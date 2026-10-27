<template>
    <div class="card card-border bg-base-100 goal-card"
        :class="progress.completed ? 'border-success/50 goal-card--done' : 'border-base-300'">
        <span v-if="progress.completed && !inReport" class="goal-confetti" aria-hidden="true">
            <i v-for="n in 8" :key="n" :style="{ '--i': n }"></i>
        </span>

        <div class="card-body gap-2">
            <div class="flex justify-between items-start gap-2">
                <h3 class="card-title min-w-0">
                    <span class="shrink-0 rounded-lg p-1.5"
                        :class="progress.completed ? 'bg-success/15 text-success' : 'bg-primary/10 text-primary'">
                        <Target :size="18" />
                    </span>
                    <span class="line-clamp-2 break-words">
                        <template v-if="budget.title">{{ budget.title }}</template>
                        <template v-else-if="progress.tags.length">
                            {{ progress.tags.map((tag) => tag.emoji).join(' ') }}
                        </template>
                        <template v-else>Savings goal</template>
                    </span>
                </h3>

                <div class="flex gap-1 shrink-0 report-hide">
                    <button v-if="active" type="button"
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
                <button v-for="tag in progress.tags" :key="tag.id" type="button" class="tooltip cursor-pointer"
                    :data-tip="tag.title" @click="$emit('view-tags', progress.tags)">{{ tag.emoji }}</button>
                <div class="badge badge-sm ml-auto gap-1" :class="statusBadge">
                    <PartyPopper v-if="progress.completed" :size="12" />
                    {{ goalStatusLabel(progress.status) }}
                </div>
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
                <progress class="progress w-full" :class="progressClass" :value="Math.min(progress.saved, progress.target)"
                    :max="progress.target || 1"></progress>
            </div>

            <div class="text-sm text-base-content/70 flex flex-col gap-0.5">
                <template v-if="progress.completed">
                    <span class="text-success font-medium">Goal reached — well done! 🎉</span>
                    <span v-if="progress.saved > progress.target" class="text-xs">
                        {{ formatMoney(progress.saved - progress.target) }} over the target
                    </span>
                </template>
                <template v-else>
                    <span><span class="tabular-nums font-medium text-base-content">{{ formatMoney(progress.remaining) }}</span> left to save</span>

                    <span v-if="progress.targetDate" class="flex items-center gap-1">
                        <CalendarClock :size="14" class="shrink-0" />
                        <template v-if="progress.overdue">Deadline {{ formatDate(progress.targetDate) }} has passed</template>
                        <template v-else>
                            {{ progress.daysLeft }} {{ progress.daysLeft === 1 ? 'day' : 'days' }} left ·
                            <span class="tabular-nums">need ~{{ formatMoney(Math.ceil(progress.requiredAmount)) }}/{{ progress.requiredPer }}</span>
                        </template>
                    </span>

                    <span v-if="!active" class="italic">Paused — progress is kept</span>
                    <span v-else-if="progress.projectedDate" class="flex items-center gap-1">
                        <TrendingUp :size="14" class="shrink-0" />
                        At current pace you will reach it in {{ formatMonthYear(progress.projectedDate) }}
                    </span>
                    <span v-else class="flex items-center gap-1">
                        <TrendingUp :size="14" class="shrink-0" />
                        No recent contributions to project a date yet
                    </span>
                </template>
            </div>

            <div class="text-xs text-base-content/50">
                Since {{ formatDate(progress.startedAt) }}
                <template v-if="progress.startAmount > 0"> · started with {{ formatMoney(progress.startAmount) }}</template>
                <template v-if="progress.targetDate"> · deadline {{ formatDate(progress.targetDate) }}</template>
            </div>
        </div>
    </div>
</template>

<script setup>
import { CalendarClock, PartyPopper, Pause, Pencil, Play, Target, TrendingUp } from 'lucide-vue-next'
import { computed, inject } from 'vue'
import { useBudgetsStore } from '../../services/budgets.js'
import { formatDate, formatMoney } from '../../services/formatters.js'
import { formatMonthYear, goalStatusLabel } from '../../services/goals.js'
import DeleteHold from '../buttons/DeleteHold.vue'

const props = defineProps({
    budget: { type: Object, required: true },
    // goalProgress() result (services/goals.js).
    progress: { type: Object, required: true },
})

defineEmits(['edit', 'view-tags'])

const budgetsStore = useBudgetsStore()
const inReport = inject('inReport', false)

const active = computed(() => props.budget.periods.some((period) => period.active))

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

const progressClass = computed(
    () =>
        ({
            completed: 'progress-success',
            on_track: 'progress-success',
            behind: 'progress-warning',
            paused: '',
            in_progress: 'progress-primary',
        })[props.progress.status] ?? '',
)
</script>

<style scoped>
.goal-card {
    position: relative;
    overflow: hidden;
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
