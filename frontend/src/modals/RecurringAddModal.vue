<template>
    <dialog id="recurring_add_modal" class="modal">
        <div class="modal-box max-w-sm">
            <h2 class="card-title mb-4">
                <Repeat :size="24" />
                Add Recurring
            </h2>

            <form @submit.prevent="handleSubmit">
                <AmountField v-model="form.amount" />

                <input type="text" v-model="form.note" placeholder="Note (optional)" class="input w-full mb-4"
                    maxlength="255" />

                <select v-model="form.frequency" class="select w-full mb-4">
                    <option value="daily">Daily</option>
                    <option value="weekly">Weekly</option>
                    <option value="monthly">Monthly</option>
                    <option value="yearly">Yearly</option>
                </select>

                <div class="text-sm text-base-content/60 mb-1">Starts</div>
                <input type="date" v-model="form.starts_at" class="input w-full mb-4" required />

                <div class="text-sm text-base-content/60 mb-1">Ends (optional)</div>
                <input type="date" v-model="form.ends_at" class="input w-full mb-4" />

                <TagPicker v-model="form.tags" />

                <div class="modal-action">
                    <button type="submit" class="btn btn-success" :disabled="recurringStore.isLoading">
                        <span v-if="recurringStore.isLoading" class="loading loading-spinner"></span>
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
import { Repeat, Save } from 'lucide-vue-next'
import { ref } from 'vue'
import AmountField from '../components/AmountField.vue'
import TagPicker from '../components/TagPicker.vue'
import { toLocalDateStr } from '../services/formatters.js'
import { useRecurringTransactionsStore } from '../services/recurringTransactions.js'

const recurringStore = useRecurringTransactionsStore()

const createDefault = () => ({
    amount: '',
    note: '',
    frequency: 'monthly',
    starts_at: toLocalDateStr(new Date()),
    ends_at: '',
    tags: [],
})

const form = ref(createDefault())

const handleSubmit = async () => {
    const ok = await recurringStore.create({ ...form.value, ends_at: form.value.ends_at || null })

    if (!ok) return

    form.value = createDefault()
    recurring_add_modal.close()
}
</script>

<style scoped></style>
