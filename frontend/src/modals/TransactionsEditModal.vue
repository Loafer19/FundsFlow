<template>
    <dialog id="transactions_edit_modal" class="modal" aria-labelledby="transactions_edit_modal_title">
        <div class="modal-box max-w-sm">
            <h2 id="transactions_edit_modal_title" class="card-title mb-4">Edit Transaction</h2>

            <form @submit.prevent="handleSubmit">
                <input type="date" v-model="transaction.at" class="input w-full mb-4" required />

                <AmountField v-model="transaction.amount" />

                <TagPicker v-model="transaction.tags" />

                <input type="text" v-model="transaction.note" class="input w-full mb-4" placeholder="Note"
                    aria-label="Note" maxlength="255" />

                <AttachmentField :transaction-id="transaction.id" :attachments="liveAttachments"
                    :busy="Boolean(transactionsStore.isLoading)" @changed="syncAttachments" />

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
import { computed, ref, watch } from 'vue'
import AmountField from '../components/AmountField.vue'
import AttachmentField from '../components/AttachmentField.vue'
import DeleteHold from '../components/buttons/DeleteHold.vue'
import TagPicker from '../components/TagPicker.vue'
import { toLocalDateStr } from '../services/formatters.js'
import { useTransactionsStore } from '../services/transactions.js'

const transactionsStore = useTransactionsStore()

const transaction = ref({
    tags: [],
})

const liveAttachments = computed(() => {
    if (!transaction.value.id) return []

    const current = transactionsStore.transactions.find((t) => t.id === transaction.value.id)

    return current?.attachments ?? []
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

const syncAttachments = () => {
    const current = transactionsStore.transactions.find((t) => t.id === transaction.value.id)

    if (current && transactionsStore.transactionForEdit?.id === current.id) {
        transactionsStore.transactionForEdit = current
    }
}

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
