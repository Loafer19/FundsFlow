<template>
    <div class="card card-border border-base-300 bg-base-100">
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Tags</th>
                        <th>Note</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="transaction in filteredTransactions" :key="transaction.id">
                        <td>{{ formatDate(transaction.at) }}</td>
                        <td>
                            <div class="badge badge-outline"
                                :class="[transaction.amount > 0 ? 'badge-success' : 'badge-error']">
                                {{ formatMoney(transaction.amount) }}
                            </div>
                        </td>
                        <td>
                            <div class="flex flex-wrap gap-2">
                                <div v-for="tag in transaction.tags" class="badge badge-soft badge-info px-1 gap-1">
                                    <span>{{ tag.emoji }}</span><span>{{ tag.title }}</span>
                                </div>
                            </div>
                        </td>
                        <td>{{ transaction.note || '-' }}</td>
                        <td>
                            <DeleteHold
                                :id="transaction.id"
                                :disabled="transactionsStore.isLoading"
                                :isLoading="transactionsStore.isLoading == transaction.id"
                                @delete="(id) => transactionsStore.delete(id)"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<script setup>
import { computed, inject } from 'vue'
import DeleteHold from '../components/buttons/DeleteHold.vue'
import { useTransactionsStore } from '../services/transactions'

const formatMoney = inject('formatMoney')
const formatDate = inject('formatDate')

const transactionsStore = useTransactionsStore()

const props = defineProps({
    dateRange: {
        type: Object,
        required: true,
    },
    transactions: {
        type: Array,
        required: true,
    },
})

const filteredTransactions = computed(() => {
    return props.transactions
        .filter((t) => {
            const date = new Date(t.at)
            return date >= props.dateRange.currentStart && date < props.dateRange.currentEnd
        })
        .reverse()
})
</script>

<style scoped>
</style>
