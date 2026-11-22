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

                <TypeChips :model-value="form.transaction_type_id" @pick="pickType" />
                <TypeFieldsCard v-if="typedType" v-model:data="form.data" :type="typedType" :auto-fields="autoFields" />
                <p v-if="defaultTagNote" class="text-xs text-base-content/60 -mt-3 mb-4">{{ defaultTagNote }}</p>

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
import { Repeat, Save } from '@lucide/vue'
import { computed, ref, watch } from 'vue'
import AmountField from '../components/AmountField.vue'
import DeleteHold from '../components/buttons/DeleteHold.vue'
import DateField from '../components/DateField.vue'
import ModalHeader from '../components/ModalHeader.vue'
import SegmentedControl from '../components/SegmentedControl.vue'
import TagPicker from '../components/TagPicker.vue'
import TypeChips from '../components/TypeChips.vue'
import TypeFieldsCard from '../components/TypeFieldsCard.vue'
import { applyScheduleData, scheduleAutoFields } from '../services/recurringTotals.js'
import { useRecurringTransactionsStore } from '../services/recurringTransactions.js'
import { useTelegramPrimary } from '../services/telegramChrome.js'
import { useTransactionTypesStore } from '../services/transactionTypes.js'
import { useTypedTransaction } from '../services/typedTransaction.js'

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

const form = ref({ tags: [], data: {}, transaction_type_id: null })
const typesStore = useTransactionTypesStore()
const amountField = ref(null)
const typed = useTypedTransaction(form, amountField)
const typedType = computed(() => typed.type.value)
const nextChargeDate = computed(() => form.value.next_run_at || form.value.starts_at)
const autoFields = computed(() => scheduleAutoFields(typedType.value, form.value.frequency, nextChargeDate.value))
const defaultTagNote = computed(() => {
    const type = typedType.value
    const ids = (type?.default_tag_ids ?? []).filter((id) => form.value.tags.includes(id))
    if (!ids.length) return ''
    return `Default tag${ids.length > 1 ? 's' : ''} from ${type.name}`
})
const pickType = (id) => {
    typed.pickType(id)
    form.value.data = applyScheduleData(typedType.value, form.value.data, form.value.frequency, nextChargeDate.value)
}

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
            next_run_at: rule.next_run_at,
            ends_at: rule.ends_at ?? '',
            active: rule.active,
            tags: rule.tags.map((tag) => tag.id),
            transaction_type_id: rule.transaction_type_id ?? null,
            data: { ...(rule.data || {}) },
        }
        typed.load({ amount: rule.amount })
        form.value.data = applyScheduleData(
            typedType.value,
            form.value.data,
            form.value.frequency,
            form.value.next_run_at,
        )
    },
)

watch(
    () => [form.value.frequency, form.value.next_run_at, form.value.transaction_type_id],
    () => {
        if (!typedType.value) return
        form.value.data = applyScheduleData(
            typedType.value,
            form.value.data,
            form.value.frequency,
            nextChargeDate.value,
        )
    },
)

const handleSubmit = async () => {
    const payload = typed.payload({ ...form.value, ends_at: form.value.ends_at || null })
    if (payload.transaction_type_id) payload.default_tags = false
    const ok = await recurringStore.update(form.value.id, payload)
    if (ok && payload.add_options) typesStore.fetch()

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
