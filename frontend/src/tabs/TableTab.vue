<template>
    <EmptyState v-if="!filteredTransactions.length" icon="📋" title="No transactions in this period"
        description="Add a transaction or pick another date range." action-label="Add Transaction"
        @action="openAdd" />

    <div v-else class="card card-border border-base-300 bg-base-100">
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th></th>
                        <th @click="sortBy('at')" class="cursor-pointer">
                            Date
                            <span v-if="sortConfig.key === 'at'">
                                {{ sortConfig.direction === 'asc' ? '↑' : '↓' }}
                            </span>
                        </th>
                        <th @click="sortBy('amount')" class="cursor-pointer">
                            Amount
                            <span v-if="sortConfig.key === 'amount'">
                                {{ sortConfig.direction === 'asc' ? '↑' : '↓' }}
                            </span>
                        </th>
                        <th>Tags</th>
                        <th>Note</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="transaction in filteredTransactions" :key="transaction.id">
                        <td>
                            <span class="tooltip tooltip-right" :data-tip="sourceLabel(transaction.source)">
                                <component :is="sourceIcon(transaction.source)" :size="18" />
                            </span>
                        </td>
                        <td class="min-w-18">{{ formatDate(transaction.at) }}</td>
                        <td>
                            <div class="badge badge-outline font-semibold"
                                :class="[transaction.amount > 0 ? 'badge-success' : 'badge-error']">
                                {{ transaction.amount > 0 ? '+' : '' }}{{ formatMoney(transaction.amount) }}
                            </div>
                        </td>
                        <td>
                            <div class="flex flex-wrap gap-2">
                                <div v-for="tag in transaction.tags" :key="tag.id"
                                    class="badge badge-soft badge-info px-1 gap-1">
                                    <span>{{ tag.emoji }}</span><span>{{ tag.title }}</span>
                                </div>
                            </div>
                        </td>
                        <td>{{ transaction.note || '-' }}</td>
                        <td class="flex gap-1">
                            <button v-if="transactionsStore.isLoading == transaction.id"
                                class="btn btn-outline btn-error btn-square btn-sm" disabled>
                                <span class="loading loading-spinner text-error"></span>
                            </button>
                            <button v-else type="button" class="btn btn-outline btn-secondary btn-square btn-sm"
                                @click="editTransaction(transaction)" :disabled="transactionsStore.isLoading">
                                <Pencil :size="24" />
                            </button>

                            <DeleteHold :id="transaction.id" :disabled="transactionsStore.isLoading"
                                :isLoading="transactionsStore.isLoading == transaction.id"
                                @delete="(id) => transactionsStore.delete(id)" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<script setup>
import { Laptop, Pencil, Repeat, Send } from 'lucide-vue-next'
import { computed, inject, ref } from 'vue'
import DeleteHold from '../components/buttons/DeleteHold.vue'
import EmptyState from '../components/EmptyState.vue'
import { getTransactionSourceLabel } from '../services/formatters'
import { useTransactionsStore } from '../services/transactions'

const formatMoney = inject('formatMoney')
const formatDate = inject('formatDate')
const sourceLabel = getTransactionSourceLabel

const sourceIcons = { web: Laptop, telegram: Send, recurring: Repeat }
const sourceIcon = (source) => sourceIcons[source] ?? sourceIcons.web

const transactionsStore = useTransactionsStore()

const props = defineProps({
    dateRange: {
        type: Object,
        required: true,
    },
})

const sortConfig = ref({
    key: 'at',
    direction: 'desc',
})

const sortBy = (key) => {
    if (sortConfig.value.key === key) {
        sortConfig.value.direction = sortConfig.value.direction === 'asc' ? 'desc' : 'asc'
    } else {
        sortConfig.value.key = key
        sortConfig.value.direction = 'desc'
    }
}

const filteredTransactions = computed(() =>
    transactionsStore.filteredByDateRange(props.dateRange.currentStart, props.dateRange.currentEnd).sort((a, b) => {
        return transactionsStore.sort(a, b, sortConfig.value.key, sortConfig.value.direction)
    }),
)

const openAdd = () => transactions_add_modal.showModal()

const editTransaction = (transaction) => {
    transactionsStore.transactionForEdit = transaction
    transactions_edit_modal.showModal()
}
</script>
