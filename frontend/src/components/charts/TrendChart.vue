<template>
    <LineChart :data="chartData" :options="chartOptions" />
</template>

<script setup>
import { CategoryScale, Chart as ChartJS, Legend, LineElement, LinearScale, PointElement, Tooltip } from 'chart.js'
import { computed, inject } from 'vue'
import { Line as LineChart } from 'vue-chartjs'

ChartJS.register(LineElement, PointElement, CategoryScale, LinearScale, Tooltip, Legend)

const props = defineProps({
    balances: {
        type: Object,
        required: true,
    },
})

const formatDate = inject('formatDate')
const formatMoney = inject('formatMoney')

const chartData = computed(() => {
    const { currentBalances, previousBalances } = props.balances
    const currentLabels = Object.keys(currentBalances)
    const previousLabels = Object.keys(previousBalances)

    const labels = currentLabels

    let repeat = previousLabels.length - currentLabels.length

    while (repeat > 0) {
        labels.push(previousLabels[previousLabels.length - repeat])
        repeat--
    }

    return {
        labels: labels.map((date) => formatDate(date)),
        datasets: [
            {
                data: Object.values(currentBalances),
                borderColor: window.getComputedStyle(document.documentElement).getPropertyValue('--color-success'),
                tension: 0.1,
                tooltip: {
                    callbacks: {
                        label: (context) => {
                            const date = new Date(currentLabels[context.dataIndex])
                            return `${formatDate(date)}: ${formatMoney(context.raw)}`
                        },
                    },
                },
            },
            {
                data: Object.values(previousBalances),
                borderColor: window.getComputedStyle(document.documentElement).getPropertyValue('--color-error'),
                tension: 0.1,
                tooltip: {
                    callbacks: {
                        label: (context) => {
                            const date = new Date(previousLabels[context.dataIndex])
                            return `${formatDate(date)}: ${formatMoney(context.raw)}`
                        },
                    },
                },
            },
        ],
    }
})

const chartOptions = {
    responsive: true,
    scales: {
        x: {
            ticks: {
                color: window.getComputedStyle(document.documentElement).getPropertyValue('--color-base-content'),
            },
            grid: {
                color: window.getComputedStyle(document.documentElement).getPropertyValue('--color-base-200'),
            },
        },
        y: {
            ticks: {
                callback: (value) => formatMoney(value),
                color: window.getComputedStyle(document.documentElement).getPropertyValue('--color-base-content'),
            },
            grid: {
                color: window.getComputedStyle(document.documentElement).getPropertyValue('--color-base-200'),
            },
        },
    },
    plugins: {
        legend: {
            display: false,
        },
        tooltip: {
            displayColors: false,
            callbacks: {
                title: () => '',
            },
        },
    },
}
</script>

<style scoped></style>
