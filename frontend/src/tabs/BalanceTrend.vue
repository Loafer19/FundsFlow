<template>
    <EmptyState v-if="!hasPeriodData" icon="📈" title="No balance data for this period"
        description="Nothing in the selected or previous period. Add a transaction or change the range"
        action-label="Add Transaction" @action="openAdd" />

    <div v-else class="card card-border border-base-300 bg-base-100">
        <div class="card-body">
            <TrendChart :balances="balanceTrend" />
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import TrendChart from '../components/charts/TrendChart.vue'
import EmptyState from '../components/EmptyState.vue'
import { toLocalDateStr } from '../services/formatters.js'
import { useTransactionsStore } from '../services/transactions.js'

const transactionsStore = useTransactionsStore()

const props = defineProps({
    dateRange: {
        type: Object,
        required: true,
    },
})

const openAdd = () => transactions_add_modal.showModal()

const hasPeriodData = computed(() => {
    const { currentStart, currentEnd, previousStart, previousEnd } = props.dateRange

    return (
        transactionsStore.filteredByDateRange(currentStart, currentEnd).length > 0 ||
        transactionsStore.filteredByDateRange(previousStart, previousEnd).length > 0
    )
})

const balanceTrend = computed(() => {
    const { currentStart, currentEnd, previousStart, previousEnd } = props.dateRange

    const calculateBalance = (start, end) => {
        const dailyTotals = transactionsStore.filteredByDateRange(start, end).reduce((acc, t) => {
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

    return {
        currentBalances: calculateBalance(currentStart, currentEnd),
        previousBalances: calculateBalance(previousStart, previousEnd),
    }
})
</script>
