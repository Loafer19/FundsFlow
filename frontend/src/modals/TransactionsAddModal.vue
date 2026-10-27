<template>
    <dialog id="transactions_add_modal" class="modal" aria-labelledby="transactions_add_modal_title">
        <div class="modal-box max-w-sm">
            <h2 id="transactions_add_modal_title" class="card-title mb-4">
                <ArrowLeftRight :size="24" />
                Add Transaction
            </h2>

            <form ref="formRef" @submit.prevent="handleSubmit">
                <DateChips v-model="transaction.at" />

                <AmountField v-model="transaction.amount" />

                <input type="text" v-model="transaction.note" placeholder="Note (optional)" aria-label="Note"
                    class="input w-full mb-4" maxlength="255" />

                <TagPicker v-model="transaction.tags" />

                <AttachmentField v-model:pending="pendingFiles" :busy="Boolean(transactionsStore.isLoading)" />

                <div class="modal-action">
                    <button type="submit" class="btn btn-success tg-primary" :disabled="transactionsStore.isLoading">
                        <span v-if="transactionsStore.isLoading" class="loading loading-spinner"></span>
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
import { ArrowLeftRight, Save } from 'lucide-vue-next'
import { ref, watch } from 'vue'
import AmountField from '../components/AmountField.vue'
import AttachmentField from '../components/AttachmentField.vue'
import DateChips from '../components/DateChips.vue'
import TagPicker from '../components/TagPicker.vue'
import { today } from '../services/clock.js'
import { useTelegramPrimary } from '../services/telegramChrome.js'
import { useTransactionsStore } from '../services/transactions.js'

const transactionsStore = useTransactionsStore()

const formRef = ref(null)

useTelegramPrimary('transactions_add_modal', {
    text: 'Save',
    form: formRef,
    busy: () => Boolean(transactionsStore.isLoading),
})

const createDefault = () => ({
    at: today.value,

    amount: '',
    note: '',
    tags: [],
})

const transaction = ref(createDefault())
const pendingFiles = ref([])

// An untouched "Today" default follows the calendar; a picked date stays put.
watch(today, (now, before) => {
    if (transaction.value.at === before) transaction.value.at = now
})

watch(
    () => transactionsStore.transactionDraftAt,
    (date) => {
        if (!date) return

        transaction.value.at = date
        transactionsStore.transactionDraftAt = null
    },
)

const handleSubmit = async () => {
    const ok = await transactionsStore.create(transaction.value, pendingFiles.value)

    if (!ok) return

    transaction.value = createDefault()
    pendingFiles.value = []
    transactions_add_modal.close()
}
</script>

<style scoped></style>
