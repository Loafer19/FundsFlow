<template>
    <dialog id="transactions_edit_modal" class="modal" aria-labelledby="transactions_edit_modal_title">
        <div class="modal-box max-w-sm">
            <ModalHeader title-id="transactions_edit_modal_title" :icon="ArrowLeftRight">
                Edit Transaction
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

                <div v-if="transaction.location" class="mb-4">
                    <span class="text-sm text-base-content/60">Place</span>
                    <div class="mt-1 flex items-center gap-2 rounded-lg border border-base-300 bg-base-200/40 px-2 py-1.5">
                        <MapPin :size="16" class="shrink-0 text-base-content/60" />
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm">{{ transaction.location.title || 'Pinned location' }}</div>
                            <div class="truncate text-[10px] text-base-content/50">
                                {{ transaction.location.address || coords(transaction.location) }}
                            </div>
                        </div>
                        <div class="tooltip tooltip-left" data-tip="Open in Google Maps">
                            <a :href="mapUrl(transaction.location)" target="_blank" rel="noopener"
                                class="btn btn-ghost btn-xs btn-square" aria-label="Open in Google Maps">
                                <ExternalLink :size="14" />
                            </a>
                        </div>
                        <div class="tooltip tooltip-left" data-tip="Remove place">
                            <button type="button" class="btn btn-ghost btn-xs btn-square text-error"
                                :disabled="Boolean(transactionsStore.isLoading)" aria-label="Remove place"
                                @click="removeLocation">
                                <X :size="14" />
                            </button>
                        </div>
                    </div>
                </div>

                <AttachmentField :transaction-id="transaction.id" :attachments="liveAttachments"
                    :busy="Boolean(transactionsStore.isLoading)" />

                <div class="modal-action">
                    <DeleteHold v-if="transaction.id" :id="transaction.id" :disabled="transactionsStore.isLoading"
                        :isLoading="transactionsStore.isLoading === transaction.id" @delete="handleDelete" />

                    <button type="submit" class="btn btn-primary tg-primary" :disabled="transactionsStore.isLoading">
                        <span v-if="transactionsStore.isLoading" class="loading loading-spinner"></span>
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
import { ArrowLeftRight, ExternalLink, MapPin, Save, X } from '@lucide/vue'
import { computed, ref, watch } from 'vue'
import AmountField from '../components/AmountField.vue'
import AttachmentField from '../components/AttachmentField.vue'
import DeleteHold from '../components/buttons/DeleteHold.vue'
import DateChips from '../components/DateChips.vue'
import ModalHeader from '../components/ModalHeader.vue'
import TagPicker from '../components/TagPicker.vue'
import TypeAmountHint from '../components/TypeAmountHint.vue'
import TypeChips from '../components/TypeChips.vue'
import TypeFieldsCard from '../components/TypeFieldsCard.vue'
import { toLocalDateStr } from '../services/formatters.js'
import { useTelegramPrimary } from '../services/telegramChrome.js'
import { useTransactionsStore } from '../services/transactions.js'
import { useTransactionTypesStore } from '../services/transactionTypes.js'
import { useTypedTransaction } from '../services/typedTransaction.js'

const transactionsStore = useTransactionsStore()
const typesStore = useTransactionTypesStore()

const formRef = ref(null)

useTelegramPrimary('transactions_edit_modal', {
    text: 'Update',
    form: formRef,
    busy: () => Boolean(transactionsStore.isLoading),
})

const transaction = ref({
    tags: [],
    transaction_type_id: null,
    data: {},
})
const amountField = ref(null)
const typed = useTypedTransaction(transaction, amountField)

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
            transaction_type_id: for_edit.transaction_type_id ?? null,
            data: { ...(for_edit.data ?? {}) },
            location: for_edit.location ?? null,
        }
        locationRemoved.value = false
        typed.load({ amount: for_edit.amount })
    },
)

// Set from Telegram (a location sent as a reply to the card); here it can only be removed.
const locationRemoved = ref(false)
const coords = (location) => `${Number(location.lat).toFixed(5)}, ${Number(location.lng).toFixed(5)}`
const mapUrl = (location) => `https://www.google.com/maps/search/?api=1&query=${location.lat},${location.lng}`
const removeLocation = () => {
    transaction.value.location = null
    locationRemoved.value = true
}

const handleSubmit = async () => {
    const payload = typed.payload(transaction.value)
    if (locationRemoved.value) payload.location = null
    else delete payload.location
    const ok = await transactionsStore.update(payload)

    if (!ok) return
    if (payload.add_options) typesStore.fetch()

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
