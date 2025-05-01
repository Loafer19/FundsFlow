<template>
    <div class="card card-border border-base-300 bg-base-100">
        <div class="card-body">
            <TrendChart :balances="balanceTrend" />
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import TrendChart from '../components/charts/TrendChart.vue'
import { useTransactionsStore } from '../services/transactions.js'

const transactionsStore = useTransactionsStore()

const props = defineProps({
    dateRange: {
        type: Object,
        required: true,
    },
})

const balanceTrend = computed(() => {
    const { currentStart, currentEnd, previousStart, previousEnd } = props.dateRange

    const toDateStr = (date) => date.toISOString().split('T')[0]

    const calculateBalance = (start, end) => {
        const endDate = new Date(end)
        endDate.setDate(endDate.getDate() + 1)

        // Step 1: Filter and group transactions by date
        const dailyTotals = transactionsStore.filteredByDateRange(start, end).reduce((acc, t) => {
            const dateStr = t.at.split('T')[0]
            acc[dateStr] = (acc[dateStr] || 0) + t.amount
            return acc
        }, {})

        // Step 2: Calculate running balance for each day
        let balance = 0
        const balances = {}
        const currentDate = new Date(start)
        currentDate.setDate(currentDate.getDate() + 1)

        while (currentDate <= endDate) {
            const dateStr = toDateStr(currentDate)
            balance += dailyTotals[dateStr] || 0
            balances[dateStr] = balance
            currentDate.setDate(currentDate.getDate() + 1)
        }

        return balances
    }

    const currentBalances = calculateBalance(currentStart, currentEnd)
    const previousBalances = calculateBalance(previousStart, previousEnd)

    return { currentBalances, previousBalances }
})
</script>

<style scoped></style>
