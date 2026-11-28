<template>
    <div class="card card-border bg-base-100 goal-card"
        :class="progress.completed ? 'border-success/50 goal-card--done' : 'border-base-300'">
        <span v-if="progress.completed && !inReport" class="goal-confetti" aria-hidden="true">
            <i v-for="n in 8" :key="n" :style="{ '--i': n }"></i>
        </span>

        <div class="card-body gap-2">
            <div class="flex justify-between items-start gap-2">
                <div class="flex items-start gap-2 min-w-0">
                    <span class="shrink-0 rounded-lg p-1.5"
                        :class="progress.completed ? 'bg-success/15 text-success' : 'bg-primary/10 text-primary'">
                        <Target :size="18" />
                    </span>
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 min-w-0 pt-0.5">
                        <h3 class="card-title min-w-0 line-clamp-2 break-words">{{ name }}</h3>
                        <div class="badge badge-sm gap-1 shrink-0" :class="statusBadge">
                            <PartyPopper v-if="progress.completed" :size="12" />
                            {{ goalStatusLabel(progress.status) }}
                        </div>
                    </div>
                </div>

                <BudgetMenu :budget="budget" :name="name" kind="goal" :active="active" :can-view="progress.tags.length > 0"
                    @edit="$emit('edit', budget)" @view-tags="$emit('view-tags', progress.tags)" />
            </div>

            <div v-if="(budget.title && progress.tags.length) || budget.monobank_jar" class="flex items-center gap-1.5 flex-wrap">
                <template v-if="budget.title">
                    <button v-for="tag in progress.tags" :key="tag.id" type="button"
                        class="badge badge-sm badge-ghost gap-1 cursor-pointer hover:bg-base-300"
                        @click="$emit('view-tags', progress.tags)">
                        {{ tag.emoji }} {{ tag.title }}
                    </button>
                </template>
                <span v-if="budget.monobank_jar" class="tooltip tooltip-bottom before:max-w-56 before:text-left"
                    :data-tip="budget.monobank_jar.linked ? 'Progress follows this monobank jar' : 'This jar is no longer in monobank — using the last known balance'">
                    <span class="badge badge-sm badge-outline gap-1" :class="budget.monobank_jar.linked ? '' : 'badge-warning'">
                        {{ budget.monobank_jar.linked ? 'Mono jar' : 'Unlinked jar' }}
                    </span>
                </span>
            </div>

            <div>
                <div class="flex items-baseline justify-between gap-2">
                    <span class="text-sm">
                        Saved <span class="font-semibold tabular-nums">{{ formatMoney(progress.saved) }}</span>
                        of <span class="tabular-nums">{{ formatMoney(progress.target) }}</span>
                    </span>
                    <span class="text-sm font-semibold tabular-nums" :class="{ 'text-success': progress.completed }">
                        {{ progress.percent }}%
                    </span>
                </div>
                <!-- Savings bar; the thin tick is where a straight line to the deadline would be today. -->
                <div class="goal-bar tooltip tooltip-bottom w-full mt-1.5" role="progressbar"
                    :aria-valuenow="Math.round(Math.min(progress.saved, progress.target))" aria-valuemin="0"
                    :aria-valuemax="progress.target || 1" :aria-label="`Saved ${progress.percent}% of the goal`"
                    :data-tip="barTip">
                    <div class="goal-bar__fill" :class="barClass" :style="{ width: Math.min(progress.percent, 100) + '%' }"></div>
                    <div v-if="progress.expectedPercent !== null" class="goal-bar__now"
                        :style="{ left: progress.expectedPercent + '%' }"></div>
                </div>
            </div>

            <div class="text-sm text-base-content/70 flex flex-col gap-0.5">
                <template v-if="progress.completed">
                    <span class="text-success font-medium">Goal reached — well done! 🎉</span>
                    <span v-if="progress.saved > progress.target" class="text-xs">
                        {{ formatMoney(progress.saved - progress.target) }} over the target
                    </span>
                </template>
                <template v-else>
                    <span class="flex items-center gap-1 flex-wrap">
                        <span><span class="tabular-nums font-medium text-base-content">{{ formatMoney(progress.remaining) }}</span> left</span>
                        <template v-if="progress.overdue">
                            <span aria-hidden="true">·</span>
                            <span class="text-warning">deadline passed</span>
                        </template>
                        <template v-else-if="progress.targetDate">
                            <span aria-hidden="true">·</span>
                            <span>{{ progress.daysLeft }} {{ progress.daysLeft === 1 ? 'day' : 'days' }} left</span>
                            <span aria-hidden="true">·</span>
                            <span class="tabular-nums">need ~{{ formatMoney(Math.ceil(progress.requiredAmount)) }}/{{ progress.requiredPer }}</span>
                        </template>
                    </span>

                    <span v-if="!active" class="italic">Paused — progress is kept</span>
                    <span v-else-if="progress.projectedDate" class="flex items-center gap-1">
                        <TrendingUp :size="14" class="shrink-0" />
                        At current pace: {{ formatMonthYear(progress.projectedDate) }}
                    </span>
                    <span v-else class="flex items-center gap-1">
                        <TrendingUp :size="14" class="shrink-0" />
                        No recent contributions to project a date yet
                    </span>
                </template>
            </div>

            <div class="flex items-center justify-between gap-2 min-h-8">
                <div class="text-xs text-base-content/50 min-w-0">
                    Since {{ formatDate(progress.startedAt) }}
                    <template v-if="progress.startAmount > 0"> · started with {{ formatMoney(progress.startAmount) }}</template>
                    <template v-if="progress.targetDate"> · deadline {{ formatDate(progress.targetDate) }}</template>
                </div>
                <button v-if="active && !progress.completed && progress.tags.length" type="button"
                    class="btn btn-soft btn-primary btn-sm shrink-0 report-hide" @click="contribute">
                    <Plus :size="16" />
                    Add contribution
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { PartyPopper, Plus, Target, TrendingUp } from '@lucide/vue'
import { computed, inject } from 'vue'
import { formatDate, formatMoney, formatMonthYear } from '../../services/formatters.js'
import { goalStatusLabel } from '../../services/goals.js'
import { useTransactionsStore } from '../../services/transactions.js'
import BudgetMenu from './BudgetMenu.vue'

const props = defineProps({
    budget: { type: Object, required: true },
    // goalProgress() result (services/goals.js).
    progress: { type: Object, required: true },
})

defineEmits(['edit', 'view-tags'])

const transactionsStore = useTransactionsStore()
const inReport = inject('inReport', false)

const active = computed(() => props.budget.periods.some((period) => period.active))

// Untitled goals are named after their tags.
const name = computed(
    () =>
        props.budget.title ||
        props.progress.tags.map((tag) => `${tag.emoji} ${tag.title}`).join(' · ') ||
        'Savings goal',
)

const statusBadge = computed(
    () =>
        ({
            completed: 'badge-success',
            on_track: 'badge-success badge-outline',
            behind: 'badge-warning badge-outline',
            paused: 'badge-ghost',
            in_progress: 'badge-primary badge-outline',
        })[props.progress.status] ?? 'badge-ghost',
)

const barClass = computed(
    () =>
        ({
            completed: 'bg-success',
            on_track: 'bg-success',
            behind: 'bg-warning',
            paused: 'bg-base-content/40',
            in_progress: 'bg-primary',
        })[props.progress.status] ?? 'bg-primary',
)

const barTip = computed(() =>
    props.progress.expectedAmount === null
        ? `${props.progress.percent}% saved`
        : `${props.progress.percent}% saved · expected by today ${formatMoney(Math.round(props.progress.expectedAmount))}`,
)

// Opens Add Transaction with the goal's tags picked and a hint about the sign.
const contribute = () => {
    transactionsStore.transactionDraftGoal = {
        title: name.value,
        tagIds: props.progress.tags.map((tag) => tag.id),
    }
    transactions_add_modal.showModal()
}
</script>

<style scoped>
.goal-card {
    position: relative;
    overflow: hidden;
}

.goal-bar {
    position: relative;
    display: block;
    height: 0.5rem;
    border-radius: 9999px;
    background: color-mix(in oklab, var(--color-base-content) 14%, transparent);
}

.goal-bar__fill {
    position: absolute;
    inset: 0 auto 0 0;
    border-radius: inherit;
    transition: width 0.2s ease;
}

/* "Expected by today": where a straight line from the start to the deadline would be now. */
.goal-bar__now {
    position: absolute;
    top: -3px;
    bottom: -3px;
    width: 2px;
    margin-left: -1px;
    border-radius: 1px;
    background: color-mix(in oklab, var(--color-base-content) 70%, transparent);
}

.goal-card--done {
    background-image: linear-gradient(
        135deg,
        color-mix(in oklab, var(--color-success) 9%, transparent),
        transparent 55%
    );
}

/* Subtle one-shot celebration for a completed goal: a few dots drift up from the corner. */
.goal-confetti {
    position: absolute;
    top: 2.6rem;
    left: 2.6rem;
    pointer-events: none;
}

.goal-confetti i {
    position: absolute;
    width: 6px;
    height: 6px;
    border-radius: 9999px;
    opacity: 0;
    background: var(--color-success);
    animation: goal-pop 1.2s ease-out calc(var(--i) * 60ms) 1 both;
}

.goal-confetti i:nth-child(3n) {
    background: var(--color-warning);
}

.goal-confetti i:nth-child(3n + 1) {
    background: var(--color-primary);
}

@keyframes goal-pop {
    0% {
        opacity: 0;
        transform: translate(0, 0) scale(0.4);
    }
    25% {
        opacity: 0.9;
    }
    100% {
        opacity: 0;
        transform: translate(calc((var(--i) - 4.5) * 9px), calc(-18px - var(--i) * 3px)) scale(1);
    }
}

@media (prefers-reduced-motion: reduce) {
    .goal-confetti {
        display: none;
    }
}
</style>
