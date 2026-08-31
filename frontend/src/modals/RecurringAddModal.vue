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

                <select v-model="form.frequency" class="select w-full mb-4" required aria-label="Recurring frequency">
                    <option value="daily">Daily</option>
                    <option value="weekly">Weekly</option>
                    <option value="monthly">Monthly</option>
                    <option value="yearly">Yearly</option>
                </select>

                <div class="text-sm text-base-content/60 mb-1">Starts</div>
                <input type="date" v-model="form.starts_at" class="input w-full mb-4" required />

                <div class="text-sm text-base-content/60 mb-1">Ends</div>
                <div class="join w-full mb-1">
                    <button type="button" class="btn join-item flex-1" :class="{ 'btn-active': endMode === 'none' }"
                        @click="endMode = 'none'">No end</button>
                    <button type="button" class="btn join-item flex-1" :class="{ 'btn-active': endMode === 'count' }"
                        @click="endMode = 'count'">Times</button>
                    <button type="button" class="btn join-item flex-1" :class="{ 'btn-active': endMode === 'date' }"
                        @click="endMode = 'date'">Date</button>
                </div>

                <template v-if="endMode === 'count'">
                    <input type="number" min="1" v-model="occurrences" placeholder="Number of times"
                        class="input w-full" />
                    <div class="text-sm text-base-content/60 mb-4 mt-1">
                        <template v-if="computedEndsAt">Ends around {{ formatDate(computedEndsAt) }}</template>
                    </div>
                </template>

                <template v-else-if="endMode === 'date'">
                    <input type="date" v-model="form.ends_at" class="input w-full" />
                    <div class="text-sm text-base-content/60 mb-4 mt-1">
                        <template v-if="form.ends_at">~{{ estimatedOccurrences }} occurrence{{ estimatedOccurrences ===
                            1 ? '' : 's' }}</template>
                    </div>
                </template>

                <div v-else class="mb-4"></div>

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
import { computed, ref } from 'vue'
import AmountField from '../components/AmountField.vue'
import TagPicker from '../components/TagPicker.vue'
import { formatDate, toLocalDateStr } from '../services/formatters.js'
import { advanceDateStr } from '../services/recurringSchedule.js'
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
const endMode = ref('none')
const occurrences = ref('')

// "N times" is UI sugar over ends_at: the Nth occurrence's own date, so
// it still fires (backend deactivates once next_run_at exceeds ends_at).
const computedEndsAt = computed(() => {
    const count = Number(occurrences.value)

    if (!count || count < 1) return null

    return advanceDateStr(form.value.starts_at, form.value.frequency, count - 1)
})

const estimatedOccurrences = computed(() => {
    if (!form.value.ends_at) return 0

    let count = 1
    let cursor = form.value.starts_at

    while (count < 1000) {
        const next = advanceDateStr(cursor, form.value.frequency, 1)

        if (next > form.value.ends_at) break

        cursor = next
        count++
    }

    return count
})

const handleSubmit = async () => {
    const endsAt =
        endMode.value === 'count' ? computedEndsAt.value : endMode.value === 'date' ? form.value.ends_at || null : null

    const ok = await recurringStore.create({ ...form.value, ends_at: endsAt })

    if (!ok) return

    form.value = createDefault()
    endMode.value = 'none'
    occurrences.value = ''
    recurring_add_modal.close()
}
</script>

<style scoped></style>
