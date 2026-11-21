<template>
    <div class="flex flex-col gap-2 min-w-0 h-full">
        <!-- Stat: one number vs the previous period, or every number field of the type -->
        <template v-if="kind === 'stat'">
            <ul v-if="data.fields" class="flex flex-col min-w-0">
                <li v-for="field in data.fields" :key="field.key"
                    class="flex items-center justify-between gap-2 py-1.5 border-b border-base-300/60 last:border-b-0 min-w-0">
                    <span class="truncate text-sm">
                        {{ field.label }}
                        <span class="text-xs text-base-content/50">· {{ aggWord(field.agg) }}</span>
                    </span>
                    <span class="flex items-center gap-2 shrink-0">
                        <span v-if="field.change_pct != null" class="badge badge-sm tabular-nums" :class="pill(field.value, field.previous, true).badge">
                            {{ pct(field.change_pct) }}
                        </span>
                        <span class="font-semibold tabular-nums">{{ formatValue(field.value, field.unit) }}</span>
                    </span>
                </li>
                <li v-if="!data.fields.length" class="text-sm text-base-content/60">No number fields</li>
            </ul>
            <template v-else>
                <div class="text-2xl font-semibold tabular-nums truncate" :style="valueStyle">
                    {{ formatValue(data.value) }}
                </div>
                <div class="flex flex-wrap items-center gap-2 text-xs min-w-0">
                    <span class="text-base-content/60 tooltip tooltip-bottom" :data-tip="previousTip">
                        vs {{ formatValue(data.previous) }}
                    </span>
                    <span v-if="data.change_pct != null" class="badge badge-sm tabular-nums" :class="pill(data.value, data.previous).badge">
                        {{ pct(data.change_pct) }}
                    </span>
                </div>
            </template>
            <div class="text-xs text-base-content/50 mt-auto">
                {{ data.count }} transaction{{ data.count === 1 ? '' : 's' }}
            </div>
        </template>

        <!-- Chart: metric over time, one series or one per tag / field value -->
        <template v-else-if="kind === 'chart'">
            <div v-if="!data.count" class="flex-1 grid place-items-center text-sm text-base-content/60 py-6">No data for this period</div>
            <template v-else>
                <div :class="chartHeight">
                    <CardSeriesChart :buckets="data.buckets" :series="coloredSeries" :kind="data.chart"
                        :bucket="data.bucket" :format="axisFormat" />
                </div>
                <ul v-if="coloredSeries.length > 1 || data.split" class="flex flex-wrap gap-x-3 gap-y-1 text-xs min-w-0">
                    <li v-for="s in coloredSeries" :key="s.label" class="flex items-center gap-1.5 min-w-0">
                        <span class="size-2.5 rounded-full shrink-0" :style="{ background: s.color }"></span>
                        <component :is="s.clickable ? 'button' : 'span'" :type="s.clickable ? 'button' : undefined"
                            class="truncate max-w-40" :class="{ 'link link-hover': s.clickable }"
                            @click="s.clickable && openValue()">
                            {{ s.label }}
                        </component>
                        <span class="tabular-nums text-base-content/60">{{ formatValue(s.total) }}</span>
                    </li>
                </ul>
            </template>
        </template>

        <!-- Pie: share by tag or field value -->
        <template v-else>
            <div v-if="!data.count" class="flex-1 grid place-items-center text-sm text-base-content/60 py-6">No data for this period</div>
            <div v-else class="flex gap-4 min-w-0 items-center" :class="size === 'S' ? 'flex-col' : 'flex-col sm:flex-row'">
                <div class="shrink-0" :class="size === 'L' ? 'w-44' : 'w-32'">
                    <TypeDonutChart :slices="coloredSlices" :format="formatValue" />
                </div>
                <ul class="flex flex-col gap-1 text-sm min-w-0 flex-1 w-full">
                    <li v-for="slice in coloredSlices" :key="slice.label" class="flex items-center gap-2 min-w-0">
                        <span class="size-2.5 rounded-full shrink-0" :style="{ background: slice.color }"></span>
                        <component :is="slice.clickable ? 'button' : 'span'" :type="slice.clickable ? 'button' : undefined"
                            class="truncate text-left" :class="{ 'link link-hover': slice.clickable }"
                            @click="slice.clickable && openValue()">
                            {{ slice.label }}
                        </component>
                        <span class="ml-auto tabular-nums shrink-0">{{ formatValue(slice.total) }}</span>
                        <span class="text-xs text-base-content/60 tabular-nums w-11 text-right shrink-0">{{ slice.share }}%</span>
                    </li>
                </ul>
            </div>
        </template>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { compareValues } from '../../services/comparison.js'
import { formatMoney } from '../../services/formatters.js'
import { useTransactionTypesStore } from '../../services/transactionTypes.js'
import CardSeriesChart from '../charts/CardSeriesChart.vue'
import TypeDonutChart from '../charts/TypeDonutChart.vue'
import { dmy } from './format.js'

const props = defineProps({
    kind: { type: String, required: true },
    data: { type: Object, required: true },
    size: { type: String, default: 'M' },
})

const typesStore = useTransactionTypesStore()

const EXPENSE = 'var(--color-error)'
const INCOME = '#2e9a4c'
const PALETTE = ['#3b82f6', '#f59e0b', '#8b5cf6', '#10b981', '#06b6d4', '#ec4899']
const OTHER = '#94a3b8'
const AGG_WORDS = { sum: 'total', avg: 'average', count: 'count', min: 'min', max: 'max' }

const aggWord = (agg) => AGG_WORDS[agg] ?? agg
const number = (value) =>
    Number.isInteger(value) ? String(value) : (Math.round(value * 100) / 100).toFixed(2).replace('.', ',')

// Money for amounts and ₴ fields, plain numbers with the field unit otherwise, integers for counts.
const formatValue = (value, unit = props.data.unit) => {
    if (value == null) return '—'
    if (props.data.agg === 'count' && !props.data.fields) return String(Math.round(value))
    if (props.data.money && unit === props.data.unit) return formatMoney(value)
    if (['₴', 'UAH', 'грн'].includes(unit)) return formatMoney(value)
    return unit ? `${number(value)} ${unit}` : number(value)
}
const axisFormat = (value) => formatValue(value)

const pct = (value) => `${value >= 0 ? '+' : ''}${value.toFixed(1)}%`

// Spending going down is good; numbers of other things (liters, prices) are neutral.
const pill = (current, previous, neutral = props.data.metric !== 'amount') =>
    compareValues(current, previous, { lowerIsBetter: props.data.flow === 'expense', neutral })

const valueStyle = computed(() => {
    if (props.data.metric !== 'amount' || props.data.agg === 'count') return {}
    if (props.data.flow === 'expense') return { color: EXPENSE }
    if (props.data.flow === 'income') return { color: INCOME }
    return {}
})

const previousTip = computed(() => {
    const p = props.data.previous_period
    if (!p) return ''
    return `Previous: ${dmy(p.from)} – ${dmy(p.to)} · ${props.data.previous_count} transaction${props.data.previous_count === 1 ? '' : 's'}`
})

const chartHeight = computed(() => (props.size === 'L' ? 'h-56' : props.size === 'S' ? 'h-28' : 'h-44'))

// One unsplit series takes the colour of what it shows: expenses red, income green, else the type colour.
const baseColor = computed(() => {
    if (props.data.metric === 'amount' && props.data.flow === 'expense') return '#ef4444'
    if (props.data.metric === 'amount' && props.data.flow === 'income') return INCOME
    return props.data.type?.color || '#3b82f6'
})

const isFieldSplit = computed(() => props.data.split && props.data.split !== 'tag' && props.data.type)
const colorAt = (item, index) =>
    item.label === 'Other' && item.value == null && index > 0 ? OTHER : PALETTE[index % PALETTE.length]

const coloredSeries = computed(() =>
    (props.data.series ?? []).map((s, index) => ({
        ...s,
        color: props.data.split ? colorAt(s, index) : baseColor.value,
        clickable: Boolean(isFieldSplit.value && s.value != null),
    })),
)

const coloredSlices = computed(() =>
    (props.data.slices ?? []).map((slice, index) => ({
        ...slice,
        color: colorAt(slice, index),
        clickable: Boolean(isFieldSplit.value && slice.value != null),
    })),
)

// A field value (WOG) opens the type's own analytics, which breaks the type down by its fields.
const openValue = () => typesStore.openAnalytics(props.data.type?.id)
</script>
