<template>
    <div class="card card-border bg-base-100" :class="cardBorder">
        <div class="card-body gap-2">
            <div class="flex justify-between items-start gap-2">
                <h3 class="card-title min-w-0">
                    <span class="shrink-0 rounded-lg p-1.5" :class="chipClass">
                        <Gauge :size="18" />
                    </span>
                    <span class="line-clamp-2 break-words">
                        <template v-if="budget.title">{{ budget.title }}</template>
                        <template v-else-if="tags.length">
                            {{ tags.map((tag) => `${tag.emoji} ${tag.title}`).join(' · ') }}
                        </template>
                        <template v-else>Spending limit</template>
                    </span>
                </h3>

                <div class="flex gap-1 shrink-0 report-hide">
                    <button v-if="current" type="button"
                        class="btn btn-outline btn-warning btn-square btn-sm tooltip" data-tip="Pause"
                        aria-label="Pause" :disabled="budgetsStore.isLoading === budget.id"
                        @click="budgetsStore.pause(budget.id)">
                        <span v-if="budgetsStore.isLoading === budget.id" class="loading loading-spinner loading-xs"></span>
                        <Pause v-else :size="20" />
                    </button>
                    <button v-else type="button" class="btn btn-outline btn-success btn-square btn-sm tooltip"
                        data-tip="Resume" aria-label="Resume" :disabled="budgetsStore.isLoading === budget.id"
                        @click="budgetsStore.resume(budget.id)">
                        <span v-if="budgetsStore.isLoading === budget.id" class="loading loading-spinner loading-xs"></span>
                        <Play v-else :size="20" />
                    </button>

                    <button type="button" class="btn btn-outline btn-secondary btn-square btn-sm tooltip"
                        data-tip="Edit" aria-label="Edit" :disabled="budgetsStore.isLoading" @click="$emit('edit', budget)">
                        <Pencil :size="20" />
                    </button>

                    <DeleteHold :id="budget.id" :disabled="budgetsStore.isLoading"
                        :isLoading="budgetsStore.isLoading === budget.id" @delete="(id) => budgetsStore.delete(id)" />
                </div>
            </div>

            <div class="flex items-center gap-1 flex-wrap">
                <button v-for="tag in tags" :key="tag.id" type="button" class="tooltip cursor-pointer"
                    :data-tip="tag.title" @click="$emit('view-tags', tags)">{{ tag.emoji }}</button>
                <div class="badge badge-sm ml-auto" :class="statusBadge">{{ statusLabel }}</div>
            </div>

            <template v-if="p">
                <div>
                    <div class="flex items-baseline justify-between gap-2">
                        <span class="text-sm">
                            Spent <span class="font-semibold tabular-nums">{{ formatMoney(p.spent) }}</span>
                            of <span class="tabular-nums">{{ formatMoney(p.amount) }}</span>
                        </span>
                        <span class="text-sm font-semibold tabular-nums" :class="toneText">{{ p.percent }}%</span>
                    </div>
                    <!-- Spending bar with a thin marker for how much of the period has passed. -->
                    <div class="limit-bar tooltip tooltip-bottom w-full mt-1.5" role="progressbar"
                        :aria-valuenow="Math.round(p.spent)" aria-valuemin="0" :aria-valuemax="p.amount"
                        :aria-label="`Spent ${p.percent}% of the limit`"
                        :data-tip="`${p.percent}% spent · ${timePercent}% of the ${periodNoun} passed`">
                        <div class="limit-bar__fill" :class="toneBg" :style="{ width: Math.min(p.percent, 100) + '%' }"></div>
                        <div v-if="p.elapsedDays < p.totalDays" class="limit-bar__now" :style="{ left: timePercent + '%' }"></div>
                    </div>
                </div>

                <div class="text-sm text-base-content/70 flex flex-col gap-0.5">
                    <span v-if="p.status === 'over'">
                        <span class="tabular-nums font-medium text-error">{{ formatMoney(-p.left) }}</span> over the limit
                    </span>
                    <span v-else>
                        <span class="tabular-nums font-medium text-base-content">{{ formatMoney(p.left) }}</span> left
                    </span>

                    <span v-if="p.status !== 'over' && p.left > 0" class="flex items-center gap-1">
                        <CalendarClock :size="14" class="shrink-0" />
                        {{ p.daysLeft }} {{ p.daysLeft === 1 ? 'day' : 'days' }} left ·
                        <span class="tabular-nums">keep under ~{{ formatMoney(Math.floor(p.perDay)) }}/day</span>
                    </span>
                    <span v-if="p.elapsedDays < p.totalDays && p.spent > 0" class="flex items-center gap-1"
                        :class="{ 'text-error': p.projected > p.amount && p.status !== 'over' }">
                        <TrendingUp :size="14" class="shrink-0" />
                        At current pace ~{{ formatMoney(Math.round(p.projected)) }} by {{ formatDate(p.end) }}
                    </span>
                </div>

                <div class="text-xs text-base-content/50">
                    {{ formatDate(p.start) }} – {{ formatDate(p.end) }} · {{ frequencyLabel }} · day {{ p.elapsedDays }}
                    of {{ p.totalDays }}
                </div>
            </template>
            <div v-else class="text-sm text-base-content/60 italic">
                Paused — {{ formatMoney(Number(latest?.amount ?? 0)) }} {{ frequencyLabel.toLowerCase() }}
            </div>

            <details v-if="history.length" class="limit-history mt-1" :open="inReport">
                <summary class="text-xs text-base-content/60 cursor-pointer select-none flex items-center gap-1">
                    <ChevronRight :size="14" class="limit-history__chevron shrink-0" />
                    History
                    <span class="ml-auto tabular-nums">{{ passedCount }}/{{ history.length }} within limit</span>
                </summary>

                <ul class="mt-1.5 flex flex-col gap-1">
                    <li v-for="row in history" :key="row.key" class="grid grid-cols-[1rem_1fr_3.5rem_auto] items-center gap-2 text-xs">
                        <Check v-if="row.ok" :size="14" class="text-success" aria-label="Within limit" />
                        <X v-else :size="14" class="text-error" aria-label="Over limit" />
                        <span class="truncate text-base-content/70">
                            {{ formatDate(row.start) }} – {{ formatDate(row.end) }}
                            <span v-if="row.tagsChanged" class="ml-0.5">{{ row.emojis }}</span>
                        </span>
                        <span class="limit-bar limit-bar--mini" aria-hidden="true">
                            <span class="limit-bar__fill" :class="row.ok ? 'bg-success/70' : 'bg-error/80'"
                                :style="{ width: Math.min(row.percent, 100) + '%' }"></span>
                        </span>
                        <span class="tabular-nums text-right text-base-content/70 whitespace-nowrap">
                            {{ formatMoney(row.spent) }} / {{ formatMoney(row.amount) }}
                        </span>
                    </li>
                </ul>
            </details>
        </div>
    </div>
</template>

<script setup>
import { CalendarClock, Check, ChevronRight, Gauge, Pause, Pencil, Play, TrendingUp, X } from 'lucide-vue-next'
import { computed, inject } from 'vue'
import { useBudgetsStore } from '../../services/budgets.js'
import { formatDate, formatMoney } from '../../services/formatters.js'
import { historyBuckets, limitPercent, limitProgress, spentBetween } from '../../services/limits.js'
import { useTransactionsStore } from '../../services/transactions.js'
import DeleteHold from '../buttons/DeleteHold.vue'

const props = defineProps({
    budget: { type: Object, required: true },
})

defineEmits(['edit', 'view-tags'])

const budgetsStore = useBudgetsStore()
const transactionsStore = useTransactionsStore()
const inReport = inject('inReport', false)

// Periods come newest-first; a paused limit has no active one.
const current = computed(() => props.budget.periods.find((period) => period.active) ?? null)
const latest = computed(() => current.value ?? props.budget.periods[0] ?? null)
const tags = computed(() => latest.value?.tags ?? [])

const p = computed(() =>
    current.value
        ? limitProgress(current.value, props.budget.periods, transactionsStore, props.budget.anchor_date)
        : null,
)

const frequencyLabel = computed(
    () => ({ week: 'Weekly', month: 'Monthly', year: 'Yearly' })[latest.value?.length] ?? '',
)
const periodNoun = computed(() => latest.value?.length ?? 'period')
const timePercent = computed(() => (p.value ? Math.round((p.value.elapsedDays / p.value.totalDays) * 100) : 0))

const status = computed(() => p.value?.status ?? 'paused')
const statusLabel = computed(
    () => ({ on_track: 'On track', near: 'Near limit', over: 'Over', paused: 'Paused' })[status.value],
)
const statusBadge = computed(
    () =>
        ({
            on_track: 'badge-success badge-outline',
            near: 'badge-warning badge-outline',
            over: 'badge-error',
            paused: 'badge-ghost',
        })[status.value],
)
const toneBg = computed(() => ({ on_track: 'bg-success', near: 'bg-warning', over: 'bg-error' })[status.value])
const toneText = computed(() => ({ near: 'text-warning', over: 'text-error' })[status.value] ?? '')
const chipClass = computed(
    () =>
        ({
            near: 'bg-warning/15 text-warning',
            over: 'bg-error/15 text-error',
            paused: 'bg-base-200 text-base-content/60',
        })[status.value] ?? 'bg-primary/10 text-primary',
)
const cardBorder = computed(() => (status.value === 'over' ? 'border-error/40' : 'border-base-300'))

// Closed versions (from their effective start; a version replaced mid-period hands that period to its successor) plus the open one's finished buckets, sliced into week/month/year buckets on the budget's schedule, newest first, one compact pass/fail row each.
const history = computed(() => {
    const currentIds = tags.value
        .map((tag) => tag.id)
        .sort()
        .join(',')

    return props.budget.periods
        .filter((period) => period.ends_at || period.active)
        .flatMap((period) => {
            const amount = Number(period.amount)
            const tagsChanged =
                period.tags
                    .map((tag) => tag.id)
                    .sort()
                    .join(',') !== currentIds

            return historyBuckets(period, props.budget.periods, props.budget.anchor_date)
                .reverse()
                .map((bucket) => {
                    const spent = spentBetween(transactionsStore, bucket.start, bucket.end, period.tags)

                    return {
                        key: `${period.id}-${bucket.start}`,
                        ...bucket,
                        amount,
                        spent,
                        ok: spent <= amount,
                        percent: limitPercent(spent, amount),
                        tagsChanged,
                        emojis: period.tags.map((tag) => tag.emoji).join(''),
                    }
                })
        })
})

const passedCount = computed(() => history.value.filter((row) => row.ok).length)
</script>

<style scoped>
.limit-bar {
    position: relative;
    display: block;
    height: 0.5rem;
    border-radius: 9999px;
    background: color-mix(in oklab, var(--color-base-content) 10%, transparent);
}

.limit-bar__fill {
    position: absolute;
    inset: 0 auto 0 0;
    border-radius: inherit;
    transition: width 0.3s ease;
}

/* "Today" marker: how far through the week/month/year we are. */
.limit-bar__now {
    position: absolute;
    top: -3px;
    bottom: -3px;
    width: 2px;
    margin-left: -1px;
    border-radius: 1px;
    background: color-mix(in oklab, var(--color-base-content) 55%, transparent);
}

.limit-bar--mini {
    height: 0.375rem;
}

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
</style>
