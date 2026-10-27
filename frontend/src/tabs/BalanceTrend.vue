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

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-3">
            <StatCard v-for="item in summary" :key="item.key" :label="item.label" :current="item.current"
                :previous="item.previous" :note="item.note" :previous-note="item.previousNote" />
        </div>

        <div class="card card-border border-base-300 bg-base-100">
            <div class="card-body">
                <TrendChart :balances="chartBalances" />
            </div>
        </div>
    </template>
</template>

<script setup>
import { computed, ref } from 'vue'
import TrendChart from '../components/charts/TrendChart.vue'
import EmptyState from '../components/EmptyState.vue'
import StatCard from '../components/StatCard.vue'
import { formatDate, nowDateStr, parseLocalDate, toLocalDateStr } from '../services/formatters.js'
import { useTransactionsStore } from '../services/transactions.js'

const transactionsStore = useTransactionsStore()

const props = defineProps({
    dateRange: {
        type: Object,
        required: true,
    },
})

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

const shortDate = (dateStr) => formatDate(parseLocalDate(dateStr))

// Max / min point of a series (first day it was reached).
const extreme = (entries, pick) =>
    entries.reduce((best, entry) => (pick(entry[1], best[1]) ? entry : best), entries[0] ?? ['', 0])

const summary = computed(() => {
    const current = Object.entries(balanceTrend.value.currentBalances)
    const previous = Object.entries(balanceTrend.value.previousBalances)

    // A period that is still running only counts the days up to today, so "to date" is compared with the
    // same day of the previous period (same x position on the chart) and the daily average isn't diluted.
    const today = nowDateStr()
    const todayIdx = current.findIndex(([date]) => date === today)
    const inProgress = todayIdx !== -1 && todayIdx < current.length - 1
    const elapsed = inProgress ? current.slice(0, todayIdx + 1) : current
    const prevIdx = inProgress ? Math.min(todayIdx, previous.length - 1) : previous.length - 1

    const curEnd = elapsed.at(-1) ?? ['', 0]
    const prevEnd = previous[prevIdx] ?? ['', 0]
    const prevClose = previous.at(-1)?.[1] ?? 0
    const curPeak = extreme(elapsed, (a, b) => a > b)
    const prevPeak = extreme(previous, (a, b) => a > b)
    const curLow = extreme(elapsed, (a, b) => a < b)
    const prevLow = extreme(previous, (a, b) => a < b)

    const withDate = ([date]) => (date ? shortDate(date) : '')

    return [
        {
            key: 'net',
            label: inProgress ? 'Net to date' : 'Period net',
            current: curEnd[1],
            previous: prevEnd[1],
            note: inProgress ? `as of ${withDate(curEnd)}` : '',
            previousNote: inProgress ? withDate(prevEnd) : '',
        },
        {
            key: 'peak',
            label: 'Peak',
            current: curPeak[1],
            previous: prevPeak[1],
            note: withDate(curPeak),
            previousNote: withDate(prevPeak),
        },
        {
            key: 'low',
            label: 'Low',
            current: curLow[1],
            previous: prevLow[1],
            note: withDate(curLow),
            previousNote: withDate(prevLow),
        },
        {
            key: 'avg',
            label: 'Avg daily change',
            current: elapsed.length ? curEnd[1] / elapsed.length : 0,
            previous: previous.length ? prevClose / previous.length : 0,
            note: inProgress ? `over ${elapsed.length} days` : '',
        },
    ]
})
</script>
