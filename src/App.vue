<template>
    <Toasts />

    <div class="container mx-auto p-4">
        <TransactionAddModal @transaction-new="transactionAdd" />

        <div class="mb-4">
            <button class="btn btn-primary" onclick="transaction_add_modal.showModal()">
                Add Transaction
            </button>
        </div>

        <TransactionListTable :transactions @transaction-remove="transactionDelete" />
    </div>
</template>

<script setup>
import { ref, inject, onMounted } from 'vue'
import { DB_Transactions_All, DB_Transactions_Add, DB_Transactions_Delete } from './services/db.js'
import TransactionAddModal from './modals/TransactionAddModal.vue'
import TransactionListTable from './tables/TransactionListTable.vue'
import Toasts from './components/Toasts.vue'

const transactions = ref([])
const toasts = inject('toasts')

onMounted(async () => {
    DB_Transactions_All()
        .then(loadedTransactions => {
            transactions.value = loadedTransactions
        })
        .catch(error => {
            toasts.push({
                message: 'Transaction loading failed: ' + error.message,
                type: 'error'
            })
        })
})

const transactionAdd = async (transaction) => {
    DB_Transactions_Add(transaction)
        .then(id => {
            transactions.value.push({ ...transaction, id })

            toasts.push({
                message: 'Transaction added successfully!',
            })
        })
        .catch(error => {
            toasts.push({
                message: 'Transaction adding failed: ' + error.message,
                type: 'error'
            })
        })
}

const transactionDelete = (id) => {
    DB_Transactions_Delete(id)
        .then(() => {
            transactions.value = transactions.value.filter(transaction => transaction.id !== id)

            toasts.push({
                message: 'Transaction deleted successfully!',
            })
        })
        .catch(error => {
            toasts.push({
                message: 'Transaction deleting failed: ' + error.message,
                type: 'error'
            })
        })

}
</script>

<style scoped></style>
