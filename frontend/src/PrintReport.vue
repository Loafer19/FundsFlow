<template>
    <ReportDocument v-if="report" :sections="report.sections" :date-range="dateRange"
        :date-selection-type="report.period_type" :tag-filter-label="tagFilterLabel" />
    <p v-else-if="failed" class="report-print-error">{{ failed }}</p>
</template>

<script setup>
// Print mode (/?print=report#t=<one-time token>): opened by Gotenberg's Chromium to render the
// report PDF from the real ReportDocument. The token is kept in memory only (never localStorage),
// nothing is written to localStorage, and window.__reportReady flips once every chart has painted.
import { Chart } from 'chart.js'
import { computed, nextTick, onMounted, shallowRef } from 'vue'
import ReportDocument from './components/ReportDocument.vue'
import { useAuthStore } from './services/auth.js'
import { useBudgetsStore } from './services/budgets.js'
import { parseLocalDate } from './services/formatters.js'
import { useRecurringTransactionsStore } from './services/recurringTransactions.js'
import { waitForCharts } from './services/report.js'
import settings, { normalizeTimezone } from './services/settings.js'
import { useTagsStore } from './services/tags.js'
import { useTransactionsStore } from './services/transactions.js'

const report = shallowRef(null)
const failed = shallowRef('')
const tagsStore = useTagsStore()
const transactionsStore = useTransactionsStore()

const dayEnd = (dateStr) => {
    const date = parseLocalDate(dateStr)
    date.setHours(23, 59, 59, 999)
    return date
}

const dateRange = computed(() => ({
    currentStart: parseLocalDate(report.value.from),
    currentEnd: dayEnd(report.value.to),
    previousStart: parseLocalDate(report.value.previous_from),
    previousEnd: dayEnd(report.value.previous_to),
}))

const tagFilterLabel = computed(() => {
    const parts = []
    if (transactionsStore.filterUntagged) parts.push('🏷️ Untagged')
    for (const id of transactionsStore.selectedTagIds) {
        const tag = tagsStore.tags.find((t) => t.id === id)
        parts.push(tag ? `${tag.emoji ? `${tag.emoji} ` : ''}${tag.title}` : `#${id}`)
    }
    return parts.length ? parts.join(', ') : 'All tags'
})

// Formatting preferences for this render only (applyUserPreferences would persist them to
// localStorage, which a shared headless Chromium profile must not keep between users).
const applyPreferences = (preferences) => {
    if (!preferences || typeof preferences !== 'object') return
    if (preferences.dateFormat) settings.dateFormat = preferences.dateFormat
    if (preferences.moneyFormat) settings.moneyFormat = preferences.moneyFormat
    if (typeof preferences.decimals === 'boolean') settings.decimals = preferences.decimals
    if (preferences.timezone) settings.timezone = normalizeTimezone(preferences.timezone)
}

const takeToken = () => {
    const token = new URLSearchParams(window.location.hash.slice(1)).get('t') || ''
    // Drop the token from the address bar / history right away.
    window.history.replaceState(null, '', window.location.pathname + window.location.search)
    return token
}

const fail = (message) => {
    failed.value = message
    // An uncaught exception makes Gotenberg (failOnConsoleExceptions) reject the render fast,
    // so the backend falls back to the server HTML report instead of printing this message.
    setTimeout(() => {
        throw new Error(`Report print failed: ${message}`)
    })
    setTimeout(() => {
        window.__reportReady = true
    }, 100)
}

onMounted(async () => {
    window.__reportReady = false
    document.documentElement.classList.add('printing-report', 'report-print-page')
    // Static output: no animation frames to wait for.
    Chart.defaults.animation = false

    const token = takeToken()
    if (!token) {
        fail('missing token')
        return
    }

    let payload
    try {
        const response = await fetch(`${import.meta.env.VITE_API_URL}/report/print-data`, {
            headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
            cache: 'no-store',
        })
        if (!response.ok) throw new Error(`HTTP ${response.status}`)
        payload = await response.json()
    } catch (error) {
        fail(`could not load the report data (${error?.message || error})`)
        return
    }

    const { user, report: params, data } = payload
    document.documentElement.setAttribute('data-theme', params.theme || 'bumblebee')
    applyPreferences(user?.preferences)
    useAuthStore().user = { name: user?.name, email: user?.email }
    tagsStore.tags = data?.tags ?? []
    transactionsStore.transactions = data?.transactions ?? []
    useBudgetsStore().budgets = data?.budgets ?? []
    useRecurringTransactionsStore().rules = data?.recurring_transactions ?? []
    transactionsStore.selectedTagIds = params.tag_ids ?? []
    transactionsStore.filterUntagged = Boolean(params.untagged)
    report.value = params

    await nextTick()
    // Charts measured their container while the page was still settling; size them to the final layout.
    for (const chart of Object.values(Chart.instances)) chart.resize()
    await waitForCharts()
    window.__reportReady = true
})
</script>
