<template>
    <dialog id="budgets_add_modal" class="modal" aria-labelledby="budgets_add_modal_title">
        <div class="modal-box max-w-sm">
            <h2 id="budgets_add_modal_title" class="card-title mb-4">
                <PiggyBank :size="24" />
                Add Budget
            </h2>

            <form @submit.prevent="handleSubmit">
                <input type="text" v-model="form.title" placeholder="Title (optional)" aria-label="Title"
                    class="input w-full mb-4" maxlength="255" autofocus />

                <input type="number" step="0.01" min="0.01" v-model="form.amount" placeholder="Amount"
                    aria-label="Amount" class="input w-full mb-4" required />

                <select v-model="form.length" class="select w-full mb-4" required aria-label="Budget length">
                    <option value="week">Weekly</option>
                    <option value="month">Monthly</option>
                    <option value="year">Yearly</option>
                </select>

                <label class="label cursor-pointer justify-start gap-2 mb-4">
                    <input type="checkbox" v-model="form.align_to_calendar" class="checkbox checkbox-sm" />
                    <span class="label-text">Start from the beginning of this {{ lengthNoun }}</span>
                </label>

                <TagPicker v-model="form.tag_ids" />

                <div class="modal-action">
                    <button type="submit" class="btn btn-success"
                        :disabled="budgetsStore.isLoading || !form.tag_ids.length">
                        <span v-if="budgetsStore.isLoading" class="loading loading-spinner"></span>
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
import { PiggyBank, Save } from 'lucide-vue-next'
import { computed, ref } from 'vue'
import TagPicker from '../components/TagPicker.vue'
import { useBudgetsStore } from '../services/budgets.js'

const budgetsStore = useBudgetsStore()

const createDefault = () => ({
    title: '',
    amount: '',
    length: 'month',
    align_to_calendar: false,
    tag_ids: [],
})

const form = ref(createDefault())

const lengthNoun = computed(() => ({ week: 'week', month: 'month', year: 'year' })[form.value.length])

const handleSubmit = async () => {
    const ok = await budgetsStore.create(form.value)

    if (!ok) return

    form.value = createDefault()
    budgets_add_modal.close()
}
</script>

<style scoped></style>
