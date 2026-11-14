<template>
    <div :class="{ 'relative h-full w-full': fill }">
        <BarChart v-if="kind === 'bar'" :data="chartData" :options="chartOptions" />
        <LineChart v-else :data="chartData" :options="chartOptions" />
    </div>
</template>

<script setup>
import {
    BarElement,
    CategoryScale,
    Chart as ChartJS,
    Filler,
    Legend,
    LinearScale,
    LineElement,
    PointElement,
    Tooltip,
} from 'chart.js'
import { computed, inject } from 'vue'
import { Bar as BarChart, Line as LineChart } from 'vue-chartjs'
import { useChartTheme } from '../../services/chartTheme.js'
import { monthShort } from '../../services/typeChartFormat.js'

ChartJS.register(BarElement, CategoryScale, LinearScale, LineElement, PointElement, Filler, Tooltip, Legend)

const props = defineProps({
    months: { type: Array, required: true },
    metric: { type: String, default: 'sum' },
    kind: { type: String, default: 'bar' },
    color: { type: String, default: '#f59e0b' },
    label: { type: String, default: '' },
    unit: { type: String, default: null },
    fill: { type: Boolean, default: false },
})

const formatMoney = inject('formatMoney')
const { colors } = useChartTheme()

const formatValue = (value) => {
    if (value == null) return ''
    if (props.unit === '₴' || props.unit === 'UAH') return formatMoney(value)
    const rounded = Number.isInteger(value) ? String(value) : String(Math.round(value * 100) / 100).replace('.', ',')
    return props.unit ? `${rounded} ${props.unit}` : rounded
}

const fillColor = computed(() => {
    const c = props.color
    if (c.startsWith('#') && (c.length === 7 || c.length === 4)) {
        const hex = c.length === 4 ? `#${c[1]}${c[1]}${c[2]}${c[2]}${c[3]}${c[3]}` : c
        const r = Number.parseInt(hex.slice(1, 3), 16)
        const g = Number.parseInt(hex.slice(3, 5), 16)
        const b = Number.parseInt(hex.slice(5, 7), 16)
        return `rgba(${r}, ${g}, ${b}, 0.18)`
    }
    return c
})

const chartData = computed(() => ({
    labels: props.months.map((row) => monthShort(row.month)),
    datasets: [
        {
            label: props.label,
            data: props.months.map((row) => row[props.metric] ?? 0),
            backgroundColor: props.kind === 'bar' ? props.color : fillColor.value,
            borderColor: props.color,
            borderWidth: props.kind === 'line' ? 2.25 : 0,
            borderRadius: props.kind === 'bar' ? { topLeft: 6, topRight: 6, bottomLeft: 2, bottomRight: 2 } : 0,
            borderSkipped: false,
            maxBarThickness: 36,
            tension: 0.25,
            pointRadius: 3.2,
            pointHoverRadius: 5,
            pointBackgroundColor: props.color,
            pointBorderColor: props.color,
            fill: props.kind === 'line',
        },
    ],
}))

const chartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: !props.fill,
    aspectRatio: 1.9,
    plugins: {
        legend: { display: false },
        tooltip: {
            displayColors: false,
            callbacks: {
                title: (items) => items[0]?.label ?? '',
                label: (context) => formatValue(context.raw),
            },
        },
    },
    scales: {
        x: {
            grid: { display: false },
            ticks: { color: colors.value.text, maxRotation: 0, autoSkip: false, font: { size: 11 } },
            border: { display: false },
        },
        y: {
            beginAtZero: props.kind === 'bar',
            grace: props.kind === 'line' ? '10%' : 0,
            grid: props.kind === 'bar' ? { display: false } : { color: colors.value.grid },
            ticks:
                props.kind === 'bar'
                    ? { display: false }
                    : {
                          color: colors.value.muted,
                          font: { size: 10 },
                          callback: (value) => formatValue(value),
                          maxTicksLimit: 4,
                      },
            border: { display: false },
        },
    },
}))
</script>
