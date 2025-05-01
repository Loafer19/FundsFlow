<template>
    <DoughnutChart :data="chartData" :options="chartOptions" />
</template>

<script setup>
import { ArcElement, Chart as ChartJS, Legend, Tooltip } from 'chart.js'
import { computed, inject } from 'vue'
import { Doughnut as DoughnutChart } from 'vue-chartjs'

ChartJS.register(ArcElement, Tooltip, Legend)

const props = defineProps({
    tagsWithAmount: {
        type: Array,
        required: true,
    },
})

const formatMoney = inject('formatMoney')

const colorCache = new Map()
const generateRandomColor = (id) => {
    if (!colorCache.has(id)) {
        const lightness = Math.random() * 10 + 80
        const chroma = Math.random() * 0.12 + 0.08
        const hue = Math.random() * 360
        colorCache.set(id, `oklch(${lightness}% ${chroma} ${hue})`)
    }
    return colorCache.get(id)
}

const chartData = computed(() => {
    return {
        labels: props.tagsWithAmount.map((tag) => tag.emoji + ' ' + tag.title),
        datasets: [
            {
                data: props.tagsWithAmount.map((tag) => tag.amount),
                backgroundColor: props.tagsWithAmount.map((tag) => generateRandomColor(tag.id)),
            },
        ],
    }
})

const chartOptions = {
    responsive: true,
    plugins: {
        legend: {
            position: 'bottom',
            labels: {
                color: window.getComputedStyle(document.documentElement).getPropertyValue('--color-base-content'),
                usePointStyle: true,
            },
        },
        tooltip: {
            displayColors: false,
            callbacks: {
                label: (context) =>
                    `${props.tagsWithAmount[context.dataIndex]?.count} txns: ${formatMoney(chartData.value.datasets[0].data[context.dataIndex])}`,
            },
        },
    },
}
</script>

<style scoped></style>
