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
import { formatMoneyCompact } from '../../services/formatters.js'

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
    // Fill the parent's height instead of keeping aspectRatio (the parent sets a height per breakpoint).
    fill: {
        type: Boolean,
        default: false,
    },
    // Bars are clickable: emits `bar-click` with the bucket key and shows a pointer cursor.
    clickable: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['bar-click'])

const formatDate = inject('formatDate')
const formatMoney = inject('formatMoney')
const label = (key) => (props.labelFormat ?? formatDate)(key)
const tooltipLabel = (key) => (props.tooltipFormat ?? label)(key)

const { colors, narrow } = useChartTheme()

const previousDatasets = (dates) => {
    if (!props.previous) return []
    const values = dates.map((_, i) => props.previous[i] || { positive: 0, negative: 0 })
    const make = (key, color) => ({
        label: 'Previous period',
        stack: 'previous',
        data: values.map((t) => t[key]),
        backgroundColor: 'transparent',
        borderColor: color,
        borderWidth: 1.5,
        tooltip: {
            callbacks: {
                label: (context) => `Previous period: ${formatMoney(context.raw)}`,
            },
        },
    })
    return [make('positive', colors.value.success), make('negative', colors.value.error)]
}

const keys = computed(() => Object.keys(props.balances))

const chartData = computed(() => {
    const dates = keys.value
    const current = (key, color) => ({
        stack: 'current',
        data: Object.values(props.balances).map((t) => t[key]),
        backgroundColor: color,
        tooltip: {
            callbacks: {
                label: (context) => `${tooltipLabel(dates[context.dataIndex])}: ${formatMoney(context.raw)}`,
            },
        },
    })

    return {
        labels: dates.map(label),
        datasets: [
            ...previousDatasets(dates),
            current('positive', colors.value.success),
            current('negative', colors.value.error),
        ],
    }
})

const chartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: !props.fill,
    aspectRatio: props.aspectRatio,
    onClick: (_event, elements) => {
        if (!props.clickable || !elements.length) return
        emit('bar-click', keys.value[elements[0].index])
    },
    onHover: (event, elements) => {
        if (!props.clickable || !event.native?.target) return
        event.native.target.style.cursor = elements.length ? 'pointer' : 'default'
    },
    scales: {
        x: {
            stacked: true,
            ticks: {
                maxRotation: narrow.value ? 0 : props.maxTickRotation,
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
