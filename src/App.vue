<template>
    <div class="container mx-auto p-4">
        <TransactionAddModal @transaction-new="transactionAdd" />

        <div class="mb-4">
            <button class="btn btn-primary" onclick="transaction_add_modal.showModal()">Add Transaction</button>
        </div>

        <TransactionListTable :transactions />
    </div>
</template>

<script setup>
import { ref } from 'vue'
import TransactionAddModal from './modals/TransactionAddModal.vue'
import TransactionListTable from './tables/TransactionListTable.vue'

const transactions = ref([])

const transactionAdd = (transaction) => {
    transactions.value.push(transaction)
}
</script>

<style scoped></style>
