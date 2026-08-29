<template>
    <dialog id="recurring_edit_modal" class="modal">
        <div class="modal-box max-w-sm">
            <h2 class="card-title mb-4">
                <Repeat :size="24" />
                Edit Recurring
            </h2>

            <form @submit.prevent="handleSubmit">
                <AmountField v-model="form.amount" />

                <input type="text" v-model="form.note" placeholder="Note (optional)" class="input w-full mb-4"
                    maxlength="255" />

                <select v-model="form.frequency" class="select w-full mb-4" required>
                    <option value="daily">Daily</option>
                    <option value="weekly">Weekly</option>
                    <option value="monthly">Monthly</option>
                    <option value="yearly">Yearly</option>
                </select>

                <div class="text-sm text-base-content/60 mb-1">Ends (optional)</div>
                <input type="date" v-model="form.ends_at" class="input w-full mb-4" />

                <TagPicker v-model="form.tags" />

                <div class="modal-action">
                    <DeleteHold :id="form.id" :disabled="recurringStore.isLoading"
                        :isLoading="recurringStore.isLoading === form.id" @delete="handleDelete" />

                    <button type="submit" class="btn btn-success btn-sm" :disabled="recurringStore.isLoading">
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

const recurringStore = useRecurringTransactionsStore()

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
