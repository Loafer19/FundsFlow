<template>
    <dialog id="transactions_add_modal" class="modal">
        <div class="modal-box max-w-sm">
            <h2 class="card-title mb-4">Add Transaction</h2>

            <form @submit.prevent="handleSubmit">
                <input type="date" v-model="transaction.at" class="input w-full mb-4" required />

                <AmountField v-model="transaction.amount" />

                <div class="tags-tree flex flex-col justify-start gap-1 mb-4" v-if="tagsStore.tags.length">
                    <button v-for="tag in tagsStore.list()" :key="tag.id" type="button" @click="toggleTag(tag.id)"
                        class="badge badge-info gap-0 px-1 text-lg cursor-pointer" :class="{
                            'badge-soft': !transaction.tags.includes(tag.id),
                        }" :style="{ marginLeft: `${tag.depth * 20}px` }">
                        <span>{{ tag.emoji }}</span>
                        <span>{{ tag.title }}</span>
                    </button>
                </div>

                <input type="text" v-model="transaction.note" class="input w-full" placeholder="Note" maxlength="255" />

                <div class="modal-action">
                    <button type="submit" class="btn btn-success" :disabled="transactionsStore.isLoading">
                        <span v-if="transactionsStore.isLoading" class="loading loading-spinner"></span>
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
import { ref } from 'vue'
import AmountField from '../components/AmountField.vue'
import { toLocalDateStr } from '../services/formatters.js'
import { useTagsStore } from '../services/tags.js'
import { useTransactionsStore } from '../services/transactions.js'

const tagsStore = useTagsStore()
const transactionsStore = useTransactionsStore()

const createDefault = () => ({
    at: toLocalDateStr(new Date()),
    amount: '',
    note: '',
    tags: [],
})

const transaction = ref(createDefault())

const toggleTag = (id) => {
    const index = transaction.value.tags.indexOf(id)

    if (index === -1) {
        transaction.value.tags.push(id)
    } else {
        transaction.value.tags.splice(index, 1)
    }
}

const handleSubmit = async () => {
    const ok = await transactionsStore.create(transaction.value)

    if (!ok) return

    transaction.value = createDefault()
    transactions_add_modal.close()
}
</script>

<style scoped>
.tags-tree {
    max-height: 45vh;
    overflow-y: auto;
}

.badge {
    transition: all 0.2s ease;
}

.badge:hover {
    opacity: 0.8;
}
</style>
