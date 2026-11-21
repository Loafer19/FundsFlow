<template>
    <div class="relative h-full w-full min-h-0">
        <BarChart v-if="kind === 'bar'" :data="chartData" :options="chartOptions" />
        <LineChart v-else :data="chartData" :options="chartOptions" />
    </div>
</template>

<script setup>
// A metric over time for Analytics → My cards: one series, or up to 6 when the card is split by a
// tag or a field value (e.g. average price per liter per station). Buckets come from the server:
// days (YYYY-MM-DD), weeks (the Monday) or months (YYYY-MM).
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
import { computed } from 'vue'
import { Bar as BarChart, Line as LineChart } from 'vue-chartjs'
import { useChartTheme } from '../../services/chartTheme.js'
import { formatMoneyCompact } from '../../services/formatters.js'
import { monthShort } from '../../services/typeChartFormat.js'

ChartJS.register(BarElement, CategoryScale, LinearScale, LineElement, PointElement, Filler, Tooltip, Legend)

const props = defineProps({
    buckets: { type: Array, required: true },
    // [{ label, color, points: [number|null] }]
    series: { type: Array, required: true },
    kind: { type: String, default: 'bar' },
    bucket: { type: String, default: 'day' },
    format: { type: Function, required: true },
})

const { colors, narrow } = useChartTheme()

const bucketLabel = (key) => {
    if (props.bucket === 'month') return monthShort(key)
    const [, m, d] = String(key).split('-')
    return `${d}.${m}`
}

const fade = (hex, alpha) => {
    if (!/^#[0-9a-f]{6}$/i.test(hex)) return hex
    const n = Number.parseInt(hex.slice(1), 16)
    return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${alpha})`
}

const single = computed(() => props.series.length === 1)

const chartData = computed(() => ({
    labels: props.buckets.map(bucketLabel),
    datasets: props.series.map((s) => ({
        label: s.label,
        data: s.points,
        backgroundColor: props.kind === 'bar' ? s.color : fade(s.color, 0.16),
        borderColor: s.color,
        borderWidth: props.kind === 'line' ? 2 : 0,
        borderRadius: props.kind === 'bar' ? 4 : 0,
        maxBarThickness: 28,
        tension: 0.25,
        spanGaps: true,
        pointRadius: props.buckets.length > 40 ? 0 : 2.5,
        pointHoverRadius: 4,
        pointBackgroundColor: s.color,
        fill: props.kind === 'line' && single.value,
    })),
}))

const chartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    animation: { duration: 200 },
    interaction: { mode: 'index', intersect: false },
    plugins: {
        legend: { display: false },
        tooltip: {
            callbacks: {
                label: (context) =>
                    context.raw == null
                        ? null
                        : `${single.value ? '' : context.dataset.label + ': '}${props.format(context.raw)}`,
            },
        },
    },
    scales: {
        x: {
            stacked: props.kind === 'bar' && !single.value,
            grid: { display: false },
            ticks: {
                color: colors.value.text,
                maxRotation: 0,
                autoSkip: true,
                maxTicksLimit: narrow.value ? 5 : 8,
                font: { size: 10 },
            },
            border: { display: false },
        },
        y: {
            stacked: props.kind === 'bar' && !single.value,
            beginAtZero: props.kind === 'bar',
            grace: '10%',
            grid: { color: colors.value.grid },
            ticks: {
                color: colors.value.muted,
                font: { size: 10 },
                maxTicksLimit: 4,
                callback: (value) => (Math.abs(value) >= 10000 ? formatMoneyCompact(value) : props.format(value, true)),
            },
            border: { display: false },
        },
    },
}))
</script>
