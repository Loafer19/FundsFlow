<template>
    <details class="limit-history mt-1" :open="open">
        <summary class="text-xs text-base-content/60 cursor-pointer select-none flex items-center gap-1">
            <ChevronRight :size="14" class="limit-history__chevron shrink-0" />
            History
            <span class="ml-auto tabular-nums truncate">
                Avg {{ formatMoney(Math.round(avg)) }} · {{ withinCount }}/{{ rows.length }} within
            </span>
        </summary>

        <!-- Newest first: % pill, spent / limit, and a bar on a shared scale (marker = limit). -->
        <ul class="mt-2 flex flex-col gap-2.5">
            <li v-for="item in newestFirst" :key="item.key" class="flex flex-col gap-1">
                <div class="flex items-center gap-2 text-xs">
                    <span class="truncate" :class="item.now ? 'font-medium' : 'text-base-content/70'">
                        {{ labelOf(item, false) }}
                        <span v-if="item.tagsChanged" class="ml-0.5">{{ item.emojis }}</span>
                    </span>
                    <span v-if="item.now" class="badge badge-ghost badge-xs">now</span>
                    <span class="badge badge-sm tabular-nums" :class="pillTone(item)">{{ item.percent }}%</span>
                    <span class="ml-auto tabular-nums text-right whitespace-nowrap text-base-content/70">
                        <span class="text-base-content font-medium">{{ formatMoney(item.spent) }}</span>
                        / {{ formatMoney(item.amount) }}
                    </span>
                </div>
                <span class="history-track" aria-hidden="true">
                    <span class="history-track__fill" :class="[barTone(item), { 'history-bar--now': item.now }]"
                        :style="{ width: (item.spent / scaleMax) * 100 + '%' }"></span>
                    <span class="history-track__marker" :style="{ left: (item.amount / scaleMax) * 100 + '%' }"></span>
                </span>
            </li>
        </ul>
    </details>
</template>

<script setup>
import { ChevronRight } from 'lucide-vue-next'
import { computed } from 'vue'
import { formatMoney, parseLocalDate } from '../../services/formatters.js'

const props = defineProps({
    // Finished buckets, newest first: { key, start, end, spent, amount, percent, ok, tagsChanged, emojis }.
    rows: { type: Array, required: true },
    // The active bucket (limitProgress result) or null when paused.
    current: { type: Object, default: null },
    open: { type: Boolean, default: false },
})

const now = computed(() =>
    props.current
        ? {
              key: 'now',
              now: true,
              start: props.current.start,
              end: props.current.end,
              spent: props.current.spent,
              amount: props.current.amount,
              percent: props.current.percent,
          }
        : null,
)
const newestFirst = computed(() => (now.value ? [now.value, ...props.rows] : props.rows))

const avg = computed(() => props.rows.reduce((sum, row) => sum + row.spent, 0) / (props.rows.length || 1))
const withinCount = computed(() => props.rows.filter((row) => row.ok).length)
const scaleMax = computed(
    () => Math.max(1, ...newestFirst.value.map((item) => Math.max(item.spent, item.amount))) * 1.05,
)

const barTone = (item) => (item.spent > item.amount ? 'bg-error/80' : 'bg-success/70')
const pillTone = (item) => (item.spent > item.amount ? 'badge-error' : 'badge-success')
const month = (date, withYear) =>
    date.toLocaleDateString('en-US', withYear ? { month: 'short', year: 'numeric' } : { month: 'short' })
const day = (date) => date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })

// Whole calendar months/years read as "Sep" / "Sep 2026" / "2025"; anchored or clipped buckets as a range.
const labelOf = (item, short) => {
    const start = parseLocalDate(item.start)
    const end = parseLocalDate(item.end)
    const wholeMonth =
        start.getDate() === 1 &&
        end.getMonth() === start.getMonth() &&
        end.getFullYear() === start.getFullYear() &&
        new Date(end.getFullYear(), end.getMonth(), end.getDate() + 1).getDate() === 1
    const wholeYear =
        item.start.endsWith('-01-01') && item.end.endsWith('-12-31') && start.getFullYear() === end.getFullYear()

    if (wholeYear) return String(start.getFullYear())
    if (wholeMonth) return month(start, !short)
    if (short) return day(start)

    return start.getMonth() === end.getMonth() ? `${day(start)} – ${end.getDate()}` : `${day(start)} – ${day(end)}`
}
</script>

<style scoped>
.limit-history > summary {
    list-style: none;
}

.limit-history > summary::-webkit-details-marker {
    display: none;
}

.limit-history__chevron {
    transition: transform 0.15s ease;
}

.limit-history[open] .limit-history__chevron {
    transform: rotate(90deg);
}

/* The running period: same colour, striped so it reads as "not final yet". */
.history-bar--now {
    background-image: repeating-linear-gradient(
        -45deg,
        transparent 0 4px,
        color-mix(in oklab, var(--color-base-100) 45%, transparent) 4px 7px
    );
}

.history-track {
    position: relative;
    display: block;
    height: 0.3rem;
    border-radius: 9999px;
    background: color-mix(in oklab, var(--color-base-content) 8%, transparent);
}

.history-track__fill {
    position: absolute;
    inset: 0 auto 0 0;
    border-radius: inherit;
}

.history-track__marker {
    position: absolute;
    top: -3px;
    bottom: -3px;
    width: 2px;
    margin-left: -1px;
    border-radius: 1px;
    background: color-mix(in oklab, var(--color-base-content) 55%, transparent);
}
</style>
