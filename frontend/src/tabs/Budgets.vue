<template>
    <EmptyState v-if="!budgetsStore.budgets.length" :icon="PiggyBank" title="No budgets or goals yet"
        description="Set a spending limit on a few tags, or a savings goal to see how close you are"
        action-label="Add Budget or Goal" @action="openAdd" />

    <template v-else>
        <template v-if="goals.length">
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3 mb-3">
                <StatCard label="Total saved" :current="goalSummary.saved" :previous="null" :compare="false"
                    :note="'of ' + formatMoney(goalSummary.target)" icon="savings" />
                <StatCard label="Left to save" :current="goalSummary.remaining" :previous="null" :compare="false"
                    :note="goalSummary.monthlyNeed ? 'need ~' + formatMoney(Math.ceil(goalSummary.monthlyNeed)) + '/month' : ''"
                    icon="goal" />
                <StatCard class="max-md:hidden" label="Goals completed" :current="goalSummary.completed"
                    :previous="null" :compare="false" format="count" :note="'of ' + goals.length" icon="done" />
            </div>
            <p class="md:hidden flex items-center gap-1.5 text-sm text-base-content/70 mb-3">
                <Flag :size="14" class="text-success" />
                {{ goalSummary.completed }} of {{ goals.length }} goals completed
            </p>

            <div class="flex items-center justify-between gap-2 mb-2">
                <h3 class="text-sm font-semibold uppercase tracking-wide text-base-content/60">Savings goals</h3>
                <button type="button" class="btn btn-primary btn-sm report-hide" aria-label="Add Budget or Goal"
                    @click="openAdd">
                    <Plus :size="20" />
                    <span class="max-sm:hidden">Add Budget or Goal</span>
                    <span class="sm:hidden">Add</span>
                </button>
            </div>
            <div v-if="activeGoalCards.length" class="grid grid-cols-1 md:grid-cols-2 gap-3"
                :class="{ 'mb-3': completedGoalCards.length, 'mb-5': !completedGoalCards.length && limits.length }">
                <GoalCard v-for="{ budget, progress } in activeGoalCards" :key="budget.id" :budget="budget"
                    :progress="progress" @edit="openEdit" @view-tags="viewTransactions" />
            </div>

            <details v-if="completedGoalCards.length" class="mb-5" :open="completedOpen ?? !activeGoalCards.length"
                @toggle="completedOpen = $event.target.open">
                <summary
                    class="cursor-pointer text-sm font-medium text-base-content/70 rounded-field px-1 -mx-1 py-1 mb-2 hover:bg-base-200">
                    Completed goals ({{ completedGoalCards.length }})
                </summary>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <GoalCard v-for="{ budget, progress } in completedGoalCards" :key="budget.id" :budget="budget"
                        :progress="progress" @edit="openEdit" @view-tags="viewTransactions" />
                </div>
            </details>
        </template>

        <div v-if="limits.length || !goals.length" class="flex items-center justify-between gap-2 mb-2">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-base-content/60">Spending limits</h3>
            <button v-if="!goals.length" type="button" class="btn btn-primary btn-sm report-hide"
                aria-label="Add Budget or Goal" @click="openAdd">
                <Plus :size="20" />
                <span class="max-sm:hidden">Add Budget or Goal</span>
                <span class="sm:hidden">Add</span>
            </button>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <LimitCard v-for="budget in limitCards" :key="budget.id" :budget="budget" @edit="openEdit"
                @view-tags="viewTransactions" />
        </div>
    </template>
</template>

<script setup>
import { Flag, PiggyBank, Plus } from '@lucide/vue'
import { computed, ref } from 'vue'
import GoalCard from '../components/budgets/GoalCard.vue'
import LimitCard from '../components/budgets/LimitCard.vue'
import EmptyState from '../components/EmptyState.vue'
import StatCard from '../components/StatCard.vue'
import { useBudgetsStore } from '../services/budgets.js'
import { formatMoney, nowDateStr } from '../services/formatters.js'
import { goalProgress, isGoal } from '../services/goals.js'
import { useTransactionsStore } from '../services/transactions.js'

const budgetsStore = useBudgetsStore()
const transactionsStore = useTransactionsStore()

// App passes the same props and listeners to every tab; this one renders a fragment and uses only some
defineOptions({ inheritAttrs: false })
const emit = defineEmits(['jump-to-list'])

const limits = computed(() => budgetsStore.budgets.filter((budget) => !isGoal(budget)))
const goals = computed(() => budgetsStore.budgets.filter(isGoal))

// Active limits first, paused ones last (stable otherwise).
const limitCards = computed(() =>
    limits.value
        .map((budget, index) => ({ budget, index, paused: !budget.periods.some((period) => period.active) }))
        .sort((a, b) => a.paused - b.paused || a.index - b.index)
        .map(({ budget }) => budget),
)

// Active goals first (closest to done on top), then paused, completed last.
const goalCards = computed(() => {
    const today = nowDateStr()
    const rank = (p) => (p.completed ? 2 : p.status === 'paused' ? 1 : 0)

    return goals.value
        .map((budget) => ({ budget, progress: goalProgress(budget, transactionsStore.transactions, today) }))
        .sort((a, b) => rank(a.progress) - rank(b.progress) || b.progress.percent - a.progress.percent)
})

const activeGoalCards = computed(() => goalCards.value.filter(({ progress }) => !progress.completed))
const completedGoalCards = computed(() => goalCards.value.filter(({ progress }) => progress.completed))

// Completed goals are folded away while other goals are in progress; the reader's choice sticks.
const completedOpen = ref(null)

const goalSummary = computed(() => {
    const sum = { saved: 0, target: 0, remaining: 0, completed: 0, monthlyNeed: 0 }

    for (const { progress } of goalCards.value) {
        sum.saved += progress.saved
        sum.target += progress.target
        sum.remaining += progress.remaining
        if (progress.completed) sum.completed++
        if (progress.requiredAmount && progress.status !== 'paused') {
            sum.monthlyNeed +=
                progress.requiredPer === 'week' ? (progress.requiredAmount * 30.4375) / 7 : progress.requiredAmount
        }
    }

    return sum
})

const viewTransactions = (tags) => {
    transactionsStore.tagFilterDraft = tags.map((tag) => tag.id)
    emit('jump-to-list')
}

const openAdd = () => budgets_add_modal.showModal()

const openEdit = (budget) => {
    budgetsStore.budgetForEdit = budget
    budgets_edit_modal.showModal()
}
</script>

<style scoped></style>
