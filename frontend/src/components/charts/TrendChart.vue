<template>
    <div :class="{ 'relative h-full w-full': fill }">
        <LineChart :data="chartData" :options="chartOptions" :plugins="plugins" />
    </div>
</template>

<script setup>
import { CategoryScale, Chart as ChartJS, Legend, LinearScale, LineElement, PointElement, Tooltip } from 'chart.js'
import { computed, inject } from 'vue'
import { Line as LineChart } from 'vue-chartjs'
import { themeColor, useChartTheme } from '../../services/chartTheme.js'
import { formatMoneyCompact, formatMonthYear } from '../../services/formatters.js'

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
    // Year view is one point per month; month and week stay daily.
    bucket: {
        type: String,
        default: 'day',
        validator: (value) => value === 'day' || value === 'month',
    },
    // Date key of today in the current series (`YYYY-MM-DD`, or the month's first day when bucket is month).
    today: {
        type: String,
        default: '',
    },
    clickable: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['select'])

const formatDate = inject('formatDate')
const formatMoney = inject('formatMoney')

const { colors, narrow } = useChartTheme()

const hasForecast = computed(() => Object.values(props.balances.forecastBalances || {}).some((value) => value != null))
const hasPrevious = computed(() => Object.values(props.balances.previousBalances || {}).some((value) => value != null))

const axis = computed(() => {
    const { currentBalances, previousBalances = {}, forecastBalances = {} } = props.balances
    const currentLabels = Object.keys(currentBalances)
    const previousLabels = Object.keys(previousBalances)
    // A longer previous period (31 vs 30 days) extends the axis with its own trailing dates.
    const labels = [...currentLabels, ...previousLabels.slice(currentLabels.length)]

    return { currentLabels, previousLabels, labels, currentBalances, previousBalances, forecastBalances }
})

const formatTick = (date) => (props.bucket === 'month' ? formatMonthYear(date) : formatDate(date))

const todayIndex = computed(() => (props.today ? axis.value.currentLabels.indexOf(props.today) : -1))

const pointRadiusAt = (index) => {
    const base = axis.value.labels.length > 45 || narrow.value ? 0 : 2
    return index === todayIndex.value ? Math.max(base, 4) : base
}

const chartData = computed(() => {
    const { currentLabels, previousLabels, labels, currentBalances, previousBalances, forecastBalances } = axis.value
    const today = todayIndex.value
    // Dots only while they stay readable; long ranges (a year of days) are a plain line with a hover dot.
    const pointRadius = labels.map((_, index) => pointRadiusAt(index))

    const pad = (values) =>
        values.length >= labels.length ? values : [...values, ...Array(labels.length - values.length).fill(null)]

    const datasets = [
        {
            label: 'Current',
            data: pad(Object.values(currentBalances)),
            borderColor: colors.value.success,
            backgroundColor: colors.value.success,
            borderWidth: 2,
            pointRadius,
            pointHoverRadius: 5,
            pointBorderWidth: labels.map((_, index) => (index === today ? 2 : 1)),
            tension: 0.1,
            tooltip: {
                callbacks: {
                    label: (context) => `${formatTick(currentLabels[context.dataIndex])}: ${formatMoney(context.raw)}`,
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
            pointHoverRadius: 5,
            tension: 0.1,
            tooltip: {
                callbacks: {
                    label: (context) =>
                        `Forecast · ${formatTick(currentLabels[context.dataIndex])}: ${formatMoney(context.raw)}`,
                },
            },
        })
    }

    if (hasPrevious.value) {
        datasets.push({
            label: 'Previous',
            data: Object.values(previousBalances),
            borderColor: colors.value.muted,
            backgroundColor: colors.value.muted,
            borderWidth: 2,
            borderDash: [2, 3],
            pointRadius,
            pointHoverRadius: 5,
            tension: 0.1,
            tooltip: {
                callbacks: {
                    label: (context) =>
                        `Previous · ${formatTick(previousLabels[context.dataIndex])}: ${formatMoney(context.raw)}`,
                },
            },
        })
    }

    return {
        labels: labels.map((date) => formatTick(date)),
        datasets,
    }
})

const todayPlugin = {
    id: 'trendToday',
    afterDatasetsDraw(chart) {
        const index = chart.options.plugins?.trendToday?.index
        if (index == null || index < 0) return

        const point = chart.getDatasetMeta(0).data[index]
        if (!point) return

        const { ctx, chartArea } = chart
        ctx.save()
        ctx.beginPath()
        ctx.moveTo(point.x, chartArea.top)
        ctx.lineTo(point.x, chartArea.bottom)
        ctx.lineWidth = 1
        ctx.setLineDash([3, 3])
        ctx.strokeStyle = themeColor('--color-base-content', 0.35)
        ctx.stroke()
        ctx.font = `${narrow.value ? 10 : 11}px sans-serif`
        ctx.fillStyle = themeColor('--color-base-content', 0.55)
        ctx.textAlign = 'center'
        ctx.fillText('Today', point.x, chartArea.top + 12)
        ctx.restore()
    },
}

const plugins = [todayPlugin]

const chartOptions = computed(() => {
    colors.value
    const { currentLabels, labels } = axis.value
    const today = todayIndex.value
    const zero = themeColor('--color-base-content', 0.28)

    return {
        responsive: true,
        maintainAspectRatio: !props.fill,
        interaction: {
            mode: 'index',
            intersect: false,
        },
        onClick: (_event, elements) => {
            const date = currentLabels[elements[0]?.index]
            if (props.clickable && date) emit('select', date)
        },
        onHover: (event, elements) => {
            if (!props.clickable || !event.native?.target) return
            event.native.target.style.cursor = currentLabels[elements[0]?.index] ? 'pointer' : 'default'
        },
        scales: {
            x: {
                ticks: {
                    maxRotation: narrow.value ? 0 : props.bucket === 'month' ? 0 : 50,
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
                    color: (ctx) => (ctx.tick.value === 0 ? zero : colors.value.grid),
                    lineWidth: (ctx) => (ctx.tick.value === 0 ? 2 : 1),
                },
            },
        },
        plugins: {
            legend: {
                display: true,
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
            trendToday: { index: today, labels },
        },
    }
})
</script>
