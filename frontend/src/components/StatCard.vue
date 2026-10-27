<template>
    <div class="card card-border border-base-300 bg-base-100 min-w-0">
        <div class="card-body p-4 gap-1 min-w-0">
            <div class="flex items-center gap-2 min-w-0">
                <span v-if="iconMeta" class="shrink-0 rounded-lg p-1.5" :class="iconMeta.class">
                    <component :is="iconMeta.component" :size="16" />
                </span>
                <div class="text-xs uppercase tracking-wide text-base-content/60 flex items-center gap-1 min-w-0">
                    <span class="truncate">{{ label }}</span>
                    <span v-if="hint" class="tooltip shrink-0 normal-case tracking-normal report-hide" :class="hintPlacement" :data-tip="hint">
                        <Info :size="12" class="opacity-60" />
                    </span>
                </div>
            </div>
            <div class="flex flex-wrap items-baseline gap-x-2 min-w-0">
                <span class="text-lg font-semibold tabular-nums">{{ display(current) }}</span>
                <span v-if="note" class="text-xs text-base-content/60">{{ note }}</span>
            </div>
            <div v-if="compare" class="flex flex-wrap items-center gap-2 text-xs">
                <span class="text-base-content/60">
                    vs {{ display(previous) }}<template v-if="previousNote"> · {{ previousNote }}</template>
                </span>
                <span v-if="diff.delta !== null" class="badge badge-sm tabular-nums" :class="diff.badge">
                    {{ diff.delta >= 0 ? '+' : '' }}{{ displayDelta(diff.delta) }}
                    <template v-if="diff.pct !== null"> ({{ diff.pct >= 0 ? '+' : '' }}{{ formatPercentage(diff.pct) }})</template>
                </span>
            </div>
        </div>
    </div>
</template>

<script setup>
import {
    ArrowDownToLine,
    ArrowUpToLine,
    CalendarDays,
    Flag,
    Gauge,
    Info,
    PiggyBank,
    Receipt,
    Scale,
    Target,
    Telescope,
    TrendingDown,
    TrendingUp,
} from 'lucide-vue-next'
import { computed } from 'vue'
import { compareValues } from '../services/comparison.js'
import { formatMoney, formatPercentage } from '../services/formatters.js'
import settings from '../services/settings.js'

const props = defineProps({
    label: { type: String, required: true },
    // null renders as "—" (e.g. a savings rate without income).
    current: { type: [Number, null], required: true },
    previous: { type: [Number, null], required: true },
    // Optional context next to the value / previous value (e.g. the date of a peak).
    note: { type: String, default: '' },
    previousNote: { type: String, default: '' },
    // Hide the "vs previous" line when the period comparison is switched off.
    compare: { type: Boolean, default: true },
    // money (default) · percent (delta in percentage points) · count (integer).
    format: { type: String, default: 'money' },
    // Colour of the delta pill: a drop is good (expenses shown as positive numbers).
    lowerIsBetter: { type: Boolean, default: false },
    // Neither direction is good or bad (e.g. transaction count): grey pill.
    neutral: { type: Boolean, default: false },
    hint: { type: String, default: '' },
    // Tooltip placement classes for the hint (a card in the last column can open it to the left).
    hintPlacement: { type: String, default: 'tooltip-bottom' },
    // income · expenses · net · avg · savings · count · daily · goal · done · low · peak · forecast
    // (unknown → no icon).
    icon: { type: String, default: '' },
})

const ICONS = {
    income: { component: TrendingUp, class: 'bg-success/15 text-success' },
    expenses: { component: TrendingDown, class: 'bg-error/15 text-error' },
    net: { component: Scale, class: 'bg-primary/15 text-primary' },
    avg: { component: Gauge, class: 'bg-info/15 text-info' },
    savings: { component: PiggyBank, class: 'bg-secondary/15 text-secondary' },
    count: { component: Receipt, class: 'bg-base-content/10 text-base-content/70' },
    daily: { component: CalendarDays, class: 'bg-warning/15 text-warning' },
    goal: { component: Target, class: 'bg-primary/15 text-primary' },
    done: { component: Flag, class: 'bg-success/15 text-success' },
    low: { component: ArrowDownToLine, class: 'bg-error/15 text-error' },
    peak: { component: ArrowUpToLine, class: 'bg-success/15 text-success' },
    forecast: { component: Telescope, class: 'bg-info/15 text-info' },
}

const iconMeta = computed(() => ICONS[props.icon] ?? null)

const integer = computed(() => new Intl.NumberFormat(settings.moneyFormat, { maximumFractionDigits: 0 }))

const display = (value) => {
    if (value == null || !Number.isFinite(value)) return '—'
    if (props.format === 'percent') return formatPercentage(value)
    if (props.format === 'count') return integer.value.format(value)
    return formatMoney(value)
}

const displayDelta = (value) => {
    if (props.format === 'percent') return `${value.toFixed(1)} pp`
    if (props.format === 'count') return integer.value.format(value)
    return formatMoney(value)
}

const diff = computed(() =>
    compareValues(props.current, props.previous, {
        lowerIsBetter: props.lowerIsBetter,
        neutral: props.neutral,
        relative: props.format !== 'percent',
    }),
)
</script>
