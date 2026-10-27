<template>
    <dialog id="recurring_edit_modal" class="modal" aria-labelledby="recurring_edit_modal_title">
        <div class="modal-box max-w-sm">
            <h2 id="recurring_edit_modal_title" class="card-title mb-4">
                <Repeat :size="24" />
                Edit Recurring
            </h2>

            <form ref="formRef" @submit.prevent="handleSubmit">
                <AmountField v-model="form.amount" :max="99_999_999.99" />

                <input type="text" v-model="form.note" placeholder="Note (optional)" aria-label="Note"
                    class="input w-full mb-4" maxlength="255" />

                <div class="grid grid-cols-2 gap-3 mb-4">
                    <label class="block min-w-0">
                        <span class="block text-sm text-base-content/60 mb-1">Frequency</span>
                        <select v-model="form.frequency" class="select w-full" required aria-label="Recurring frequency">
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                            <option value="yearly">Yearly</option>
                        </select>
                    </label>
                    <label class="block min-w-0">
                        <span class="block text-sm text-base-content/60 mb-1">Ends (optional)</span>
                        <input type="date" v-model="form.ends_at" class="input w-full" aria-label="End date" />
                    </label>
                </div>

                <div class="text-sm text-base-content/60 mb-1">Tags</div>
                <TagPicker v-model="form.tags" />

                <div class="modal-action">
                    <DeleteHold v-if="form.id" :id="form.id" :disabled="recurringStore.isLoading"
                        :isLoading="recurringStore.isLoading === form.id" @delete="handleDelete" />

                    <button type="submit" class="btn btn-success btn-sm tg-primary" :disabled="recurringStore.isLoading">
                        <span v-if="recurringStore.isLoading" class="loading loading-spinner"></span>
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
import { Repeat, Save } from 'lucide-vue-next'
import { ref, watch } from 'vue'
import AmountField from '../components/AmountField.vue'
import DeleteHold from '../components/buttons/DeleteHold.vue'
import TagPicker from '../components/TagPicker.vue'
import { useRecurringTransactionsStore } from '../services/recurringTransactions.js'
import { useTelegramPrimary } from '../services/telegramChrome.js'

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
