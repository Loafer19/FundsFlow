<template>
    <div class="card card-border border-base-300 bg-base-100">
        <div class="card-body">
            <BarChart :balances="moneyFlow" />
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import BarChart from '../components/charts/BarChart.vue'
import { useTransactionsStore } from '../services/transactions.js'

const transactionsStore = useTransactionsStore()

const props = defineProps({
    dateRange: {
        type: Object,
        required: true,
    },
})

const moneyFlow = computed(() => {
    const { currentStart, currentEnd } = props.dateRange
    const endDate = new Date(currentEnd)
    endDate.setDate(endDate.getDate() + 1)

    const toDateStr = (date) => date.toISOString().split('T')[0]

    // Step 1: Filter and group transactions by date
    const dailyTotals = transactionsStore
        .filteredByDateRange(currentStart, currentEnd)
        .filter((t) => {
            const date = new Date(t.at)
            return date >= currentStart && date <= endDate
        })
        .reduce((acc, t) => {
            const dateStr = t.at.split('T')[0]
            acc[dateStr] = acc[dateStr] || { positive: 0, negative: 0 }
            t.amount >= 0 ? (acc[dateStr].positive += t.amount) : (acc[dateStr].negative += t.amount)
            return acc
        }, {})

    // Step 2: Fill in all dates in range, even if no transactions
    const balances = {}
    const currentDate = new Date(currentStart)
    currentDate.setDate(currentDate.getDate() + 1)

    while (currentDate <= endDate) {
        const dateStr = toDateStr(currentDate)
        balances[dateStr] = dailyTotals[dateStr] || { positive: 0, negative: 0 }
        currentDate.setDate(currentDate.getDate() + 1)
    }

    return balances
})
</script>

<style scoped></style>
