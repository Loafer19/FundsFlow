<template>
    <div class="card card-border border-base-300 bg-base-100">
        <div class="card-body p-4 gap-1">
            <div class="text-xs uppercase tracking-wide text-base-content/60">{{ label }}</div>
            <div class="flex flex-wrap items-baseline gap-x-2">
                <span class="text-lg font-semibold">{{ formatMoney(current) }}</span>
                <span v-if="note" class="text-xs text-base-content/60">{{ note }}</span>
            </div>
            <div v-if="compare" class="flex flex-wrap items-center gap-2 text-xs">
                <span class="text-base-content/60">
                    vs {{ formatMoney(previous) }}<template v-if="previousNote"> · {{ previousNote }}</template>
                </span>
                <span class="badge badge-sm" :class="diff.badge">
                    {{ diff.delta >= 0 ? '+' : '' }}{{ formatMoney(diff.delta) }}
                    <template v-if="diff.pct !== null"> ({{ diff.pct >= 0 ? '+' : '' }}{{ formatPercentage(diff.pct) }})</template>
                </span>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { compareValues } from '../services/comparison.js'
import { formatMoney, formatPercentage } from '../services/formatters.js'

const props = defineProps({
    label: { type: String, required: true },
    current: { type: Number, required: true },
    previous: { type: Number, required: true },
    // Optional context next to the value / previous value (e.g. the date of a peak).
    note: { type: String, default: '' },
    previousNote: { type: String, default: '' },
    // Hide the "vs previous" line when the period comparison is switched off.
    compare: { type: Boolean, default: true },
})

const diff = computed(() => compareValues(props.current, props.previous))
</script>
