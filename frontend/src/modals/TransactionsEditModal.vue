<template>
    <dialog id="transactions_edit_modal" class="modal">
        <div class="modal-box max-w-sm">
            <h2 class="card-title mb-4">Edit Transaction</h2>

            <form @submit.prevent="handleSubmit">
                <input type="date" v-model="transaction.at" class="input w-full mb-4" required />

                <AmountField v-model="transaction.amount" />

                <TagPicker v-model="transaction.tags" />

                <input type="text" v-model="transaction.note" class="input w-full" placeholder="Note" maxlength="255" />

                <div class="modal-action">
                    <DeleteHold :id="transaction.id" :disabled="transactionsStore.isLoading"
                        :isLoading="transactionsStore.isLoading === transaction.id" @delete="handleDelete" />

                    <button type="submit" class="btn btn-success btn-sm" :disabled="transactionsStore.isLoading">
                        <span v-if="transactionsStore.isLoading" class="loading loading-spinner"></span>
                        Update
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
import DeleteHold from '../components/buttons/DeleteHold.vue'
import TagPicker from '../components/TagPicker.vue'
import { toLocalDateStr } from '../services/formatters.js'
import { useTransactionsStore } from '../services/transactions.js'

const transactionsStore = useTransactionsStore()

const transaction = ref({
    tags: [],
})

watch(
    () => transactionsStore.transactionForEdit,
    (for_edit) => {
        if (!for_edit) return

        transaction.value = {
            id: for_edit.id,
            at: toLocalDateStr(for_edit.at),
            amount: for_edit.amount,
            note: for_edit.note,
            tags: for_edit.tags.map((tag) => tag.id),
        }
    },
)

const handleSubmit = async () => {
    const ok = await transactionsStore.update(transaction.value)

    if (!ok) return

    transactionsStore.transactionForEdit = null
    transactions_edit_modal.close()
}

const handleDelete = async (id) => {
    const ok = await transactionsStore.delete(id)

    if (!ok) return

    transactionsStore.transactionForEdit = null
    transactions_edit_modal.close()
}
</script>

<style scoped></style>
