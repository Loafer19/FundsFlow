<template>
    <div class="card card-border border-base-300 bg-base-100">
        <div class="card-body">
            <LineChart :data="chartData" :options="chartOptions" />
        </div>
    </div>
</template>

<script setup>
import { CategoryScale, Chart as ChartJS, Legend, LineElement, LinearScale, PointElement, Tooltip } from 'chart.js'
import { computed } from 'vue'
import { Line as LineChart } from 'vue-chartjs'

ChartJS.register(LineElement, PointElement, CategoryScale, LinearScale, Tooltip, Legend)

const props = defineProps({
    dateRange: {
        type: Object,
        required: true,
    },
    transactions: {
        type: Array,
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
        const dailyTotals = props.transactions
            .filter((t) => {
                const date = new Date(t.at)
                return date >= start && date <= endDate
            })
            .reduce((acc, t) => {
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

const chartData = computed(() => {
    const { currentBalances, previousBalances } = balanceTrend.value
    const currentLabels = Object.keys(currentBalances)
    const previousLabels = Object.keys(previousBalances)

    return {
        labels: currentLabels.length >= previousLabels.length ? currentLabels : previousLabels,
        datasets: [
            {
                label: 'Current',
                data: Object.values(currentBalances),
                borderColor: 'oklch(76% 0.177 163.223)',
                tension: 0.1,
            },
            {
                label: 'Previous',
                data: Object.values(previousBalances),
                borderColor: 'oklch(70% 0.191 22.216)',
                tension: 0.1,
            },
        ],
    }
})

const chartOptions = {
    responsive: true,
    plugins: {
        legend: {
            display: false,
        },
    },
}
</script>

<style scoped></style>
