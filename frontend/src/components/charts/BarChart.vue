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
})

const formatDate = inject('formatDate')
const formatMoney = inject('formatMoney')

const chartData = computed(() => {
    return {
        labels: Object.keys(props.balances).map((date) => formatDate(date)),
        datasets: [
            {
                data: Object.values(props.balances).map((t) => t.positive),
                backgroundColor: window.getComputedStyle(document.documentElement).getPropertyValue('--color-success'),
                tooltip: {
                    callbacks: {
                        label: (context) => {
                            return `${formatDate(context.label)}: ${formatMoney(context.raw)}`
                        },
                    },
                },
            },
            {
                data: Object.values(props.balances).map((t) => t.negative),
                backgroundColor: window.getComputedStyle(document.documentElement).getPropertyValue('--color-error'),
                tooltip: {
                    callbacks: {
                        label: (context) => {
                            return `${formatDate(context.label)}: ${formatMoney(context.raw)}`
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
