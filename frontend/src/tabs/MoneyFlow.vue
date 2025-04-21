<template>
    <div class="card card-border border-base-300 bg-base-100">
        <div class="card-body">
            <BarChart :data="chartData" :options="chartOptions" />
        </div>
    </div>
</template>

<script setup>
import { BarElement, CategoryScale, Chart as ChartJS, Legend, LinearScale, Tooltip } from 'chart.js'
import { computed } from 'vue'
import { Bar as BarChart } from 'vue-chartjs'
import { useTransactionsStore } from '../services/transactions.js'

ChartJS.register(BarElement, CategoryScale, LinearScale, Tooltip, Legend)

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

const chartData = computed(() => ({
    labels: Object.keys(moneyFlow.value),
    datasets: [
        {
            data: Object.values(moneyFlow.value).map((t) => t.positive),
            backgroundColor: 'oklch(76% 0.177 163.223)',
        },
        {
            data: Object.values(moneyFlow.value).map((t) => t.negative),
            backgroundColor: 'oklch(70% 0.191 22.216)',
        },
    ],
}))

const chartOptions = {
    responsive: true,
    scales: {
        x: {
            stacked: true,
        },
    },
    plugins: {
        legend: {
            display: false,
        },
    },
}
</script>

<style scoped></style>
