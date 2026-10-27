<template>
    <dialog id="budgets_edit_modal" class="modal" aria-labelledby="budgets_edit_modal_title">
        <div class="modal-box max-w-sm">
            <h2 id="budgets_edit_modal_title" class="card-title mb-4">
                <Target v-if="form.type === 'goal'" :size="24" />
                <PiggyBank v-else :size="24" />
                {{ form.type === 'goal' ? 'Edit Savings Goal' : 'Edit Budget' }}
            </h2>

            <form ref="formRef" @submit.prevent="handleSubmit">
                <BudgetFormFields v-model="form" type-locked />

                <div class="modal-action">
                    <button type="submit" class="btn btn-success tg-primary"
                        :disabled="budgetsStore.isLoading || !form.tag_ids.length">
                        <span v-if="budgetsStore.isLoading" class="loading loading-spinner"></span>
                        Update
                        <Save :size="24" />
                    </button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button>close</button>
        </form>
    </dialog>
</template>

<script setup>
import { PiggyBank, Save, Target } from 'lucide-vue-next'
import { ref, watch } from 'vue'
import BudgetFormFields from '../components/budgets/BudgetFormFields.vue'
import { budgetPayload } from '../services/budgetPayload.js'
import { useBudgetsStore } from '../services/budgets.js'
import { nowDateStr } from '../services/formatters.js'
import { chainStart } from '../services/limits.js'
import { useTelegramPrimary } from '../services/telegramChrome.js'

const budgetsStore = useBudgetsStore()

const form = ref({ type: 'limit', tag_ids: [] })

const formRef = ref(null)

const earliestStart = (periods) =>
    periods
        .map((period) => period.starts_at)
        .filter(Boolean)
        .sort()[0] ?? ''

useTelegramPrimary('budgets_edit_modal', {
    text: 'Update',
    form: formRef,
    busy: () => Boolean(budgetsStore.isLoading),
    enabled: () => form.value.tag_ids.length > 0,
})

watch(
    () => budgetsStore.budgetForEdit,
    (budget) => {
        if (!budget) return

        // Fall back to the most recent period when paused (no active one) —
        // periods are ordered newest-first, so [0] is still the right prefill.
        const current = budget.periods.find((period) => period.active) ?? budget.periods[0]

        if (!current) return

        const goal = budget.type === 'goal'
        // Limits: the start of the open period (paused: resume from today); it cannot move
        // before the previous settings ended.
        const limitStart = current.active ? current.starts_at : nowDateStr()
        const closedEnds = budget.periods
            .filter((period) => period !== current || !current.active)
            .map((period) => period.ends_at)
            .filter(Boolean)
            .sort()

        form.value = {
            id: budget.id,
            type: goal ? 'goal' : 'limit',
            title: budget.title ?? '',
            amount: current.amount,
            length: current.length ?? 'month',
            tag_ids: current.tags.map((tag) => tag.id),
            start_date: goal ? (budget.start_date ?? earliestStart(budget.periods)) : limitStart,
            start_original: goal ? '' : limitStart,
            // The run the open limit belongs to: edits keep counting from the active period's start.
            chain_start: !goal && current.active ? chainStart(current, budget.periods) : '',
            paused: !current.active,
            anchor_original: budget.anchor_date ?? '',
            start_min: goal ? '' : (closedEnds.at(-1) ?? ''),
            target_date: budget.target_date ?? '',
            target_original: budget.target_date ?? '',
            start_amount: budget.start_amount ?? '',
        }
    },
)

const handleSubmit = async () => {
    const ok = await budgetsStore.update(form.value.id, budgetPayload(form.value))

    if (!ok) return

    budgetsStore.budgetForEdit = null
    budgets_edit_modal.close()
}
</script>

<style scoped></style>
