<template>
    <dialog
        id="type_analytics_modal"
        ref="dialogRef"
        class="modal"
        aria-labelledby="type_analytics_title"
        @close="onClose"
    >
        <div
            class="modal-box bg-base-100 w-full h-dvh max-h-dvh sm:h-auto sm:max-h-[92dvh] sm:w-[94vw] max-w-6xl overflow-y-auto p-4 sm:p-5 rounded-none sm:rounded-box"
        >
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between mb-4">
                <div class="min-w-0 flex items-start gap-3">
                    <span
                        class="size-11 rounded-field grid place-items-center text-2xl shrink-0"
                        :style="emojiBadgeStyle"
                        aria-hidden="true"
                    >{{ payload?.type?.emoji || '🏷️' }}</span>
                    <div class="min-w-0 flex-1">
                        <h2 id="type_analytics_title" class="card-title text-lg sm:text-xl mb-0 gap-2 flex-wrap">
                            {{ payload?.type?.name || 'Type analytics' }}
                            <span v-if="payload?.period" class="badge badge-sm font-normal">{{ payload.period.label }}</span>
                            <span v-if="payload?.type?.archived" class="badge badge-ghost badge-sm">Archived</span>
                        </h2>
                        <p v-if="headerSub" class="text-sm text-base-content/60 mt-0.5">{{ headerSub }}</p>
                    </div>
                    <button
                        type="button"
                        class="btn btn-ghost btn-sm btn-square shrink-0 sm:hidden"
                        aria-label="Close"
                        @click="closeModal"
                    >
                        <X :size="18" />
                    </button>
                </div>
                <div class="flex items-center gap-1.5 shrink-0 flex-wrap">
                    <label class="sr-only" for="type_analytics_period">Period</label>
                    <select
                        id="type_analytics_period"
                        class="select select-sm w-auto max-w-[10rem] bg-base-100"
                        :value="periodChoice"
                        @change="onPeriod"
                    >
                        <option v-for="preset in periodPresets" :key="preset.id" :value="preset.id">{{ preset.label }}</option>
                    </select>
                    <div class="join" role="tablist" aria-label="Type analytics">
                        <button
                            type="button"
                            role="tab"
                            class="btn btn-sm join-item"
                            :class="tab === 'overview' ? 'btn-primary' : 'border-base-300'"
                            :aria-selected="tab === 'overview'"
                            @click="tab = 'overview'"
                        >
                            Overview
                        </button>
                        <button
                            type="button"
                            role="tab"
                            class="btn btn-sm join-item"
                            :class="tab === 'breakdown' ? 'btn-primary' : 'border-base-300'"
                            :aria-selected="tab === 'breakdown'"
                            @click="tab = 'breakdown'"
                        >
                            Breakdown
                        </button>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm" @click="closeModal">
                        <List :size="16" />
                        List
                    </button>
                    <button
                        type="button"
                        class="btn btn-ghost btn-sm btn-square hidden sm:inline-flex"
                        aria-label="Close"
                        @click="closeModal"
                    >
                        <X :size="18" />
                    </button>
                </div>
            </div>

            <div v-if="loading" class="flex justify-center py-16">
                <span class="loading loading-spinner loading-md text-primary"></span>
            </div>
            <p v-else-if="error" class="text-sm text-error py-8 text-center">{{ error }}</p>
            <template v-else-if="payload">
                <div v-if="tab === 'overview'">
                    <div
                        v-if="payload.count === 0"
                        class="card card-border border-base-300 bg-base-200/40 shadow min-w-0 p-6 text-sm text-base-content/60 text-center"
                    >
                        No {{ payload.type.name }} transactions in this period.
                    </div>
                    <template v-else>
                        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
                            <div class="card card-border border-base-300 bg-base-100 shadow min-w-0">
                                <div class="card-body p-4 gap-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="shrink-0 rounded-lg p-1.5 bg-primary/15 text-primary">
                                            <Hash :size="16" />
                                        </span>
                                        <div class="text-xs uppercase tracking-wide text-base-content/60 truncate">Count</div>
                                    </div>
                                    <div class="text-lg font-semibold tabular-nums">{{ payload.count }}</div>
                                    <div class="flex flex-wrap items-center gap-2 text-xs">
                                        <span class="text-base-content/60">vs {{ payload.previous.count }} in {{ previousName }}</span>
                                        <span class="badge badge-sm tabular-nums" :class="countBadgeClass">{{ countBadgeText }}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="card card-border border-base-300 bg-base-100 shadow min-w-0">
                                <div class="card-body p-4 gap-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="shrink-0 rounded-lg p-1.5"
                                            :class="isExpense ? 'bg-error/15 text-error' : 'bg-success/15'"
                                            :style="!isExpense ? { color: INCOME } : undefined"
                                        >
                                            <Wallet :size="16" />
                                        </span>
                                        <div class="text-xs uppercase tracking-wide text-base-content/60 truncate">Total</div>
                                    </div>
                                    <div class="text-lg font-semibold tabular-nums" :style="{ color: moneyColor(payload.total) }">
                                        {{ signedMoney(payload.total) }}
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2 text-xs">
                                        <span class="text-base-content/60">vs {{ signedMoney(payload.previous.total) }}</span>
                                        <span class="badge badge-sm tabular-nums" :class="totalBadgeClass">{{ totalBadgeText }}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="card card-border border-base-300 bg-base-100 shadow min-w-0">
                                <div class="card-body p-4 gap-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="shrink-0 rounded-lg p-1.5 bg-secondary/20 text-secondary-content">
                                            <Gauge :size="16" />
                                        </span>
                                        <div class="text-xs uppercase tracking-wide text-base-content/60 truncate">Average</div>
                                    </div>
                                    <div class="text-lg font-semibold tabular-nums" :style="{ color: moneyColor(payload.avg) }">
                                        {{ signedMoney(payload.avg) }}
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2 text-xs">
                                        <span class="text-base-content/60">vs {{ signedMoney(payload.previous.avg) }}</span>
                                        <span class="badge badge-sm tabular-nums" :class="avgBadgeClass">{{ avgBadgeText }}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="card card-border border-base-300 bg-base-100 shadow min-w-0">
                                <div class="card-body p-4 gap-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="shrink-0 rounded-lg p-1.5 bg-warning/20 text-warning">
                                            <Activity :size="16" />
                                        </span>
                                        <div class="text-xs uppercase tracking-wide text-base-content/60 truncate">
                                            vs {{ previousName }}
                                        </div>
                                    </div>
                                    <div class="text-lg font-semibold tabular-nums" :style="{ color: headlineColor }">
                                        {{ headline }}
                                    </div>
                                    <div class="text-xs text-base-content/60">{{ headlineHint }}</div>
                                </div>
                            </div>
                        </div>

                        <RatingsCard v-if="payload.ratings?.rows?.length" :ratings="payload.ratings" class="mb-3" />

                        <div v-if="payload.top_values.length" class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
                            <div
                                v-for="group in topValueGroups"
                                :key="group.key"
                                class="card card-border border-base-300 bg-base-100 shadow min-w-0"
                            >
                                <div class="card-body p-4 gap-3">
                                    <h3 class="card-title text-base gap-2">
                                        <component :is="fieldIcon(group)" :size="16" class="text-base-content/60 shrink-0" />
                                        Top values · {{ group.label }}
                                    </h3>
                                    <p v-if="!group.rows.length" class="text-sm text-base-content/50">No values in this period.</p>
                                    <ul v-else class="flex flex-col gap-2.5 text-sm">
                                        <li v-for="(row, index) in group.rows" :key="String(row.value) + row.label">
                                            <div class="flex items-center justify-between gap-2 mb-1">
                                                <span
                                                    class="truncate font-medium"
                                                    :class="row.count === 0 || row.label === 'Other' ? 'text-base-content/70' : ''"
                                                >{{ row.label }}</span>
                                                <span
                                                    class="shrink-0 tabular-nums font-medium"
                                                    :class="row.count === 0 ? 'text-base-content/50' : ''"
                                                    :style="row.count === 0 ? undefined : { color: moneyColor(row.total) }"
                                                >{{ row.count === 0 && !row.total ? '—' : signedMoney(row.total) }}</span>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <div class="h-2 flex-1 rounded-full bg-base-200 overflow-hidden">
                                                    <div
                                                        class="h-full rounded-full"
                                                        :style="{ width: Math.min(100, row.share) + '%', backgroundColor: colorFor(row.label, index) }"
                                                    ></div>
                                                </div>
                                                <span
                                                    class="text-xs tabular-nums w-16 text-right"
                                                    :class="row.count === 0 ? 'text-base-content/50' : 'text-base-content/60'"
                                                >{{ row.count }} · {{ formatPct(row.share) }}</span>
                                            </div>
                                        </li>
                                    </ul>
                                    <p class="text-xs text-base-content/50">{{ fieldHint(group) }}</p>
                                </div>
                            </div>
                        </div>
                        <p v-else class="text-sm text-base-content/50 mb-4">
                            This type has no text, select or yes/no fields to summarise.
                        </p>

                        <div v-if="trendCards.length" class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div
                                v-for="trend in trendCards"
                                :key="trend.key + trend.metric"
                                class="card card-border border-base-300 bg-base-100 shadow min-w-0"
                            >
                                <div class="card-body p-4 gap-2">
                                    <div class="flex items-start justify-between gap-2">
                                        <h3 class="card-title text-base">{{ trend.title }}</h3>
                                        <span class="text-xs text-base-content/50 shrink-0">{{ trend.caption }}</span>
                                    </div>
                                    <div class="h-40 sm:h-44">
                                        <TypeSeriesChart
                                            :months="trend.months"
                                            :metric="trend.metric"
                                            :kind="trend.kind"
                                            :color="typeColor"
                                            :label="trend.title"
                                            :unit="trend.unit"
                                            fill
                                        />
                                    </div>
                                    <div class="flex justify-between text-xs text-base-content/60 tabular-nums">
                                        <span>{{ trend.footLeft }}</span>
                                        <span class="font-medium text-base-content">{{ trend.footRight }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <p v-else class="text-sm text-base-content/50">This type has no number fields to chart.</p>
                    </template>
                </div>

                <div v-else>
                    <div
                        v-if="!payload.groupable.length"
                        class="card card-border border-base-300 bg-base-200/40 shadow p-6 text-sm text-base-content/60 text-center"
                    >
                        Nothing to split by — add a text, select or yes/no field to this type.
                    </div>
                    <template v-else>
                        <div class="flex flex-wrap items-center gap-2 mb-4">
                            <span class="text-xs uppercase tracking-wide text-base-content/60">Split by</span>
                            <div class="join">
                                <button
                                    v-for="field in groupableFields"
                                    :key="field.key"
                                    type="button"
                                    class="btn btn-sm join-item"
                                    :class="groupKey === field.key ? 'btn-primary' : 'border-base-300'"
                                    @click="selectGroup(field.key)"
                                >
                                    {{ field.label }}
                                </button>
                            </div>
                            <span v-if="groupMeta" class="text-xs text-base-content/50 sm:ml-auto">{{ groupHint }}</span>
                        </div>

                        <div
                            v-if="payload.count === 0"
                            class="card card-border border-base-300 bg-base-200/40 shadow p-6 text-sm text-base-content/60 text-center"
                        >
                            No {{ payload.type.name }} transactions in this period.
                        </div>
                        <template v-else-if="payload.breakdown">
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 mb-3">
                                <div class="card card-border border-base-300 bg-base-100 shadow min-w-0">
                                    <div class="card-body p-4 gap-3">
                                        <h3 class="card-title text-base">{{ pieTitle }}</h3>
                                        <div class="flex flex-col sm:flex-row items-center gap-4">
                                            <div class="h-44 w-44 shrink-0">
                                                <TypeDonutChart :slices="pieSlices" />
                                            </div>
                                            <ul class="flex flex-col gap-1.5 text-sm min-w-0 flex-1 w-full">
                                                <li
                                                    v-for="slice in pieSlices"
                                                    :key="slice.label"
                                                    class="flex items-center gap-2"
                                                >
                                                    <span
                                                        class="size-2.5 rounded-full shrink-0"
                                                        :style="{ backgroundColor: slice.color }"
                                                    ></span>
                                                    <span class="flex-1 truncate">{{ slice.label }}</span>
                                                    <span
                                                        class="tabular-nums font-medium"
                                                        :style="{ color: moneyColor(slice.total) }"
                                                    >{{ signedMoney(slice.total) }}</span>
                                                    <span class="text-xs text-base-content/60 w-10 text-right tabular-nums">{{ formatPct(slice.share) }}</span>
                                                </li>
                                            </ul>
                                        </div>
                                        <p v-if="otherNote" class="text-xs text-base-content/50">{{ otherNote }}</p>
                                    </div>
                                </div>
                                <div class="card card-border border-base-300 bg-base-100 shadow min-w-0">
                                    <div class="card-body p-4 gap-2">
                                        <div class="flex items-start justify-between gap-2">
                                            <h3 class="card-title text-base">Stacked by month</h3>
                                            <span class="text-xs text-base-content/50">{{ stackRange }}</span>
                                        </div>
                                        <div class="h-52 sm:h-56">
                                            <TypeStackedBar :months="payload.breakdown.months" :series="stackSeries" fill />
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="card card-border border-base-300 bg-base-100 shadow overflow-hidden">
                                <div class="overflow-x-auto">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>{{ payload.breakdown.label }}</th>
                                                <th class="text-right">Transactions</th>
                                                <th class="text-right">Spend</th>
                                                <th class="text-right">Share</th>
                                            </tr>
                                        </thead>
                                        <tbody class="tabular-nums">
                                            <tr v-for="row in tableRows" :key="row.label" :class="row.label === 'Other' && 'text-base-content/70'">
                                                <td class="font-medium">
                                                    {{ row.label }}
                                                    <span v-if="row.othersText" class="text-xs font-normal text-base-content/50">{{ row.othersText }}</span>
                                                </td>
                                                <td class="text-right">{{ row.count }}</td>
                                                <td class="text-right font-medium" :style="{ color: moneyColor(row.total) }">{{ signedMoney(row.total) }}</td>
                                                <td class="text-right">{{ formatPct(row.share, 1) }}</td>
                                            </tr>
                                            <tr class="font-semibold">
                                                <td>Total</td>
                                                <td class="text-right">{{ tableTotal.count }}</td>
                                                <td class="text-right" :style="{ color: moneyColor(tableTotal.total) }">{{ signedMoney(tableTotal.total) }}</td>
                                                <td class="text-right">100%</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </template>
                    </template>
                </div>
            </template>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button>close</button>
        </form>
    </dialog>
</template>

<script setup>
import { Activity, Droplet, Gauge, Hash, List, MapPin, ToggleLeft, Type, Wallet, X } from '@lucide/vue'
import { computed, inject, ref, watch } from 'vue'
import RatingsCard from '../components/analytics/RatingsCard.vue'
import TypeDonutChart from '../components/charts/TypeDonutChart.vue'
import TypeSeriesChart from '../components/charts/TypeSeriesChart.vue'
import TypeStackedBar from '../components/charts/TypeStackedBar.vue'
import { apiErrorMessage } from '../services/formatters.js'
import { showModal } from '../services/modal.js'
import { useTransactionTypesStore } from '../services/transactionTypes.js'
import { monthRangeLabel, monthShort, PERIOD_PRESETS } from '../services/typeChartFormat.js'

const INCOME = '#2e9a4c'
const PALETTE = ['#3b82f6', '#10b981', '#8b5cf6', '#f97316', '#06b6d4', '#ef4444', '#64748b']
const OTHER = '#94a3b8'

const typesStore = useTransactionTypesStore()
const formatMoney = inject('formatMoney')
const dateRange = inject('insightDateRange', null)

const dialogRef = ref(null)
const loading = ref(false)
const error = ref('')
const payload = ref(null)
const tab = ref('overview')
const groupKey = ref(null)
const periodChoice = ref('view')
const periodPresets = PERIOD_PRESETS
let requestId = 0

const typeColor = computed(() => payload.value?.type?.color || '#f59e0b')

const emojiBadgeStyle = computed(() => ({
    background: `color-mix(in oklab, ${typeColor.value} 22%, var(--color-base-100))`,
}))

const openedFrom = computed(() =>
    typesStore.analyticsSource === 'manage' ? 'Opened from Manage Types' : 'Opened from the Type column',
)

const periodDates = computed(() => {
    const period = payload.value?.period
    if (!period) return ''
    const dmy = (iso) => {
        const [year, month, day] = String(iso).slice(0, 10).split('-')
        return `${day}.${month}.${year}`
    }
    return `${dmy(period.from)} – ${dmy(period.to)}`
})

const previousName = computed(() => {
    const label = payload.value?.period?.previous_label || 'previous period'
    return label.replace(/\s+\d{4}$/, '')
})

const headerSub = computed(() => {
    if (!payload.value) return ''
    if (tab.value === 'breakdown') {
        const n = payload.value.breakdown?.top_n ?? 5
        return `Spend split by a select or text field · top ${n} + Other`
    }
    if (!payload.value.period) return ''
    return `${openedFrom.value} · ${periodDates.value}`
})

const isExpense = computed(() => (payload.value?.total ?? 0) <= 0)

const moneyColor = (amount) => (amount > 0 ? INCOME : amount < 0 ? 'var(--color-error)' : undefined)

const signedMoney = (amount) => {
    const text = formatMoney(Math.abs(amount))
    if (amount < 0) return `−${text}`
    if (amount > 0) return text
    return formatMoney(0)
}

const formatPct = (value, digits = 0) => {
    if (value == null || Number.isNaN(Number(value))) return '—'
    const n = Number(value)
    const rounded = digits === 0 ? Math.round(n) : Math.round(n * 10 ** digits) / 10 ** digits
    const text =
        digits === 0 || Number.isInteger(rounded)
            ? String(Math.round(rounded))
            : rounded.toFixed(digits).replace('.', ',')
    return `${text}%`
}

const pctLabel = (value) => {
    if (value == null) return '—'
    const n = Math.round(Number(value) * 10) / 10
    const text = Number.isInteger(n) ? String(n) : n.toFixed(1).replace('.', ',')
    const sign = n > 0 ? '+' : ''
    return `${sign}${text}%`
}

const spendDeltaPct = computed(() => {
    const delta = payload.value?.delta
    if (!delta || delta.total_pct == null) return null
    const total = payload.value.total
    const previous = payload.value.previous?.total ?? 0
    if (total <= 0 && previous <= 0) return -delta.total_pct
    return delta.total_pct
})

const spendDeltaAmount = computed(() => {
    const delta = payload.value?.delta?.total
    if (delta == null) return 0
    return isExpense.value ? -delta : delta
})

const countBadgeText = computed(() => {
    const delta = payload.value?.delta
    if (!delta) return '—'
    const count = delta.count
    const sign = count > 0 ? '+' : ''
    const pct = delta.count_pct == null ? '' : ` (${pctLabel(delta.count_pct)})`
    return `${sign}${count}${pct}`
})

const countBadgeClass = computed(() => {
    const count = payload.value?.delta?.count ?? 0
    if (count > 0) return 'badge-warning'
    if (count < 0) return 'badge-ghost'
    return 'badge-ghost'
})

const totalBadgeText = computed(() => {
    const pct = spendDeltaPct.value
    if (pct == null) return '—'
    const amount = spendDeltaAmount.value
    const sign = amount > 0 ? '+' : amount < 0 ? '−' : ''
    return `${sign}${formatMoney(Math.abs(amount))} (${pctLabel(pct)})`
})

const totalBadgeClass = computed(() => {
    const pct = spendDeltaPct.value
    if (pct == null || pct === 0) return 'badge-ghost'
    if (isExpense.value) return pct > 0 ? 'badge-error' : 'badge-success'
    return pct > 0 ? 'badge-success' : 'badge-error'
})

const avgSpendDelta = computed(() => {
    const delta = payload.value?.delta
    if (!delta || delta.avg_pct == null) return null
    const avg = payload.value.avg
    const previous = payload.value.previous?.avg ?? 0
    // Expenses: a less-negative (higher) avg is a lower ticket.
    if (avg <= 0 && previous <= 0) return -delta.avg_pct
    return delta.avg_pct
})

const avgBadgeText = computed(() => {
    const delta = payload.value?.delta
    if (!delta || delta.avg_pct == null) return '—'
    const pct = avgSpendDelta.value
    const amount = isExpense.value ? -delta.avg : delta.avg
    const sign = amount > 0 ? '+' : amount < 0 ? '−' : ''
    return `${sign}${formatMoney(Math.abs(amount))} (${pctLabel(pct)})`
})

const avgBadgeClass = computed(() => {
    const pct = avgSpendDelta.value
    if (pct == null || pct === 0) return 'badge-ghost'
    // Lower average ticket (expense down) is success; higher ticket is error.
    if (isExpense.value) return pct < 0 ? 'badge-success' : 'badge-warning'
    return pct > 0 ? 'badge-success' : 'badge-warning'
})

const headline = computed(() => {
    const pct = spendDeltaPct.value
    if (pct == null) return 'No previous period'
    const abs = formatPct(Math.abs(pct))
    if (pct === 0) return 'Unchanged'
    if (isExpense.value) return pct > 0 ? `Spend up ${abs}` : `Spend down ${abs}`
    return pct > 0 ? `Income up ${abs}` : `Income down ${abs}`
})

const headlineColor = computed(() => {
    const pct = spendDeltaPct.value
    if (pct == null || pct === 0) return undefined
    if (isExpense.value) return pct > 0 ? 'var(--color-error)' : INCOME
    return pct > 0 ? INCOME : 'var(--color-error)'
})

const headlineHint = computed(() => {
    const delta = payload.value?.delta
    if (!delta) return ''
    const n = delta.count
    const fills = Math.abs(n) === 1 ? 'fill' : 'fills'
    const countBit = n > 0 ? `More ${fills} (+${n})` : n < 0 ? `Fewer ${fills} (${n})` : 'The same number of fills'
    const avgPct = avgSpendDelta.value
    if (avgPct == null || Math.abs(avgPct) < 0.5) return `${countBit}, at a similar average ticket`
    if (isExpense.value) {
        return avgPct < 0 ? `${countBit} at a slightly lower average ticket` : `${countBit} at a higher average ticket`
    }
    return avgPct > 0 ? `${countBit} at a higher average ticket` : `${countBit} at a slightly lower average ticket`
})

const isRateField = (trend) => {
    const unit = (trend.unit || '').toLowerCase()
    const label = (trend.label || '').toLowerCase()
    return unit === '₴' || unit === 'uah' || unit === '$' || /price|per\b/.test(label)
}

const formatQty = (value, unit) => {
    if (unit === '₴' || unit === 'UAH') {
        const text = signedMoney(value)
        return text.includes('₴') ? text : `${text} ₴`
    }
    const rounded = Number.isInteger(value) ? String(value) : String(Math.round(value * 100) / 100).replace('.', ',')
    return unit ? `${rounded} ${unit}` : rounded
}

const trendCards = computed(() => {
    const cards = []
    for (const trend of payload.value?.trends ?? []) {
        const unit = trend.unit
        const rate = isRateField(trend) || trend.kind === 'rating'
        const metric = rate ? 'avg' : 'sum'
        const months = trend.months
        const first = months[0]
        const last = months[months.length - 1]
        cards.push({
            key: trend.key,
            metric,
            kind: rate ? 'line' : 'bar',
            title: rate
                ? `Avg ${trend.label.toLowerCase()}`
                : unit
                  ? `${trend.label} per month`
                  : `${trend.label} (sum)`,
            caption:
                rate && unit
                    ? `${unit} / ${trend.label.toLowerCase().includes('liter') ? 'l' : 'unit'}`
                    : monthRangeLabel(months),
            unit,
            months,
            footLeft: first
                ? rate
                    ? `${monthShort(first.month)} · ${formatQty(first[metric], unit)}`
                    : formatQty(first[metric], unit)
                : '',
            footRight: last ? `${monthShort(last.month)} · ${formatQty(last[metric], unit)}` : '',
        })
    }
    return cards
})

const PREFERRED_GROUP = ['station', 'store', 'format', 'service', 'merchant', 'place']

const groupRank = (key) => {
    const i = PREFERRED_GROUP.indexOf(String(key || '').toLowerCase())
    return i === -1 ? PREFERRED_GROUP.length : i
}

const topValueGroups = computed(() =>
    [...(payload.value?.top_values ?? [])].sort((a, b) => groupRank(a.key) - groupRank(b.key)),
)

const groupableFields = computed(() =>
    [...(payload.value?.groupable ?? [])].sort((a, b) => groupRank(a.key) - groupRank(b.key)),
)

const colorFor = (label, index) => {
    if (label === 'Other' || label === '—' || label === 'Not set') return OTHER
    if (index === 0) return typeColor.value
    return PALETTE[(index - 1) % PALETTE.length]
}

const fieldIcon = (group) => {
    const key = (group.key || '').toLowerCase()
    const kind = group.kind
    if (/station|store|place|merchant/.test(key)) return MapPin
    if (/fuel|diesel|petrol/.test(key)) return Droplet
    if (kind === 'boolean') return ToggleLeft
    if (kind === 'text') return Type
    return List
}

const kindLabel = (kind) => {
    if (kind === 'select') return 'select'
    if (kind === 'boolean') return 'yes/no'
    if (kind === 'text') return 'text'
    return kind || 'field'
}

const fieldHint = (group) => {
    const typeName = payload.value?.type?.name
    const kind = kindLabel(group.kind)
    if (group.kind === 'select') return `Select field · options from the ${typeName} type`
    return `${group.label} is a ${kind} field on ${typeName}`
}

const pieSlices = computed(() =>
    (payload.value?.breakdown?.period ?? [])
        .filter((slice) => slice.label !== 'Other' || slice.count || slice.total)
        .map((slice, index) => ({
            ...slice,
            color: colorFor(slice.label, index),
        })),
)

const pieTitle = computed(() => {
    const period = (payload.value?.period?.label || 'This period').replace(/\s+\d{4}$/, '')
    const field = (payload.value?.breakdown?.label || 'field').toLowerCase()
    return `${period} spend by ${field}`
})

const groupMeta = computed(
    () => payload.value?.groupable?.find((field) => field.key === groupKey.value) ?? payload.value?.breakdown,
)

const groupHint = computed(() => {
    const field = groupMeta.value
    if (!field) return ''
    return `${field.label} is a ${kindLabel(field.kind)} field on ${payload.value?.type?.name}`
})

const otherSlice = computed(() => (payload.value?.breakdown?.period ?? []).find((row) => row.label === 'Other'))

const otherNote = computed(() => {
    const row = otherSlice.value
    if (!row || !(row.count || row.total)) return ''
    const names = (row.others ?? []).filter(Boolean)
    const group = names.length ? `Other groups ${names.join(' and ')}` : 'Other groups the remaining values'
    const fills = row.count === 1 ? '1 transaction' : `${row.count} transactions`
    const shown = (payload.value?.breakdown?.period ?? []).filter((item) => item.label !== 'Other').length
    return `${group} (${fills}). Top ${shown} shown individually.`
})

const stackRange = computed(() => monthRangeLabel(payload.value?.breakdown?.months ?? []))

const stackSeries = computed(() => {
    const months = payload.value?.breakdown?.months ?? []
    const labels = []
    for (const month of months) {
        for (const slice of month.slices || []) {
            if (!labels.includes(slice.label)) labels.push(slice.label)
        }
    }
    const visible = labels.filter((label) => {
        if (label !== 'Other') return true
        return months.some((month) => (month.slices || []).some((slice) => slice.label === 'Other' && slice.total))
    })
    return visible.map((label, index) => ({ label, color: colorFor(label, index) }))
})

const tableRows = computed(() =>
    (payload.value?.breakdown?.period ?? []).map((row) => ({
        ...row,
        othersText: row.label === 'Other' && row.others?.length ? `(${row.others.join(', ')})` : '',
    })),
)

const tableTotal = computed(() => {
    const rows = payload.value?.breakdown?.period ?? []
    return {
        count: rows.reduce((sum, row) => sum + (row.count || 0), 0),
        total: rows.reduce((sum, row) => sum + (row.total || 0), 0),
    }
})

const rangeParams = () => {
    if (periodChoice.value && periodChoice.value !== 'view') return { period: periodChoice.value }
    const range = dateRange?.value
    if (!range?.currentStart || !range?.currentEnd) return { period: 'this_month' }
    const ymd = (date) => {
        const year = date.getFullYear()
        const month = String(date.getMonth() + 1).padStart(2, '0')
        const day = String(date.getDate()).padStart(2, '0')
        return `${year}-${month}-${day}`
    }
    return { from: ymd(range.currentStart), to: ymd(range.currentEnd) }
}

const load = async (id, group = null) => {
    const token = ++requestId
    loading.value = true
    error.value = ''
    try {
        const params = { ...rangeParams() }
        if (group) params.group = group
        const data = await typesStore.analytics(id, params)
        if (token !== requestId) return
        payload.value = data
        groupKey.value = data.breakdown?.key ?? data.groupable?.[0]?.key ?? null
    } catch (err) {
        if (token !== requestId) return
        error.value = apiErrorMessage(err, 'Could not load type analytics: ')
        payload.value = null
    } finally {
        if (token === requestId) loading.value = false
    }
}

const selectGroup = (key) => {
    groupKey.value = key
    if (typesStore.analyticsTypeId) load(typesStore.analyticsTypeId, key)
}

const onPeriod = (event) => {
    periodChoice.value = event.target.value
    if (typesStore.analyticsTypeId) load(typesStore.analyticsTypeId, groupKey.value)
}

const closeModal = () => {
    dialogRef.value?.close()
}

const onClose = () => {
    typesStore.closeAnalytics()
}

watch(
    () => typesStore.analyticsTypeId,
    (id) => {
        if (!id) {
            dialogRef.value?.open && dialogRef.value.close()
            return
        }
        tab.value = 'overview'
        periodChoice.value = 'view'
        payload.value = null
        showModal('type_analytics_modal')
        load(id)
    },
)
</script>

<style>
@media (max-width: 639px) {
    #type_analytics_modal {
        padding: 0 !important;
    }
    #type_analytics_modal .modal-box {
        width: 100% !important;
        max-width: none !important;
        height: 100dvh !important;
        max-height: 100dvh !important;
        margin: 0 !important;
        border-radius: 0 !important;
    }
}
</style>
