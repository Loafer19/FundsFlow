<template>
    <div class="flex flex-col items-center gap-4 min-w-0">
        <div class="relative w-full max-w-[17.5rem]">
            <DoughnutChart :data="chartData" :options="chartOptions" />
            <div class="absolute inset-0 grid place-items-center pointer-events-none">
                <div class="text-center px-12 min-w-0">
                    <div class="text-xs text-base-content/60 truncate">{{ centerLabel }}</div>
                    <div class="text-lg font-semibold tabular-nums">{{ centerValue }}</div>
                </div>
            </div>
        </div>

        <ul class="w-full flex flex-col min-w-0" aria-label="Legend">
            <li v-for="item in items" :key="item.key" class="border-b border-base-300/60 last:border-b-0">
                <component :is="item.clickable || item.drillable ? 'button' : 'div'"
                    :type="item.clickable || item.drillable ? 'button' : undefined"
                    class="w-full flex items-center gap-2 py-1.5 px-1 text-left min-w-0 rounded-field"
                    :class="{ 'cursor-pointer hover:bg-base-200/60': clickable && (item.clickable || item.drillable) }"
                    :aria-label="clickable && item.drillable ? `Show ${item.title} sub-tags` : undefined"
                    @click="clickable && select(item)">
                    <span class="size-2.5 rounded-full shrink-0" :style="{ backgroundColor: item.color }"></span>
                    <span class="shrink-0">{{ item.emoji }}</span>
                    <span class="truncate flex-1 min-w-0">{{ item.title }}</span>
                    <span class="font-medium tabular-nums shrink-0">{{ formatMoney(item.amount) }}</span>
                    <span class="text-xs text-base-content/60 tabular-nums w-12 text-right shrink-0">
                        {{ formatPercentage(share(item)) }}
                    </span>
                    <ChevronRight v-if="clickable" :size="14" class="shrink-0 text-base-content/40"
                        :class="{ 'opacity-0': !item.drillable }" />
                </component>
            </li>
        </ul>
    </div>
</template>

<script setup>
import { ChevronRight } from '@lucide/vue'
import { ArcElement, Chart as ChartJS, Tooltip } from 'chart.js'
import { computed, inject } from 'vue'
import { Doughnut as DoughnutChart } from 'vue-chartjs'
import { themeColor, useChartTheme } from '../../services/chartTheme.js'

ChartJS.register(ArcElement, Tooltip)

const props = defineProps({
    // [{ key, id, title, emoji, amount, count, color, clickable, drillable }], largest first.
    items: { type: Array, required: true },
    centerLabel: { type: String, default: 'Total' },
    centerValue: { type: String, default: '' },
    /** Emit `select` on slice / legend click. */
    clickable: { type: Boolean, default: false },
})

const emit = defineEmits(['select'])

const formatMoney = inject('formatMoney')
const formatPercentage = inject('formatPercentage')
const { colors } = useChartTheme()

const sum = computed(() => props.items.reduce((total, item) => total + item.amount, 0))
const share = (item) => (sum.value > 0 ? (item.amount / sum.value) * 100 : 0)

const select = (item) => {
    if (item.clickable || item.drillable) emit('select', item)
}

const chartData = computed(() => ({
    labels: props.items.map((item) => `${item.emoji} ${item.title}`),
    datasets: [
        {
            data: props.items.map((item) => item.amount),
            backgroundColor: props.items.map((item) => item.color),
            borderColor: themeColor('--color-base-100'),
            borderWidth: 2,
        },
    ],
}))

const chartOptions = computed(() => {
    colors.value

    return {
        responsive: true,
        cutout: '68%',
        onClick: (_event, elements) => {
            const item = props.items[elements[0]?.index]
            if (props.clickable && item) select(item)
        },
        onHover: (event, elements) => {
            if (!props.clickable || !event.native?.target) return
            const item = props.items[elements[0]?.index]
            event.native.target.style.cursor = item && (item.clickable || item.drillable) ? 'pointer' : 'default'
        },
        plugins: {
            legend: { display: false },
            tooltip: {
                displayColors: false,
                callbacks: {
                    label: (context) => {
                        const item = props.items[context.dataIndex]
                        return `${item?.count} txns: ${formatMoney(item?.amount)} (${formatPercentage(share(item))})`
                    },
                },
            },
        },
    }
})
</script>
