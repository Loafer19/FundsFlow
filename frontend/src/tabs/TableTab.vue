<template>
    <EmptyState v-if="!dateRangeTransactions.length" icon="📋" title="No transactions in this period"
        description="Nothing in this period. Add a transaction or pick another date range"
        action-label="Add Transaction" @action="openAdd" />

    <template v-else>
        <div class="flex flex-wrap items-center gap-2 mb-3">
            <label class="input input-sm w-full max-w-xs border-base-300 text-sm">
                <Search :size="16" class="text-base-content/60" />
                <input v-model="searchQuery" type="text" placeholder="Search notes" />
            </label>
        </div>

        <EmptyState v-if="!filteredTransactions.length" icon="🔍" title="No transactions match the current filters"
            description="Try a different search term or clear the tag filter (including Untagged)" />

        <div v-else class="card card-border border-base-300 bg-base-100">
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th></th>
                            <th role="button" tabindex="0" class="cursor-pointer"
                                :aria-sort="sortConfig.key === 'at' ? (sortConfig.direction === 'asc' ? 'ascending' : 'descending') : 'none'"
                                @click="sortBy('at')" @keydown.enter.prevent="sortBy('at')"
                                @keydown.space.prevent="sortBy('at')">
                                Date
                                <span v-if="sortConfig.key === 'at'">
                                    {{ sortConfig.direction === 'asc' ? '↑' : '↓' }}
                                </span>
                            </th>
                            <th role="button" tabindex="0" class="cursor-pointer"
                                :aria-sort="sortConfig.key === 'amount' ? (sortConfig.direction === 'asc' ? 'ascending' : 'descending') : 'none'"
                                @click="sortBy('amount')" @keydown.enter.prevent="sortBy('amount')"
                                @keydown.space.prevent="sortBy('amount')">
                                Amount
                                <span v-if="sortConfig.key === 'amount'">
                                    {{ sortConfig.direction === 'asc' ? '↑' : '↓' }}
                                </span>
                            </th>
                            <th>Tags</th>
                            <th>Note</th>
                            <th class="w-0">
                                <span class="sr-only">Files</span>
                                <Paperclip :size="14" aria-hidden="true" />
                            </th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="transaction in filteredTransactions" :key="transaction.id">
                            <td>
                                <span class="tooltip tooltip-right" :data-tip="sourceLabel(transaction.source)"
                                    :aria-label="sourceLabel(transaction.source)">
                                    <component :is="sourceIcon(transaction.source)" :size="18" aria-hidden="true" />
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
                            <td>
                                <span class="truncate">{{ transaction.note || '-' }}</span>
                            </td>
                            <td>
                                <button v-if="transaction.attachments?.length" type="button"
                                    class="btn btn-ghost btn-xs gap-0.5 px-1 items-center"
                                    :aria-label="'Preview ' + transaction.attachments.length + ' attachments'"
                                    @click="previewAttachments(transaction)">
                                    <template v-for="file in transaction.attachments.slice(0, 2)" :key="file.id">
                                        <FileText v-if="file.mime === 'application/pdf'" :size="14"
                                            aria-hidden="true" />
                                        <ImageIcon v-else :size="14" aria-hidden="true" />
                                    </template>
                                    <span v-if="transaction.attachments.length > 2"
                                        class="inline-flex items-center text-[10px] leading-none translate-y-px opacity-70">
                                        +{{ transaction.attachments.length - 2 }}
                                    </span>

                                </button>

                            </td>



                            <td class="flex gap-1">
                                <button v-if="transactionsStore.isLoading == transaction.id"
                                    class="btn btn-outline btn-error btn-square btn-sm" disabled>
                                    <span class="loading loading-spinner text-error"></span>
                                </button>
                                <button v-else type="button" class="btn btn-outline btn-secondary btn-square btn-sm"
                                    aria-label="Edit" @click="editTransaction(transaction)"
                                    :disabled="transactionsStore.isLoading">
                                    <Pencil :size="20" />
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
</template>

<script setup>
import { Bot, FileText, Image as ImageIcon, Laptop, Paperclip, Pencil, Repeat, Search, Send } from 'lucide-vue-next'

import { computed, inject, ref, watch } from 'vue'
import DeleteHold from '../components/buttons/DeleteHold.vue'
import EmptyState from '../components/EmptyState.vue'
import { getTransactionSourceLabel } from '../services/formatters'
import { useTransactionsStore } from '../services/transactions'

const formatMoney = inject('formatMoney')
const formatDate = inject('formatDate')
const sourceLabel = getTransactionSourceLabel

const sourceIcons = { web: Laptop, telegram: Send, recurring: Repeat, mcp: Bot }
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

const searchQuery = ref('')

watch(
    () => transactionsStore.tagFilterDraft,
    () => {
        transactionsStore.setTagFilterFromDraft()
    },
    { immediate: true },
)

const sortBy = (key) => {
    if (sortConfig.value.key === key) {
        sortConfig.value.direction = sortConfig.value.direction === 'asc' ? 'desc' : 'asc'
    } else {
        sortConfig.value.key = key
        sortConfig.value.direction = 'desc'
    }
}

const dateRangeTransactions = computed(() =>
    transactionsStore.filteredByDateRangeAndTags(props.dateRange.currentStart, props.dateRange.currentEnd),
)

const filteredTransactions = computed(() => {
    const query = searchQuery.value.trim().toLowerCase()

    return dateRangeTransactions.value
        .filter((transaction) => !query || transaction.note?.toLowerCase().includes(query))
        .sort((a, b) => transactionsStore.sort(a, b, sortConfig.value.key, sortConfig.value.direction))
})

const openAdd = () => transactions_add_modal.showModal()

const editTransaction = (transaction) => {
    transactionsStore.transactionForEdit = transaction
    transactions_edit_modal.showModal()
}

const previewAttachments = (transaction) => {
    transactionsStore.openAttachmentPreview(transaction)
}
</script>
