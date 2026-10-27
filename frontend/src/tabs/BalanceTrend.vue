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

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
            <StatCard v-for="item in summary" :key="item.key" :icon="item.key" :label="item.label" :current="item.current"
                :previous="item.previous" :compare="compare" />
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
import { nowDateStr, toLocalDateStr } from '../services/formatters.js'
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

const summary = computed(() => {
    const current = Object.values(balanceTrend.value.currentBalances)
    const previous = Object.values(balanceTrend.value.previousBalances)

    // A period that is still running only averages over the days up to today.
    const todayIdx = Object.keys(balanceTrend.value.currentBalances).indexOf(nowDateStr())
    const elapsedDays = todayIdx === -1 ? current.length : todayIdx + 1
    const net = current.at(-1) ?? 0
    const prevNet = previous.at(-1) ?? 0

    return [
        { key: 'net', label: 'Net', current: net, previous: prevNet },
        {
            key: 'avg',
            label: 'Avg daily change',
            current: elapsedDays ? net / elapsedDays : 0,
            previous: previous.length ? prevNet / previous.length : 0,
        },
    ]
})
</script>
