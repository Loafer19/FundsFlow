<template>
    <EmptyState v-if="!hasPeriodData" icon="📊" title="No data for this period"
        description="Nothing in the selected or previous period. Add a transaction or change the range"
        action-label="Add Transaction" @action="openAdd" />

    <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div class="card card-border border-base-300 bg-base-100">
            <div class="card-body">
                <h2 class="card-title">
                    <Receipt :size="24" class="text-primary" />
                    Total Transactions
                </h2>
                <div class="flex justify-between items-center">
                    <span class="text-2xl">{{ filteredTransactions.length }}</span>
                    <div class="tooltip tooltip-left" :data-tip="`Previous period: ${previousTransactions.length}`">
                        <div class="badge badge-outline badge-secondary">
                            {{ formatPercentage(calculatePercentageDiff(filteredTransactions.length,
                                previousTransactions.length)) }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card card-border border-base-300 bg-base-100">
            <div class="card-body">
                <h2 class="card-title">
                    <Scale :size="24" class="text-info" />
                    Balance Change
                </h2>
                <div class="flex justify-between items-center">
                    <span class="text-2xl tooltip tooltip-right"
                        :data-tip="`Percentage of Income: ${formatPercentage(incomeToBalancePercentage)}`">
                        {{ formatMoney(balanceChange) }}
                    </span>
                    <div class="tooltip tooltip-left"
                        :data-tip="`Previous period: ${formatMoney(previousBalanceChange)}`">
                        <div class="badge badge-outline" :class="{
                            'badge-success': balanceChange > previousBalanceChange,
                            'badge-error': balanceChange < previousBalanceChange,
                            'badge-secondary': balanceChange == previousBalanceChange,
                        }">
                            {{ formatPercentage(calculatePercentageDiff(balanceChange, previousBalanceChange)) }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card card-border border-base-300 bg-base-100">
            <div class="card-body">
                <h2 class="card-title">
                    <TrendingUp :size="24" class="text-success" />
                    Average Daily Income
                </h2>
                <div class="flex justify-between items-center">
                    <span class="text-2xl tooltip tooltip-right"
                        :data-tip="`Total Income: ${formatMoney(totalIncome)}`">
                        {{ formatMoney(averageDailyIncome) }}
                    </span>
                    <div class="tooltip tooltip-left"
                        :data-tip="`Previous period: ${formatMoney(previousAverageDailyIncome)}`">
                        <div class="badge badge-outline" :class="{
                            'badge-success': averageDailyIncome > previousAverageDailyIncome,
                            'badge-error': averageDailyIncome < previousAverageDailyIncome,
                            'badge-secondary': averageDailyIncome == previousAverageDailyIncome,
                        }">
                            {{ formatPercentage(calculatePercentageDiff(averageDailyIncome,
                                previousAverageDailyIncome)) }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card card-border border-base-300 bg-base-100">
            <div class="card-body">
                <h2 class="card-title">
                    <TrendingDown :size="24" class="text-error" />
                    Average Daily Expenses
                </h2>
                <div class="flex justify-between items-center">
                    <span class="text-2xl tooltip tooltip-right"
                        :data-tip="`Total Expenses: ${formatMoney(totalExpenses)}`">
                        {{ formatMoney(averageDailyExpenses) }}
                    </span>
                    <div class="tooltip tooltip-left"
                        :data-tip="`Previous period: ${formatMoney(previousAverageDailyExpenses)}`">
                        <div class="badge badge-outline" :class="{
                            'badge-success': averageDailyExpenses < previousAverageDailyExpenses,
                            'badge-error': averageDailyExpenses > previousAverageDailyExpenses,
                            'badge-secondary': averageDailyExpenses == previousAverageDailyExpenses,
                        }">
                            {{ formatPercentage(calculatePercentageDiff(averageDailyExpenses,
                                previousAverageDailyExpenses)) }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card card-border border-base-300 bg-base-100">
            <div class="card-body">
                <div class="tags-list">
                    <table class="table">
                        <tbody>
                            <tr>
                                <td colspan="4">
                                    <span class="text-xl font-semibold tooltip tooltip-right"
                                        data-tip="Based on all transactions (not just this period)">Balances Per Tag</span>
                                </td>
                            </tr>
                            <tr v-for="(tag, index) in balancesByTags.slice(0, balancesByTags.length / 2)" :key="index">
                                <td>
                                    <div class="flex items-center gap-2">
                                        <div class="badge badge-soft badge-info text-xl py-4 px-2">
                                            {{ tag.emoji }}
                                        </div>
                                        <span>{{ tag.title }}</span>
                                    </div>
                                </td>
                                <td class="w-full text-right">
                                    <span class="text-2xl tooltip tooltip-left"
                                        :data-tip="tag.txns + ' transaction' + (tag.txns === 1 ? '' : 's')">
                                        {{ formatMoney(tag.balance) }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card card-border border-base-300 bg-base-100">
            <div class="card-body">
                <div class="tags-list">
                    <table class="table">
                        <tbody>
                            <tr v-for="(tag, index) in balancesByTags.slice(balancesByTags.length / 2, balancesByTags.length)"
                                :key="index">
                                <td>
                                    <div class="flex items-center gap-2">
                                        <div class="badge badge-soft badge-info text-xl py-4 px-2">
                                            {{ tag.emoji }}
                                        </div>
                                        <span>{{ tag.title }}</span>
                                    </div>
                                </td>
                                <td class="w-full text-right">
                                    <span class="text-2xl tooltip tooltip-left"
                                        :data-tip="tag.txns + ' transaction' + (tag.txns === 1 ? '' : 's')">
                                        {{ formatMoney(tag.balance) }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { Receipt, Scale, TrendingDown, TrendingUp } from 'lucide-vue-next'
import { computed, inject } from 'vue'
import EmptyState from '../components/EmptyState.vue'
import { useTagsStore } from '../services/tags.js'
import { useTransactionsStore } from '../services/transactions.js'

const tagsStore = useTagsStore()
const transactionsStore = useTransactionsStore()
const formatMoney = inject('formatMoney')
const formatPercentage = inject('formatPercentage')
const openAdd = () => transactions_add_modal.showModal()

const props = defineProps({
    dateRange: {
        type: Object,
        required: true,
    },
})

const hasPeriodData = computed(
    () =>
        transactionsStore.filteredByDateRange(props.dateRange.currentStart, props.dateRange.currentEnd).length > 0 ||
        transactionsStore.filteredByDateRange(props.dateRange.previousStart, props.dateRange.previousEnd).length > 0,
)

const calculatePercentageDiff = (current, previous) => {
    if (previous === 0) return current !== 0 ? Number.POSITIVE_INFINITY : 0
    return ((current - previous) / previous) * 100
}

const computeMetrics = (transactions, startDate, endDate) => {
    const totals = (transactions || []).reduce(
        (acc, t) => {
            t.amount >= 0 ? (acc.positive += t.amount) : (acc.negative += t.amount)
            return acc
        },
        {
            positive: 0,
            negative: 0,
        },
    )

    const diffTime = endDate - startDate
    const days = Math.ceil(diffTime / (1000 * 60 * 60 * 24))

    return {
        totalIncome: totals.positive,
        totalExpenses: Math.abs(totals.negative),
        balanceChange: totals.positive + totals.negative,
        averageDailyExpenses: Math.abs(totals.negative) / days,
        averageDailyIncome: totals.positive / days,
    }
}

const balancesByTags = computed(() => {
    const grouped = transactionsStore.groupedByTags()

    return tagsStore.forBalances().map((tag) => {
        const transactions = grouped.get(tag.id) || []
        return {
            ...tag,
            balance: transactions.reduce((acc, t) => acc + t.amount, 0),
            txns: transactions.length,
        }
    })
})

const filteredTransactions = computed(() =>
    transactionsStore.filteredByDateRange(props.dateRange.currentStart, props.dateRange.currentEnd),
)

const previousTransactions = computed(() =>
    transactionsStore.filteredByDateRange(props.dateRange.previousStart, props.dateRange.previousEnd),
)

const currentMetrics = computed(() =>
    computeMetrics(filteredTransactions.value, props.dateRange.currentStart, props.dateRange.currentEnd),
)

const previousMetrics = computed(() =>
    computeMetrics(previousTransactions.value, props.dateRange.previousStart, props.dateRange.previousEnd),
)

const incomeToBalancePercentage = computed(() => {
    if (currentMetrics.value.totalIncome === 0) return balanceChange.value >= 0 ? 0 : Number.NEGATIVE_INFINITY
    return (balanceChange.value / currentMetrics.value.totalIncome) * 100
})

const totalIncome = computed(() => currentMetrics.value.totalIncome)
const totalExpenses = computed(() => currentMetrics.value.totalExpenses)
const balanceChange = computed(() => currentMetrics.value.balanceChange)
const averageDailyExpenses = computed(() => currentMetrics.value.averageDailyExpenses)
const averageDailyIncome = computed(() => currentMetrics.value.averageDailyIncome)

const previousBalanceChange = computed(() => previousMetrics.value.balanceChange)
const previousAverageDailyExpenses = computed(() => previousMetrics.value.averageDailyExpenses)
const previousAverageDailyIncome = computed(() => previousMetrics.value.averageDailyIncome)
</script>

<style scoped></style>
