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
    // Optional label formatter for the bucket keys (default: the date format setting), e.g. months.
    labelFormat: {
        type: Function,
        default: null,
    },
    // Optional tooltip title formatter for the bucket keys (default: labelFormat).
    tooltipFormat: {
        type: Function,
        default: null,
    },
    aspectRatio: {
        type: Number,
        default: 2,
    },
    // 0 keeps x labels horizontal (Chart.js then skips labels that do not fit).
    maxTickRotation: {
        type: Number,
        default: 50,
    },
})

const formatDate = inject('formatDate')
const formatMoney = inject('formatMoney')
const label = (key) => (props.labelFormat ?? formatDate)(key)
const tooltipLabel = (key) => (props.tooltipFormat ?? label)(key)

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
        labels: dates.map(label),
        datasets: [
            ...previousDatasets(dates),
            {
                stack: 'current',
                data: Object.values(props.balances).map((t) => t.positive),
                backgroundColor: window.getComputedStyle(document.documentElement).getPropertyValue('--color-success'),
                tooltip: {
                    callbacks: {
                        label: (context) => {
                            return `${tooltipLabel(dates[context.dataIndex])}: ${formatMoney(context.raw)}`
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
                            return `${tooltipLabel(dates[context.dataIndex])}: ${formatMoney(context.raw)}`
                        },
                    },
                },
            },
        ],
    }
})

const chartOptions = {
    responsive: true,
    aspectRatio: props.aspectRatio,
    scales: {
        x: {
            stacked: true,
            ticks: {
                maxRotation: props.maxTickRotation,
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
