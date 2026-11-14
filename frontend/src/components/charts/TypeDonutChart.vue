<template>
    <DoughnutChart :data="chartData" :options="chartOptions" />
</template>

<script setup>
import { ArcElement, Chart as ChartJS, Legend, Tooltip } from 'chart.js'
import { computed, inject } from 'vue'
import { Doughnut as DoughnutChart } from 'vue-chartjs'
import { useChartTheme } from '../../services/chartTheme.js'

ChartJS.register(ArcElement, Tooltip, Legend)

const props = defineProps({
    slices: { type: Array, required: true },
})

const formatMoney = inject('formatMoney')
const { colors } = useChartTheme()

const chartData = computed(() => ({
    labels: props.slices.map((slice) => slice.label),
    datasets: [
        {
            data: props.slices.map((slice) => Math.abs(slice.total)),
            backgroundColor: props.slices.map((slice) => slice.color),
            borderWidth: 0,
        },
    ],
}))

const chartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: true,
    aspectRatio: 1.15,
    cutout: '62%',
    plugins: {
        legend: { display: false },
        tooltip: {
            displayColors: false,
            callbacks: {
                label: (context) => {
                    const slice = props.slices[context.dataIndex]
                    if (!slice) return ''
                    const share = slice.share != null ? ` (${slice.share}%)` : ''
                    return `${slice.count} · ${formatMoney(Math.abs(slice.total))}${share}`
                },
                title: (items) => props.slices[items[0]?.dataIndex]?.label ?? '',
            },
        },
    },
}))

void colors
</script>
