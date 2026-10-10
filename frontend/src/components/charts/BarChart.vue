<template>
    <BarChart :data="chartData" :options="chartOptions" />
</template>

<script setup>
import { BarElement, CategoryScale, Chart as ChartJS, Legend, LinearScale, Tooltip } from 'chart.js'
import { computed, inject } from 'vue'
import { Bar as BarChart } from 'vue-chartjs'

ChartJS.register(BarElement, CategoryScale, LinearScale, Tooltip, Legend)

const props = defineProps({
    balances: {
        type: Object,
        required: true,
    },
    // Optional previous-period buckets ({ positive, negative } objects), aligned by index.
    previous: {
        type: Array,
        default: null,
    },
})

const formatDate = inject('formatDate')
const formatMoney = inject('formatMoney')

const cssVar = (name) => window.getComputedStyle(document.documentElement).getPropertyValue(name).trim()

const previousDatasets = (dates) => {
    if (!props.previous) return []
    const values = dates.map((_, i) => props.previous[i] || { positive: 0, negative: 0 })
    const make = (key, color) => ({
        label: 'Previous period',
        stack: 'previous',
        data: values.map((t) => t[key]),
        backgroundColor: 'transparent',
        borderColor: cssVar(color),
        borderWidth: 1.5,
        tooltip: {
            callbacks: {
                label: (context) => `Previous period: ${formatMoney(context.raw)}`,
            },
        },
    })
    return [make('positive', '--color-success'), make('negative', '--color-error')]
}

const chartData = computed(() => {
    const dates = Object.keys(props.balances)

    return {
        labels: dates.map((date) => formatDate(date)),
        datasets: [
            ...previousDatasets(dates),
            {
                stack: 'current',
                data: Object.values(props.balances).map((t) => t.positive),
                backgroundColor: window.getComputedStyle(document.documentElement).getPropertyValue('--color-success'),
                tooltip: {
                    callbacks: {
                        label: (context) => {
                            return `${formatDate(dates[context.dataIndex])}: ${formatMoney(context.raw)}`
                        },
                    },
                },
            },
            {
                stack: 'current',
                data: Object.values(props.balances).map((t) => t.negative),
                backgroundColor: window.getComputedStyle(document.documentElement).getPropertyValue('--color-error'),
                tooltip: {
                    callbacks: {
                        label: (context) => {
                            return `${formatDate(dates[context.dataIndex])}: ${formatMoney(context.raw)}`
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
            stacked: true,
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
