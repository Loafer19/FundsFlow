<template>
    <dialog id="budgets_add_modal" class="modal" aria-labelledby="budgets_add_modal_title">
        <div class="modal-box max-w-sm">
            <h2 id="budgets_add_modal_title" class="card-title mb-4">
                <PiggyBank :size="24" />
                Add Budget
            </h2>

            <form ref="formRef" @submit.prevent="handleSubmit">
                <input type="text" v-model="form.title" placeholder="Title (optional)" aria-label="Title"
                    class="input w-full mb-4" maxlength="255" autofocus />

                <AmountField v-model="form.amount" :signed="false" :max="99_999_999.99" :autofocus="false" />

                <select v-model="form.length" class="select w-full mb-4" required aria-label="Budget length">
                    <option value="week">Weekly</option>
                    <option value="month">Monthly</option>
                    <option value="year">Yearly</option>
                </select>

                <label class="label cursor-pointer justify-start gap-2 mb-4">
                    <input type="checkbox" v-model="form.align_to_calendar" class="checkbox checkbox-sm" />
                    <span class="label-text">Start from the beginning of this {{ form.length }}</span>
                </label>

                <TagPicker v-model="form.tag_ids" />

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
import { PiggyBank, Save } from 'lucide-vue-next'
import { ref } from 'vue'
import AmountField from '../components/AmountField.vue'
import TagPicker from '../components/TagPicker.vue'
import { useBudgetsStore } from '../services/budgets.js'
import { useTelegramPrimary } from '../services/telegramChrome.js'

const budgetsStore = useBudgetsStore()

const createDefault = () => ({
    title: '',
    amount: '',
    length: 'month',
    align_to_calendar: false,
    tag_ids: [],
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
    const ok = await budgetsStore.create(form.value)

    if (!ok) return

    form.value = createDefault()
    budgets_add_modal.close()
}
</script>

<style scoped></style>
