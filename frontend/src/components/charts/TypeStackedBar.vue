<template>
    <div :class="{ 'relative h-full w-full': fill }">
        <BarChart :data="chartData" :options="chartOptions" />
    </div>
</template>

<script setup>
import { BarElement, CategoryScale, Chart as ChartJS, Legend, LinearScale, Tooltip } from 'chart.js'
import { computed, inject } from 'vue'
import { Bar as BarChart } from 'vue-chartjs'
import { useChartTheme } from '../../services/chartTheme.js'
import { monthShort } from '../../services/typeChartFormat.js'

ChartJS.register(BarElement, CategoryScale, LinearScale, Tooltip, Legend)

const props = defineProps({
    months: { type: Array, required: true },
    series: { type: Array, required: true },
    fill: { type: Boolean, default: false },
})

const formatMoney = inject('formatMoney')
const { colors } = useChartTheme()

const chartData = computed(() => ({
    labels: props.months.map((row) => monthShort(row.month)),
    datasets: props.series.map((item) => ({
        label: item.label,
        data: props.months.map((row) => {
            const slice = (row.slices || []).find((entry) => entry.label === item.label)
            return Math.abs(slice?.total ?? 0)
        }),
        backgroundColor: item.color,
        borderWidth: 0,
        stack: 'spend',
        borderRadius: 2,
    })),
}))

const chartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: !props.fill,
    aspectRatio: 2.1,
    plugins: {
        legend: { display: false },
        tooltip: {
            callbacks: {
                label: (context) => `${context.dataset.label}: ${formatMoney(context.raw)}`,
            },
        },
    },
    scales: {
        x: {
            stacked: true,
            grid: { display: false },
            ticks: { color: colors.value.text, maxRotation: 0 },
            border: { display: false },
        },
        y: {
            stacked: true,
            beginAtZero: true,
            grid: { display: false },
            ticks: { display: false },
            border: { display: false },
        },
    },
}))
</script>
