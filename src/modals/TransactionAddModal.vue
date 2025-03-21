<template>
    <dialog id="transaction_add_modal" class="modal">
        <div class="modal-box max-w-sm">
            <h3 class="mb-4 text-lg font-bold">Add Transaction</h3>

            <form @submit.prevent="submitTransaction">
                <input id="transaction.date" type="date" v-model="transaction.date" class="input w-full mb-4"
                    required />

                <input id="transaction.amount" type="number" v-model="transaction.amount" class="input w-full mb-4"
                    step="0.01" placeholder="Amount" required />

                <input id="transaction.note" type="text" v-model="transaction.note" class="input w-full"
                    placeholder="Note" />

                <div class="modal-action mt-4">
                    <button type="submit" class="btn btn-success">Save</button>
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

const emit = defineEmits(['transaction-new'])

const transaction = ref({
    date: new Date().toISOString().split('T')[0],
    amount: '',
    note: ''
})

const submitTransaction = () => {
    emit('transaction-new', { ...transaction.value })

    transaction.value = {
        date: new Date().toISOString().split('T')[0],
        amount: '',
        note: ''
    }

    transaction_add_modal.close()
}
</script>

<style scoped></style>
