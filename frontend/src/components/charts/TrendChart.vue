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

const chartData = computed(() => {
    const { currentBalances, previousBalances } = props.balances
    const currentLabels = Object.keys(currentBalances)
    const previousLabels = Object.keys(previousBalances)

    // A longer previous period (31 vs 30 days) extends the axis with its own trailing dates.
    const labels = [...currentLabels, ...previousLabels.slice(currentLabels.length)]
    // Dots only while they stay readable; long ranges (a year) are a plain line with a hover dot.
    const pointRadius = labels.length > 45 || narrow.value ? 0 : 2

    return {
        labels: labels.map((date) => formatDate(date)),
        datasets: [
            {
                label: 'This period',
                data: Object.values(currentBalances),
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
            {
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
            },
        ],
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
            display: false,
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
