<template>
    <Toasts />

    <div class="container mx-auto p-4">
        <TransactionAddModal @transaction-new="transactionAdd" />

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div class="card card-border border-base-300 bg-base-100">
                <div class="card-body">
                    <h2 class="card-title">Total Transactions</h2>
                    <p class="text-2xl">{{ transactions.length }}</p>
                </div>
            </div>
            <div class="card card-border border-base-300 bg-base-100">
                <div class="card-body">
                    <h2 class="card-title">Total Amount</h2>
                    <p class="text-2xl">{{ formatMoney(totalAmount) }}</p>
                </div>
            </div>
        </div>

        <TransactionsChart :transactions />

        <TransactionListTable :transactions @transaction-remove="transactionDelete" />
    </div>
</template>

<script setup>
import { ref, inject, onMounted, computed } from 'vue'
import { DB_Transactions_All, DB_Transactions_Add, DB_Transactions_Delete } from './services/db.js'
import TransactionAddModal from './modals/TransactionAddModal.vue'
import TransactionListTable from './tables/TransactionListTable.vue'
import Toasts from './components/Toasts.vue'
import TransactionsChart from './charts/TransactionsChart.vue'

const transactions = ref([])
const toasts = inject('toasts')
const formatMoney = inject('formatMoney')

onMounted(async () => loadTransactions())

const loadTransactions = async () => {
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
}

const transactionAdd = async (transaction) => {
    DB_Transactions_Add(transaction)
        .then(id => {
            loadTransactions()

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

const totalAmount = computed(() => {
    return transactions.value.reduce((sum, t) => sum + Number(t.amount), 0)
})
</script>

<style scoped></style>
