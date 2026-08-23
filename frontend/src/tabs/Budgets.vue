<template>
    <EmptyState v-if="!budgetsStore.budgets.length" icon="🐷" title="No budgets yet"
        description="Set a limit on a few tags to keep spending in check." action-label="Add Budget"
        @action="openAdd" />

    <template v-else>
        <div class="flex justify-end mb-3">
            <button type="button" class="btn btn-primary btn-sm" @click="openAdd">
                <Plus :size="20" />
                Add Budget
            </button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div v-for="budget in budgetsStore.budgets" :key="budget.id"
                class="card card-border border-base-300 bg-base-100">
                <div class="card-body">
                    <div class="flex justify-between items-start gap-2">
                        <h3 class="card-title">{{ titleFor(budget) }}</h3>

                        <div class="flex gap-1 shrink-0">
                            <button type="button" class="btn btn-outline btn-secondary btn-square btn-sm"
                                aria-label="Edit" @click="openEdit(budget)">
                                <Pencil :size="20" />
                            </button>

                            <DeleteHold :id="budget.id" :disabled="budgetsStore.isLoading"
                                :isLoading="budgetsStore.isLoading === budget.id"
                                @delete="(id) => budgetsStore.delete(id)" />
                        </div>
                    </div>

                    <template v-if="currentPeriod(budget)">
                        <div class="flex justify-between text-sm text-base-content/60 mb-1">
                            <span>{{ formatMoney(currentPeriod(budget).spent) }} /
                                {{ formatMoney(currentPeriod(budget).amount) }}</span>
                            <span>{{ lengthLabel(currentPeriod(budget).length) }}</span>
                        </div>
                        <progress class="progress w-full" :class="progressClass(currentPeriod(budget))"
                            :value="currentPeriod(budget).spent" :max="currentPeriod(budget).amount"></progress>
                    </template>

                    <template v-if="historyFor(budget).length">
                        <div class="divider text-xs text-base-content/60 my-2">History</div>

                        <div v-for="period in historyFor(budget)" :key="period.id"
                            class="flex justify-between text-sm text-base-content/60">
                            <span>{{ formatDate(period.starts_at) }} – {{ formatDate(period.ends_at) }}</span>
                            <span>{{ formatMoney(period.spent) }} / {{ formatMoney(period.amount) }}</span>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </template>
</template>

<script setup>
import { Pencil, Plus } from 'lucide-vue-next'
import { onMounted } from 'vue'
import DeleteHold from '../components/buttons/DeleteHold.vue'
import EmptyState from '../components/EmptyState.vue'
import { formatDate, formatMoney } from '../services/formatters.js'
import { useBudgetsStore } from '../services/budgets.js'

const budgetsStore = useBudgetsStore()

// `spent` is computed server-side from live transactions, so it goes stale
// the moment a transaction changes elsewhere — refetch on every tab visit.
onMounted(() => budgetsStore.load())

const currentPeriod = (budget) => budget.periods.find((period) => period.active) ?? null
const historyFor = (budget) => budget.periods.filter((period) => !period.active)

const lengthLabel = (length) => ({ week: 'per week', month: 'per month', year: 'per year' })[length]

const titleFor = (budget) => {
    if (budget.title) return budget.title

    const current = currentPeriod(budget)

    return current ? current.tags.map((tag) => tag.emoji).join(' ') : 'Budget'
}

const progressClass = (period) => {
    const ratio = period.amount > 0 ? period.spent / period.amount : 0

    if (ratio >= 1) return 'progress-error'
    if (ratio >= 0.8) return 'progress-warning'

    return 'progress-success'
}

const openAdd = () => budgets_add_modal.showModal()

const openEdit = (budget) => {
    budgetsStore.budgetForEdit = budget
    budgets_edit_modal.showModal()
}
</script>

<style scoped></style>
