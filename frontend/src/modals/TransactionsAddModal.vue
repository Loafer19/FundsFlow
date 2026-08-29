<template>
    <dialog id="transactions_add_modal" class="modal">
        <div class="modal-box max-w-sm">
            <h2 class="card-title mb-4">Add Transaction</h2>

            <form @submit.prevent="handleSubmit">
                <input type="date" v-model="transaction.at" class="input w-full mb-4" required />

                <AmountField v-model="transaction.amount" />

                <TagPicker v-model="transaction.tags" />

                <input type="text" v-model="transaction.note" class="input w-full" placeholder="Note" maxlength="255" />

                <div class="modal-action">
                    <button type="submit" class="btn btn-success" :disabled="transactionsStore.isLoading">
                        <span v-if="transactionsStore.isLoading" class="loading loading-spinner"></span>
                        Save
                        <Save :size="20" />
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
import { Save } from 'lucide-vue-next'
import { ref, watch } from 'vue'
import AmountField from '../components/AmountField.vue'
import TagPicker from '../components/TagPicker.vue'
import { toLocalDateStr } from '../services/formatters.js'
import { useTransactionsStore } from '../services/transactions.js'

const transactionsStore = useTransactionsStore()

const createDefault = () => ({
    at: toLocalDateStr(new Date()),
    amount: '',
    note: '',
    tags: [],
})

const transaction = ref(createDefault())

watch(
    () => transactionsStore.transactionDraftAt,
    (date) => {
        if (!date) return

        transaction.value.at = date
        transactionsStore.transactionDraftAt = null
    },
)

const handleSubmit = async () => {
    const ok = await transactionsStore.create(transaction.value)

    if (!ok) return

    transaction.value = createDefault()
    transactions_add_modal.close()
}
</script>

<style scoped></style>
