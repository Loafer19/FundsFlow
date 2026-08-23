<template>
    <dialog id="budgets_add_modal" class="modal">
        <div class="modal-box max-w-sm">
            <h2 class="card-title mb-4">
                <PiggyBank :size="24" />
                Add Budget
            </h2>

            <form @submit.prevent="handleSubmit">
                <input type="text" v-model="form.title" placeholder="Title (optional)" class="input w-full mb-4"
                    maxlength="255" autofocus />

                <input type="number" step="0.01" min="0.01" v-model="form.amount" placeholder="Amount"
                    class="input w-full mb-4" required />

                <select v-model="form.length" class="select w-full mb-4" required>
                    <option value="week">Weekly</option>
                    <option value="month">Monthly</option>
                    <option value="year">Yearly</option>
                </select>

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
import { ref } from 'vue'
import TagPicker from '../components/TagPicker.vue'
import { useBudgetsStore } from '../services/budgets.js'

const budgetsStore = useBudgetsStore()

const createDefault = () => ({
    title: '',
    amount: '',
    length: 'month',
    tag_ids: [],
})

const form = ref(createDefault())

const handleSubmit = async () => {
    const ok = await budgetsStore.create(form.value)

    if (!ok) return

    form.value = createDefault()
    budgets_add_modal.close()
}
</script>

<style scoped></style>
