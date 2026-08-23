<template>
    <dialog id="budgets_edit_modal" class="modal">
        <div class="modal-box max-w-sm">
            <h2 class="card-title mb-4">
                <PiggyBank :size="24" />
                Edit Budget
            </h2>

            <form @submit.prevent="handleSubmit">
                <input type="text" v-model="form.title" placeholder="Title (optional)" class="input w-full mb-4"
                    maxlength="255" autofocus />

                <input type="number" step="0.01" min="0.01" v-model="form.amount" placeholder="Amount"
                    class="input w-full mb-4" required />

                <select v-model="form.length" class="select w-full mb-4">
                    <option value="week">Weekly</option>
                    <option value="month">Monthly</option>
                    <option value="year">Yearly</option>
                </select>

                <TagPicker v-model="form.tag_ids" />

                <div class="modal-action">
                    <button type="submit" class="btn btn-success"
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
import { PiggyBank, Save } from 'lucide-vue-next'
import { ref, watch } from 'vue'
import TagPicker from '../components/TagPicker.vue'
import { useBudgetsStore } from '../services/budgets.js'

const budgetsStore = useBudgetsStore()

const form = ref({ tag_ids: [] })

watch(
    () => budgetsStore.budgetForEdit,
    (budget) => {
        if (!budget) return

        // Fall back to the most recent period when paused (no active one) —
        // periods are ordered newest-first, so [0] is still the right prefill.
        const current = budget.periods.find((period) => period.active) ?? budget.periods[0]

        if (!current) return

        form.value = {
            id: budget.id,
            title: budget.title ?? '',
            amount: current.amount,
            length: current.length,
            tag_ids: current.tags.map((tag) => tag.id),
        }
    },
)

const handleSubmit = async () => {
    const ok = await budgetsStore.update(form.value.id, form.value)

    if (!ok) return

    budgetsStore.budgetForEdit = null
    budgets_edit_modal.close()
}
</script>

<style scoped></style>
