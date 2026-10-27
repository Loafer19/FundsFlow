<template>
    <dialog id="budgets_add_modal" class="modal" aria-labelledby="budgets_add_modal_title">
        <div class="modal-box max-w-sm">
            <h2 id="budgets_add_modal_title" class="card-title mb-4">
                <Target v-if="form.type === 'goal'" :size="24" />
                <PiggyBank v-else :size="24" />
                {{ form.type === 'goal' ? 'Add Savings Goal' : 'Add Budget' }}
            </h2>

            <form ref="formRef" @submit.prevent="handleSubmit">
                <BudgetFormFields v-model="form" />

                <div class="modal-action">
                    <button type="submit" class="btn btn-success tg-primary"
                        :disabled="budgetsStore.isLoading || !form.tag_ids.length">
                        <span v-if="budgetsStore.isLoading" class="loading loading-spinner"></span>
                        Save
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
import { ref } from 'vue'
import BudgetFormFields from '../components/budgets/BudgetFormFields.vue'
import { budgetPayload } from '../services/budgetPayload.js'
import { useBudgetsStore } from '../services/budgets.js'
import { useTelegramPrimary } from '../services/telegramChrome.js'

const budgetsStore = useBudgetsStore()

const createDefault = (type = 'limit') => ({
    type,
    title: '',
    amount: '',
    length: 'month',
    tag_ids: [],
    // Filled by BudgetFormFields: calendar period start for limits, today for goals.
    start_date: '',
    start_auto: true,
    target_date: '',
    start_amount: '',
})

const form = ref(createDefault())

const formRef = ref(null)

useTelegramPrimary('budgets_add_modal', {
    text: 'Save',
    form: formRef,
    busy: () => Boolean(budgetsStore.isLoading),
    enabled: () => form.value.tag_ids.length > 0,
})

const handleSubmit = async () => {
    const ok = await budgetsStore.create(budgetPayload(form.value))

    if (!ok) return

    // Keep the chosen type so adding several goals in a row stays quick.
    form.value = createDefault(form.value.type)
    budgets_add_modal.close()
}
</script>

<style scoped></style>
