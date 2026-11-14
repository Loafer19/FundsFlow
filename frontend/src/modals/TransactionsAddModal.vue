<template>
    <dialog id="transactions_add_modal" class="modal" aria-labelledby="transactions_add_modal_title">
        <div class="modal-box max-w-sm">
            <ModalHeader title-id="transactions_add_modal_title" :icon="ArrowLeftRight">
                Add Transaction
            </ModalHeader>

            <form ref="formRef" @submit.prevent="handleSubmit">
                <TypeChips :model-value="transaction.transaction_type_id" @pick="typed.pickType" />

                <DateChips v-model="transaction.at" />

                <AmountField ref="amountField" v-model="transaction.amount" :linked="typed.isLinked.value"
                    @manual="typed.onManualAmount">
                    <template #hint>
                        <TypeAmountHint :hint="typed.hint.value" @reset="typed.resetAmount" />
                    </template>
                </AmountField>

                <TypeFieldsCard :type="typed.type.value" v-model:data="transaction.data" />

                <input type="text" v-model="transaction.note" placeholder="Note (optional)" aria-label="Note"
                    class="input w-full mb-4" maxlength="255" />

                <TagPicker v-model="transaction.tags" />
                <p v-if="defaultTagNote" class="text-xs text-base-content/60 -mt-3 mb-4">{{ defaultTagNote }}</p>

                <AttachmentField v-model:pending="pendingFiles" :busy="Boolean(transactionsStore.isLoading)" />

                <div class="modal-action">
                    <button type="submit" class="btn btn-primary tg-primary" :disabled="transactionsStore.isLoading">
                        <span v-if="transactionsStore.isLoading" class="loading loading-spinner"></span>
                        <Save v-else :size="20" />
                        Save
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
import { ArrowLeftRight, Save } from '@lucide/vue'
import { computed, ref, watch } from 'vue'
import AmountField from '../components/AmountField.vue'
import AttachmentField from '../components/AttachmentField.vue'
import DateChips from '../components/DateChips.vue'
import ModalHeader from '../components/ModalHeader.vue'
import TagPicker from '../components/TagPicker.vue'
import TypeAmountHint from '../components/TypeAmountHint.vue'
import TypeChips from '../components/TypeChips.vue'
import TypeFieldsCard from '../components/TypeFieldsCard.vue'
import { today } from '../services/clock.js'
import { useTelegramPrimary } from '../services/telegramChrome.js'
import { useTransactionsStore } from '../services/transactions.js'
import { useTransactionTypesStore } from '../services/transactionTypes.js'
import { useTypedTransaction } from '../services/typedTransaction.js'

const transactionsStore = useTransactionsStore()
const typesStore = useTransactionTypesStore()

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
    transaction_type_id: null,
    data: {},
})

const transaction = ref(createDefault())
const pendingFiles = ref([])
const amountField = ref(null)
const typed = useTypedTransaction(transaction, amountField)

const defaultTagNote = computed(() => {
    const type = typed.type.value
    const ids = (type?.default_tag_ids ?? []).filter((id) => transaction.value.tags.includes(id))
    if (!ids.length) return ''
    return `Default tag${ids.length > 1 ? 's' : ''} from ${type.name}`
})

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
    const payload = typed.payload(transaction.value)
    // The form already shows the type's default tags as removable picks.
    if (payload.transaction_type_id) payload.default_tags = false
    const ok = await transactionsStore.create(payload, pendingFiles.value)

    if (!ok) return
    if (payload.add_options) typesStore.fetch()

    transaction.value = createDefault()
    typed.load()
    pendingFiles.value = []
    transactions_add_modal.close()
}
</script>

<style scoped></style>
