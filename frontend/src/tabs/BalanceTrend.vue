<template>
    <EmptyState v-if="!hasPeriodData" icon="📈" title="No balance data for this period"
        description="Nothing in the selected or previous period. Add a transaction or change the range"
        action-label="Add Transaction" @action="openAdd" />

    <template v-else>
        <div class="flex flex-wrap items-center gap-3 mb-3">
            <label class="label text-sm cursor-pointer ml-auto report-hide">
                <input v-model="compare" type="checkbox" class="toggle toggle-sm" />
                Compare with previous period
            </label>
        </div>

        <div class="grid grid-cols-2 gap-3 mb-3 max-lg:[&>:last-child:nth-child(odd)]:col-span-2"
            :class="summary.length > 4 ? 'lg:grid-cols-5' : 'lg:grid-cols-4'">
            <StatCard v-for="item in summary" :key="item.key" :icon="item.icon" :label="item.label"
                :current="item.current" :previous="item.previous" :note="item.note" :previous-note="item.previousNote"
                :hint="item.hint" :hint-placement="item.hintPlacement" :compare="compare" />
        </div>

        <div class="card card-border border-base-300 bg-base-100">
            <div class="card-body gap-3 p-3 sm:p-6">
                <div class="h-64 sm:h-96">
                    <TrendChart :balances="chartBalances" fill />
                </div>
            </div>
        </div>
    </template>
</template>

<script setup>
import { computed, inject, ref } from 'vue'
import TrendChart from '../components/charts/TrendChart.vue'
import EmptyState from '../components/EmptyState.vue'
import StatCard from '../components/StatCard.vue'
import { nowDateStr, toLocalDateStr } from '../services/formatters.js'
import { useTransactionsStore } from '../services/transactions.js'

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

// App passes the same listeners to every tab; declared so they are not extraneous attrs.
defineEmits(['jump-to-list', 'jump-to-month'])

const formatDate = inject('formatDate')

// The previous-period line is shown by default (the chart always had it); unlike Flow's bar overlay,
// the cards below always compare against the previous period, same as Flow.
const compare = ref(true)
const openAdd = () => transactions_add_modal.showModal()

const hasPeriodData = computed(() => {
    const { currentStart, currentEnd, previousStart, previousEnd } = props.dateRange

    return (
        transactionsStore.filteredByDateRangeAndTags(currentStart, currentEnd).length > 0 ||
        transactionsStore.filteredByDateRangeAndTags(previousStart, previousEnd).length > 0
    )
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
        previousBalances: calculateBalance(previousStart, previousEnd),
    }
})

const chartBalances = computed(() => ({
    currentBalances: balanceTrend.value.currentBalances,
    previousBalances: compare.value ? balanceTrend.value.previousBalances : {},
}))

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
            label: 'Avg daily change',
            current: elapsedDays ? net / elapsedDays : 0,
            previous: previous.length ? prevNet / previous.length : 0,
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
