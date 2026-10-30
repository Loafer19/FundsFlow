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

            <span v-if="listDay" class="badge badge-info gap-1 pl-2 pr-0.5">
                <CalendarDays :size="12" aria-hidden="true" />
                {{ formatDate(parseLocalDate(listDay)) }}
                <button type="button" class="btn btn-ghost btn-xs btn-circle" aria-label="Show the whole period"
                    @click="listDay = null">
                    <X :size="12" />
                </button>
            </span>
        </div>

        <EmptyState v-if="!filteredTransactions.length" icon="🔍" title="No transactions match the current filters"
            :description="listDay ? 'Try a different search term, clear the tag filter or show the whole period' : 'Try a different search term or clear the tag filter (including Untagged)'" />

        <div v-else class="card card-border border-base-300 bg-base-100 overflow-hidden">
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
                                <span v-if="sortConfig.key === 'at'" class="report-hide">
                                    {{ sortConfig.direction === 'asc' ? '↑' : '↓' }}
                                </span>
                            </th>
                            <th role="button" tabindex="0" class="cursor-pointer"
                                :aria-sort="sortConfig.key === 'amount' ? (sortConfig.direction === 'asc' ? 'ascending' : 'descending') : 'none'"
                                @click="sortBy('amount')" @keydown.enter.prevent="sortBy('amount')"
                                @keydown.space.prevent="sortBy('amount')">
                                Amount
                                <span v-if="sortConfig.key === 'amount'" class="report-hide">
                                    {{ sortConfig.direction === 'asc' ? '↑' : '↓' }}
                                </span>
                            </th>
                            <th>Tags</th>
                            <th>Note</th>
                            <th class="w-0">
                                <span class="sr-only">Files</span>
                                <Paperclip :size="14" aria-hidden="true" class="report-hide" />
                            </th>
                            <th class="table-actions-col">Actions</th>
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
                                    <template v-for="tag in transaction.tags" :key="tag.id">
                                        <div v-if="inReport" class="badge badge-soft badge-info px-1 gap-1">
                                            <span>{{ tag.emoji }}</span><span>{{ tag.title }}</span>
                                        </div>
                                        <button v-else type="button"
                                            class="badge badge-soft badge-info px-1 gap-1 cursor-pointer transition-colors hover:bg-info/25 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-info"
                                            :aria-label="`Open ${tag.title} details`"
                                            @click.stop="openTagDetail(tag, transaction)">
                                            <span>{{ tag.emoji }}</span><span>{{ tag.title }}</span>
                                        </button>
                                    </template>
                                </div>
                            </td>
                            <td class="max-w-xs" :title="transaction.note || undefined">
                                <span class="line-clamp-2 whitespace-normal break-words">{{ transaction.note || '-' }}</span>
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



                            <td class="table-actions-col flex gap-1">
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

        <!-- Totals of exactly what the table lists, plus averages over the same rows. -->
        <section v-if="filteredTransactions.length"
            class="mt-4 grid gap-4 sm:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]"
            aria-label="Totals of the listed transactions">
            <div class="card card-border border-base-300 bg-base-100 min-w-0">
                <div class="card-body p-4 gap-3 min-w-0">
                    <h2 class="text-xs uppercase tracking-wide text-base-content/60">
                        Totals <span class="normal-case tracking-normal">· {{ countLabel }}</span>
                    </h2>

                    <!-- Written addition: income, minus expenses, equals net. -->
                    <div class="grid grid-cols-[1rem_1fr_auto] items-baseline gap-x-2 gap-y-1 tabular-nums">
                        <span class="text-success font-semibold" aria-hidden="true">+</span>
                        <span class="text-sm text-base-content/60">Income</span>
                        <span class="text-right text-success">{{ formatMoney(totals.income) }}</span>
                        <span class="text-error font-semibold" aria-hidden="true">−</span>
                        <span class="text-sm text-base-content/60">Expenses</span>
                        <span class="text-right text-error">{{ formatMoney(Math.abs(totals.expenses)) }}</span>
                        <span class="col-span-3 border-t-2 border-base-content/20"></span>
                        <span class="font-semibold" aria-hidden="true">=</span>
                        <span class="text-sm font-semibold">Net</span>
                        <span class="text-right text-lg font-semibold">{{ signed(totals.net) }}</span>
                    </div>

                    <div v-if="incomeShare !== null" class="flex h-1 overflow-hidden rounded-full bg-base-300"
                        role="img"
                        :aria-label="`Income ${incomeShare}% of the money moved, expenses ${100 - incomeShare}%`">
                        <div class="bg-success/70" :style="{ width: `${incomeShare}%` }"></div>
                        <div class="flex-1 bg-error/70"></div>
                    </div>
                </div>
            </div>

            <div class="card card-border border-base-300 bg-base-100 min-w-0">
                <div class="card-body p-4 gap-3 min-w-0">
                    <h2 class="text-xs uppercase tracking-wide text-base-content/60">Averages</h2>
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-3 tabular-nums">
                        <div>
                            <dt class="text-xs text-base-content/60">Spend per day</dt>
                            <dd class="font-semibold">{{ formatMoney(averages.perDay) }}</dd>
                            <dd class="text-xs text-base-content/50">
                                over {{ averages.days }} {{ averages.days === 1 ? 'day' : 'days' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-base-content/60">Avg expense</dt>
                            <dd class="font-semibold">
                                {{ averages.expense === null ? '—' : formatMoney(averages.expense) }}
                            </dd>
                            <dd class="text-xs text-base-content/50">
                                {{ averages.expenseCount }} {{ averages.expenseCount === 1 ? 'expense' : 'expenses' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-base-content/60">Days with spending</dt>
                            <dd class="font-semibold">{{ averages.spendDays }} / {{ averages.days }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-base-content/60">Busiest day</dt>
                            <dd class="font-semibold">
                                {{ averages.busiest ? formatMoney(averages.busiest.amount) : '—' }}
                            </dd>
                            <dd v-if="averages.busiest" class="text-xs text-base-content/50">
                                {{ formatDate(parseLocalDate(averages.busiest.day)) }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>
        </section>

        <TagDetailModal v-if="!inReport" ref="detailModal" :dateRange="dateRange"
            @jump-to-list="emit('jump-to-list')" />
    </template>
</template>

<script setup>
import {
    Bot,
    CalendarDays,
    FileText,
    Image as ImageIcon,
    Laptop,
    Paperclip,
    Pencil,
    Repeat,
    Search,
    Send,
    X,
} from 'lucide-vue-next'

import { computed, inject, ref } from 'vue'
import DeleteHold from '../components/buttons/DeleteHold.vue'
import EmptyState from '../components/EmptyState.vue'
import TagDetailModal from '../components/TagDetailModal.vue'
import { today } from '../services/clock.js'
import { getTransactionSourceLabel, parseLocalDate, toLocalDateStr } from '../services/formatters'
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
    dateSelectionType: {
        type: String,
        default: 'month',
    },
})

// App passes the same listeners to every tab; declared so they are not extraneous attrs.
const emit = defineEmits(['jump-to-list', 'jump-to-month'])

// A day picked from a Flow bar (App clears it when the period or tab changes); null = whole period.
const listDay = inject('listDay', ref(null))

const sortConfig = ref({
    key: 'at',
    direction: 'desc',
})

const searchQuery = ref('')

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

// Same day key as the Flow bars (toLocalDateStr of the transaction time).
const dayTransactions = computed(() =>
    listDay.value
        ? dateRangeTransactions.value.filter((transaction) => toLocalDateStr(transaction.at) === listDay.value)
        : dateRangeTransactions.value,
)

const filteredTransactions = computed(() => {
    const query = searchQuery.value.trim().toLowerCase()

    return dayTransactions.value
        .filter((transaction) => !query || transaction.note?.toLowerCase().includes(query))
        .sort((a, b) => transactionsStore.sort(a, b, sortConfig.value.key, sortConfig.value.direction))
})

// Totals of exactly what the table lists (period, header tag filter, day, search).
const totals = computed(() => {
    let income = 0
    let expenses = 0

    for (const { amount } of filteredTransactions.value) {
        if (amount > 0) income += amount
        else expenses += amount
    }

    const round = (value) => Math.round(value * 100) / 100

    return {
        count: filteredTransactions.value.length,
        income: round(income),
        expenses: round(expenses),
        net: round(income + expenses),
    }
})

const countLabel = computed(() => `${totals.value.count} ${totals.value.count === 1 ? 'transaction' : 'transactions'}`)

// Same sign style as the Amount column: "+" for income, the formatter's minus for expenses.
const signed = (value) => (value > 0 ? '+' : '') + formatMoney(value)

// Income share of all money moved (income + |expenses|), whole percent; null when nothing moved.
const incomeShare = computed(() => {
    const moved = totals.value.income - totals.value.expenses

    return moved > 0 ? Math.round((totals.value.income / moved) * 100) : null
})

// Averages over the days of the period that have started (or the picked day).
const averages = computed(() => {
    const start = props.dateRange.currentStart
    const end = new Date(Math.min(props.dateRange.currentEnd, parseLocalDate(today.value)))
    const days = listDay.value ? 1 : Math.max(1, Math.round((end - start) / 86400000) + 1)
    const byDay = new Map()
    let expenseCount = 0

    for (const transaction of filteredTransactions.value) {
        if (transaction.amount >= 0) continue
        expenseCount += 1
        const day = toLocalDateStr(transaction.at)
        byDay.set(day, (byDay.get(day) ?? 0) - transaction.amount)
    }

    let busiest = null
    for (const [day, amount] of byDay) if (!busiest || amount > busiest.amount) busiest = { day, amount }
    const spend = -totals.value.expenses

    return {
        days,
        perDay: spend / days,
        expense: expenseCount ? spend / expenseCount : null,
        expenseCount,
        spendDays: byDay.size,
        busiest,
    }
})

const openAdd = () => transactions_add_modal.showModal()

// ReportDocument renders this tab for print: tag pills stay plain text there.
const inReport = inject('inReport', false)
const detailModal = ref(null)
// Same modal as the Tags tab; the side follows the transaction sign.
const openTagDetail = (tag, transaction) =>
    detailModal.value?.open(tag.id, transaction.amount >= 0 ? 'income' : 'expenses')

const editTransaction = (transaction) => {
    transactionsStore.transactionForEdit = transaction
    transactions_edit_modal.showModal()
}

const previewAttachments = (transaction) => {
    transactionsStore.openAttachmentPreview(transaction)
}
</script>
