<template>
    <EmptyState v-if="!hasPeriodData" :icon="TrendingUp" title="No balance data for this period"
        description="Nothing in this period. Add a transaction or change the range."
        action-label="Add Transaction" @action="openAdd" />

    <template v-else>
        <div class="max-lg:-mx-4 max-lg:px-4 flex gap-2 overflow-x-auto mb-3 pb-1 max-lg:snap-x max-lg:snap-mandatory lg:grid lg:gap-3 lg:mx-0 lg:px-0 lg:overflow-visible"
            :class="summary.length > 4 ? 'lg:grid-cols-5' : 'lg:grid-cols-4'">
            <StatCard v-for="item in summary" :key="item.key" compact class="min-w-[10.5rem] shrink-0 max-lg:snap-start"
                :icon="item.icon" :label="item.label"
                :current="item.current" :previous="item.previous" :note="item.note" :previous-note="item.previousNote"
                :hint="item.hint" :hint-placement="item.hintPlacement" :compare="compare && hasPrevious" />
        </div>

        <div class="card card-border border-base-300 bg-base-100 min-w-0 max-md:scroll-mb-[calc(6rem+env(safe-area-inset-bottom))]">
            <div class="card-body gap-3 p-3 sm:p-6 max-md:pb-4">
                <div class="flex flex-wrap items-center gap-2 min-w-0">
                    <h2 class="card-title text-base sm:text-lg min-w-0 gap-2">
                        <span>Trend</span>
                        <span class="tooltip tooltip-bottom before:max-w-56 font-normal report-hide"
                            data-tip="Cumulative net for this period, starting at 0. This is not your account balance.">
                            <Info :size="16" class="opacity-60" />
                        </span>
                    </h2>
                    <label class="label text-sm cursor-pointer ml-auto gap-2 py-0 report-hide min-w-0">
                        <span class="text-base-content/70 truncate">Compare</span>
                        <input v-model="compare" type="checkbox" class="toggle toggle-primary toggle-sm" />
                    </label>
                </div>
                <div class="h-56 sm:h-96">
                    <TrendChart :balances="chartBalances" :bucket="chartBucket" :today="todayKey" fill
                        :clickable="!inReport" @select="openPoint" />
                </div>
            </div>
        </div>
    </template>
</template>

<script setup>
import { Info, TrendingUp } from '@lucide/vue'
import { computed, inject, ref, watch } from 'vue'
import TrendChart from '../components/charts/TrendChart.vue'
import EmptyState from '../components/EmptyState.vue'
import StatCard from '../components/StatCard.vue'
import { useAuthStore } from '../services/auth.js'
import { nowDateStr, toLocalDateStr } from '../services/formatters.js'
import { useTransactionsStore } from '../services/transactions.js'
import { patchUiState, readUiState } from '../services/uiState.js'

const transactionsStore = useTransactionsStore()

const props = defineProps({
    dateRange: {
        type: Object,
        required: true,
    },
    dateSelectionType: {
        type: String,
        default: 'month',
    },
})

const emit = defineEmits(['jump-to-list', 'jump-to-month'])

const formatDate = inject('formatDate')
const inReport = inject('inReport', false)

const authStore = useAuthStore()
const compare = ref(readUiState().compare !== false)

watch(
    () => authStore.user?.id,
    () => {
        compare.value = readUiState().compare !== false
    },
)

watch(compare, (value) => {
    if (!authStore.isAuthenticated) return
    patchUiState({ compare: value })
})

const openAdd = () => transactions_add_modal.showModal()

const hasPeriodData = computed(() => {
    const { currentStart, currentEnd } = props.dateRange
    return transactionsStore.filteredByDateRangeAndTags(currentStart, currentEnd).length > 0
})

const hasPrevious = computed(() => {
    const { previousStart, previousEnd } = props.dateRange
    return transactionsStore.filteredByDateRangeAndTags(previousStart, previousEnd).length > 0
})

// Running (cumulative) net of the period's tag-filtered transactions, one point per day, starting from 0.
const calculateBalance = (start, end) => {
    const dailyTotals = transactionsStore.filteredByDateRangeAndTags(start, end).reduce((acc, t) => {
        const dateStr = toLocalDateStr(t.at)
        acc[dateStr] = (acc[dateStr] || 0) + t.amount
        return acc
    }, {})

    let balance = 0
    const balances = {}
    const currentDate = new Date(start)
    currentDate.setHours(0, 0, 0, 0)

    const endDate = new Date(end)
    endDate.setHours(0, 0, 0, 0)

    while (currentDate <= endDate) {
        const dateStr = toLocalDateStr(currentDate)
        balance += dailyTotals[dateStr] || 0
        balances[dateStr] = balance
        currentDate.setDate(currentDate.getDate() + 1)
    }

    return balances
}

const balanceTrend = computed(() => {
    const { currentStart, currentEnd, previousStart, previousEnd } = props.dateRange

    return {
        currentBalances: calculateBalance(currentStart, currentEnd),
        previousBalances: hasPrevious.value ? calculateBalance(previousStart, previousEnd) : {},
    }
})

// Linear projection from today's net using the same average daily change as the Forecast card.
// Empty when the period is fully in the past or today is the last day (nothing left to project).
const forecastBalances = computed(() => {
    const current = balanceTrend.value.currentBalances
    const dates = Object.keys(current)
    const todayIdx = dates.indexOf(nowDateStr())
    if (todayIdx === -1 || todayIdx >= dates.length - 1) return {}

    const elapsedDays = todayIdx + 1
    const netToday = current[dates[todayIdx]]
    const avg = elapsedDays ? netToday / elapsedDays : 0
    const out = {}
    for (let i = todayIdx; i < dates.length; i++) {
        out[dates[i]] = netToday + avg * (i - todayIdx)
    }
    return out
})

// Year view: one point per month (the last daily value in that month, including a trailing null month).
const lastByMonth = (daily) => {
    const out = {}
    for (const [date, value] of Object.entries(daily)) {
        const key = `${date.slice(0, 7)}-01`
        if (!(key in out)) out[key] = null
        if (value != null) out[key] = value
    }
    return out
}

const chartBucket = computed(() => (props.dateSelectionType === 'year' ? 'month' : 'day'))

const bucket = (daily) => (chartBucket.value === 'month' ? lastByMonth(daily) : daily)

const chartBalances = computed(() => {
    const current = balanceTrend.value.currentBalances
    const forecast = forecastBalances.value
    const hasForecast = Object.keys(forecast).length > 0
    const todayIdx = Object.keys(current).indexOf(nowDateStr())

    let currentForChart = current
    if (hasForecast) {
        currentForChart = {}
        Object.keys(current).forEach((date, i) => {
            currentForChart[date] = i <= todayIdx ? current[date] : null
        })
    }

    return {
        currentBalances: bucket(currentForChart),
        previousBalances: compare.value ? bucket(balanceTrend.value.previousBalances) : {},
        forecastBalances: bucket(forecast),
    }
})

const todayKey = computed(() => {
    const today = nowDateStr()
    const key = chartBucket.value === 'month' ? `${today.slice(0, 7)}-01` : today
    return key in chartBalances.value.currentBalances ? key : ''
})

const openPoint = (date) => emit('jump-to-list', { groupBy: chartBucket.value, date })

// Lowest / highest running net and the first day it was reached (null for an empty list).
const extreme = (balances, pick) => {
    let best = null
    for (const [date, value] of balances) {
        if (best === null || pick(value, best.value)) best = { date, value }
    }
    return best
}

const summary = computed(() => {
    const currentEntries = Object.entries(balanceTrend.value.currentBalances)
    const previousEntries = Object.entries(balanceTrend.value.previousBalances)
    const current = currentEntries.map(([, value]) => value)
    const previous = previousEntries.map(([, value]) => value)

    // A period that is still running only averages over the days up to today.
    const todayIdx = Object.keys(balanceTrend.value.currentBalances).indexOf(nowDateStr())
    const running = todayIdx !== -1
    const elapsedDays = running ? todayIdx + 1 : current.length
    const elapsed = currentEntries.slice(0, elapsedDays)
    const net = current.at(-1) ?? 0
    const prevNet = previous.at(-1) ?? 0

    const low = extreme(elapsed, (a, b) => a < b)
    const peak = extreme(elapsed, (a, b) => a > b)
    const prevLow = extreme(previousEntries, (a, b) => a < b)
    const prevPeak = extreme(previousEntries, (a, b) => a > b)
    const day = (point) => (point ? formatDate(point.date) : '')

    const cards = [
        { key: 'net', icon: 'net', label: 'Net', current: net, previous: prevNet },
        {
            key: 'avg',
            icon: 'avg',
            label: 'Avg / day',
            current: elapsedDays ? net / elapsedDays : 0,
            previous: previous.length ? prevNet / previous.length : 0,
            hint: 'Average daily change of the running net',
            hintPlacement: 'tooltip-left before:max-w-52',
        },
        {
            key: 'low',
            icon: 'low',
            label: 'Low',
            current: low?.value ?? null,
            previous: prevLow?.value ?? null,
            note: day(low),
            previousNote: day(prevLow),
        },
        {
            key: 'peak',
            icon: 'peak',
            label: 'Peak',
            current: peak?.value ?? null,
            previous: prevPeak?.value ?? null,
            note: day(peak),
            previousNote: day(prevPeak),
        },
    ]

    // Projection only makes sense while the period is running: today's net plus the average daily
    // change so far for each remaining day, compared with where the previous period ended.
    // Uses the net up to today, so transactions dated later in the period are not counted twice.
    if (running) {
        const lastDate = currentEntries.at(-1)[0]
        const netToday = elapsed.at(-1)[1]
        cards.push({
            key: 'forecast',
            icon: 'forecast',
            label: 'Forecast',
            current: netToday + (netToday / elapsedDays) * (current.length - elapsedDays),
            previous: prevNet,
            note: `by ${formatDate(lastDate)}`,
            hint: 'Net at the end of the period if the average daily change so far continues',
            // Last card of the desktop row: open the hint inwards so it does not widen the page.
            hintPlacement: 'tooltip-bottom lg:tooltip-left before:max-w-52',
        })
    }

    return cards
})
</script>
