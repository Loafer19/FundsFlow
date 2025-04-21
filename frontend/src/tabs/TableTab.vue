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
                        <td class="flex gap-1">
                            <button v-if="transactionsStore.isLoading == transaction.id"
                                class="btn btn-outline btn-error btn-square btn-sm" disabled>
                                <span class="loading loading-spinner text-error"></span>
                            </button>
                            <button v-else type="button" class="btn btn-outline btn-secondary btn-square btn-sm"
                                @click="transactionsStore.transactionForEdit = transaction"
                                onclick="transactions_edit_modal.showModal()" :disabled="transactionsStore.isLoading">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"
                                    xmlns="http://www.w3.org/2000/svg">
                                    <path fill-rule="evenodd" clip-rule="evenodd"
                                        d="M14.7566 2.62145C16.5852 0.792851 19.55 0.792851 21.3786 2.62145C23.2072 4.45005 23.2072 7.41479 21.3786 9.24339L11.8933 18.7287C11.3514 19.2706 11.0323 19.5897 10.6774 19.8665C10.2592 20.1927 9.80655 20.4725 9.32766 20.7007C8.92136 20.8943 8.49334 21.037 7.76623 21.2793L4.43511 22.3897L3.63303 22.6571C2.98247 22.8739 2.26522 22.7046 1.78032 22.2197C1.29542 21.7348 1.1261 21.0175 1.34296 20.367L2.72068 16.2338C2.96303 15.5067 3.10568 15.0787 3.29932 14.6724C3.52755 14.1935 3.80727 13.7409 4.13354 13.3226C4.41035 12.9677 4.72939 12.6487 5.27137 12.1067L14.7566 2.62145ZM4.40051 20.8201L7.24203 19.8729C8.03314 19.6092 8.36927 19.4958 8.68233 19.3466C9.06287 19.1653 9.42252 18.943 9.75492 18.6837C10.0284 18.4704 10.2801 18.2205 10.8698 17.6308L18.4393 10.0614C17.6506 9.78321 16.6346 9.26763 15.6835 8.31651C14.7324 7.36538 14.2168 6.34939 13.9387 5.56075L6.36917 13.1302C5.77951 13.7199 5.52959 13.9716 5.3163 14.2451C5.05704 14.5775 4.83476 14.9371 4.65341 15.3177C4.50421 15.6307 4.3908 15.9669 4.12709 16.758L3.17992 19.5995L4.40051 20.8201ZM15.1554 4.34404C15.1896 4.519 15.2474 4.75684 15.3438 5.03487C15.561 5.66083 15.9712 6.48288 16.7442 7.25585C17.5171 8.02881 18.3392 8.43903 18.9651 8.6562C19.2432 8.75266 19.481 8.81046 19.656 8.84466L20.3179 8.18272C21.5607 6.93991 21.5607 4.92492 20.3179 3.68211C19.0751 2.4393 17.0601 2.4393 15.8173 3.68211L15.1554 4.34404Z" />
                                </svg>
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
})

const filteredTransactions = computed(() =>
    transactionsStore
        .filteredByDateRange(props.dateRange.currentStart, props.dateRange.currentEnd)
        .sort((a, b) => new Date(b.at) - new Date(a.at)),
)
</script>

<style scoped></style>
