<template>
    <dialog id="recurring_edit_modal" class="modal" aria-labelledby="recurring_edit_modal_title">
        <div class="modal-box max-w-sm">
            <ModalHeader title-id="recurring_edit_modal_title" :icon="Repeat">
                Edit Recurring
            </ModalHeader>

            <form ref="formRef" @submit.prevent="handleSubmit">
                <AmountField v-model="form.amount" :max="99_999_999.99" />

                <input type="text" v-model="form.note" placeholder="Note (optional)" aria-label="Note"
                    class="input w-full mb-4" maxlength="255" />

                <div class="mb-4">
                    <div class="text-sm text-base-content/60 mb-1">Frequency</div>
                    <SegmentedControl v-model="form.frequency" block size="sm" label="Recurring frequency"
                        :options="frequencyOptions" />
                </div>
                <label class="block mb-4">
                    <span class="block text-sm text-base-content/60 mb-1">Ends (optional)</span>
                    <DateField v-model="form.ends_at" clearable aria-label="End date" placeholder="No end date" />
                </label>

                <div class="text-sm text-base-content/60 mb-1">Tags</div>
                <TagPicker v-model="form.tags" />

                <div class="modal-action">
                    <DeleteHold v-if="form.id" :id="form.id" :disabled="recurringStore.isLoading"
                        :isLoading="recurringStore.isLoading === form.id" @delete="handleDelete" />

                    <button type="submit" class="btn btn-primary tg-primary" :disabled="recurringStore.isLoading">
                        <span v-if="recurringStore.isLoading" class="loading loading-spinner"></span>
                        <Save v-else :size="20" />
                        Update
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
import { Repeat, Save } from 'lucide-vue-next'
import { ref, watch } from 'vue'
import AmountField from '../components/AmountField.vue'
import DeleteHold from '../components/buttons/DeleteHold.vue'
import TagPicker from '../components/TagPicker.vue'
import { useRecurringTransactionsStore } from '../services/recurringTransactions.js'
import { useTelegramPrimary } from '../services/telegramChrome.js'
import ModalHeader from '../components/ModalHeader.vue'
import SegmentedControl from '../components/SegmentedControl.vue'
import DateField from '../components/DateField.vue'

const frequencyOptions = [
    { value: 'daily', label: 'Daily' },
    { value: 'weekly', label: 'Weekly' },
    { value: 'monthly', label: 'Monthly' },
    { value: 'yearly', label: 'Yearly' },
]

const recurringStore = useRecurringTransactionsStore()

const formRef = ref(null)

useTelegramPrimary('recurring_edit_modal', {
    text: 'Update',
    form: formRef,
    busy: () => Boolean(recurringStore.isLoading),
})

const form = ref({ tags: [] })

watch(
    () => recurringStore.ruleForEdit,
    (rule) => {
        if (!rule) return

        form.value = {
            id: rule.id,
            amount: rule.amount,
            note: rule.note ?? '',
            frequency: rule.frequency,
            starts_at: rule.starts_at,
            ends_at: rule.ends_at ?? '',
            active: rule.active,
            tags: rule.tags.map((tag) => tag.id),
        }
    },
)

const handleSubmit = async () => {
    const ok = await recurringStore.update(form.value.id, { ...form.value, ends_at: form.value.ends_at || null })

    if (!ok) return

    recurringStore.ruleForEdit = null
    recurring_edit_modal.close()
}

const handleDelete = async (id) => {
    const ok = await recurringStore.delete(id)

    if (!ok) return

    recurringStore.ruleForEdit = null
    recurring_edit_modal.close()
}
</script>

<style scoped></style>
