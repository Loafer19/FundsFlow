<template>
    <div class="overflow-x-auto">
        <table class="table w-full">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Amount</th>
                    <th>Note</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(transaction, index) in transactions" :key="index">
                    <td>{{ formatDate(transaction.date) }}</td>
                    <td>{{ formatAmount(transaction.amount) }}</td>
                    <td>{{ transaction.note || '-' }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<script setup>
defineProps({
    transactions: {
        type: Array,
        required: true
    }
})

const formatDate = (date) => {
    return new Date(date).toLocaleDateString()
}

const formatAmount = (amount) => {
    return new Intl.NumberFormat('uk-UA', {
        style: 'currency',
        currency: 'UAH'
    }).format(amount)
}
</script>

<style scoped></style>
