<template>
    <div :class="{ 'relative h-full w-full': fill }">
        <LineChart :data="chartData" :options="chartOptions" />
    </div>
</template>

<script setup>
import { CategoryScale, Chart as ChartJS, Legend, LinearScale, LineElement, PointElement, Tooltip } from 'chart.js'
import { computed, inject } from 'vue'
import { Line as LineChart } from 'vue-chartjs'
import { useChartTheme } from '../../services/chartTheme.js'
import { formatMoneyCompact } from '../../services/formatters.js'

ChartJS.register(LineElement, PointElement, CategoryScale, LinearScale, Tooltip, Legend)

const props = defineProps({
    balances: {
        type: Object,
        required: true,
    },
    // Fill the parent's height instead of the default 2:1 aspect ratio.
    fill: {
        type: Boolean,
        default: false,
    },
})

const formatDate = inject('formatDate')
const formatMoney = inject('formatMoney')

const { colors, narrow } = useChartTheme()

const hasForecast = computed(() =>
    Object.values(props.balances.forecastBalances || {}).some((value) => value != null),
)

const chartData = computed(() => {
    const { currentBalances, previousBalances, forecastBalances = {} } = props.balances
    const currentLabels = Object.keys(currentBalances)
    const previousLabels = Object.keys(previousBalances)

    // A longer previous period (31 vs 30 days) extends the axis with its own trailing dates.
    const labels = [...currentLabels, ...previousLabels.slice(currentLabels.length)]
    // Dots only while they stay readable; long ranges (a year) are a plain line with a hover dot.
    const pointRadius = labels.length > 45 || narrow.value ? 0 : 2

    const pad = (values) =>
        values.length >= labels.length ? values : [...values, ...Array(labels.length - values.length).fill(null)]

    const datasets = [
        {
            label: 'This period',
            data: pad(Object.values(currentBalances)),
            borderColor: colors.value.success,
            backgroundColor: colors.value.success,
            borderWidth: 2,
            pointRadius,
            pointHoverRadius: 4,
            tension: 0.1,
            tooltip: {
                callbacks: {
                    label: (context) =>
                        `${formatDate(currentLabels[context.dataIndex])}: ${formatMoney(context.raw)}`,
                },
            },
        },
    ]

    if (hasForecast.value) {
        datasets.push({
            label: 'Forecast',
            data: pad(currentLabels.map((date) => (forecastBalances[date] != null ? forecastBalances[date] : null))),
            borderColor: colors.value.muted,
            backgroundColor: colors.value.muted,
            borderWidth: 2,
            borderDash: [6, 4],
            pointRadius,
            pointHoverRadius: 4,
            tension: 0.1,
            tooltip: {
                callbacks: {
                    label: (context) =>
                        `Forecast · ${formatDate(currentLabels[context.dataIndex])}: ${formatMoney(context.raw)}`,
                },
            },
        })
    }

    datasets.push({
        label: 'Previous period',
        data: Object.values(previousBalances),
        borderColor: colors.value.error,
        backgroundColor: colors.value.error,
        borderWidth: 2,
        pointRadius,
        pointHoverRadius: 4,
        tension: 0.1,
        tooltip: {
            callbacks: {
                label: (context) =>
                    `Previous · ${formatDate(previousLabels[context.dataIndex])}: ${formatMoney(context.raw)}`,
            },
        },
    })

    return {
        labels: labels.map((date) => formatDate(date)),
        datasets,
    }
})

const chartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: !props.fill,
    interaction: {
        mode: 'index',
        intersect: false,
    },
    scales: {
        x: {
            ticks: {
                maxRotation: narrow.value ? 0 : 50,
                autoSkipPadding: narrow.value ? 12 : 3,
                color: colors.value.text,
                font: { size: narrow.value ? 10 : 12 },
            },
            grid: {
                display: !narrow.value,
                color: colors.value.grid,
            },
        },
        y: {
            ticks: {
                maxTicksLimit: narrow.value ? 6 : 11,
                callback: (value) => (narrow.value ? formatMoneyCompact(value) : formatMoney(value)),
                color: colors.value.text,
                font: { size: narrow.value ? 10 : 12 },
            },
            grid: {
                color: colors.value.grid,
            },
        },
    },
    plugins: {
        legend: {
            display: hasForecast.value,
            position: 'top',
            align: 'end',
            labels: {
                boxWidth: 14,
                boxHeight: 2,
                padding: 10,
                color: colors.value.text,
                font: { size: narrow.value ? 10 : 12 },
            },
        },
        tooltip: {
            displayColors: false,
            callbacks: {
                title: () => '',
            },
        },
    },
}))
</script>

<style scoped></style>
