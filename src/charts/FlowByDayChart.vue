<template>
    <div class="card card-border border-base-300 bg-base-100 mb-4">
        <div class="card-body">
            <div class="flex justify-between items-center">
                <h2 class="card-title">Money Flow by Day</h2>

                <select class="select select-sm select-primary w-40" v-model="selectedRange">
                    <option value="thisWeek">This Week</option>
                    <option value="lastWeek">Last Week</option>
                    <option value="thisMonth">This Month</option>
                    <option value="lastMonth">Last Month</option>
                </select>
            </div>

            <BarChart :data="chartData" :options="chartOptions" />
        </div>
    </div>
</template>

<script setup>
import { BarElement, CategoryScale, Chart as ChartJS, Legend, LinearScale, Tooltip } from 'chart.js'
import { computed, inject, ref } from 'vue'
import { Bar as BarChart } from 'vue-chartjs'

ChartJS.register(Tooltip, BarElement, CategoryScale, LinearScale, Legend)

const props = defineProps({
    transactions: {
        type: Array,
        required: true,
    },
})

const selectedRange = ref('thisWeek')
const formatDate = inject('formatDate')

const getDateRange = () => {
    const today = new Date()
    today.setHours(0, 0, 0, 0)

    let startDate
    let endDate

    switch (selectedRange.value) {
        case 'thisWeek':
            startDate = new Date(today)
            startDate.setDate(today.getDate() - today.getDay() + 1)
            endDate = new Date(startDate)
            endDate.setDate(startDate.getDate() + 6)
            break
        case 'lastWeek':
            startDate = new Date(today)
            startDate.setDate(today.getDate() - today.getDay() - 6)
            endDate = new Date(startDate)
            endDate.setDate(startDate.getDate() + 6)
            break
        case 'thisMonth':
            startDate = new Date(today.getFullYear(), today.getMonth(), 1)
            endDate = new Date(today.getFullYear(), today.getMonth() + 1, 0)
            break
        case 'lastMonth':
            startDate = new Date(today.getFullYear(), today.getMonth() - 1, 1)
            endDate = new Date(today.getFullYear(), today.getMonth(), 0)
            break
    }

    return { startDate, endDate }
}

const transactionsFormatted = computed(() => {
    const { startDate, endDate } = getDateRange()
    endDate.setDate(endDate.getDate() + 1)

    const initialData = props.transactions
        .filter((t) => {
            const date = new Date(t.date)
            return date >= startDate && date <= endDate
        })
        .reduce((acc, t) => {
            const date = t.date.split('T')[0]
            if (!acc[date]) {
                acc[date] = { positive: 0, negative: 0 }
            }
            if (t.amount >= 0) {
                acc[date].positive += t.amount
            } else {
                acc[date].negative += t.amount
            }
            return acc
        }, {})

    const result = {}
    const currentDate = new Date(startDate)
    currentDate.setDate(currentDate.getDate() + 1)

    while (currentDate <= endDate) {
        const dateStr = currentDate.toISOString().split('T')[0]
        result[dateStr] = initialData[dateStr] || { positive: 0, negative: 0 }
        currentDate.setDate(currentDate.getDate() + 1)
    }

    return result
})

const chartData = computed(() => ({
    labels: Object.keys(transactionsFormatted.value),
    datasets: [
        {
            label: 'Income',
            data: Object.values(transactionsFormatted.value).map((t) => t.positive),
            backgroundColor: 'oklch(76% 0.177 163.223)',
            stack: 'Stack 0',
        },
        {
            label: 'Expenses',
            data: Object.values(transactionsFormatted.value).map((t) => t.negative),
            backgroundColor: 'oklch(70% 0.191 22.216)',
            stack: 'Stack 0',
        },
    ],
}))

const chartOptions = {
    responsive: true,
    scales: {
        x: {
            stacked: true,
        },
        y: {
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
