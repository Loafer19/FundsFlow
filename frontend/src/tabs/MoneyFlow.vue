<template>
    <EmptyState v-if="!hasPeriodData" icon="💸" title="No money flow in this period"
        description="Nothing in the selected or previous period. Add a transaction or change the range"
        action-label="Add Transaction" @action="openAdd" />

    <template v-else>
        <div class="flex flex-wrap items-center gap-3 mb-3">
            <select v-model="groupBy" aria-label="Group money flow by"
                class="select select-sm w-30 border-base-300 focus:border-base-content cursor-pointer">
                <option value="day">Day</option>
                <option value="week" :hidden="dateSelectionType == 'week'">Week</option>
                <option value="month" :hidden="dateSelectionType == 'week' || dateSelectionType == 'month'">Month
                </option>
            </select>

            <template v-if="groupBy == 'week'">
                <div class="flex items-center text-sm text-error">
                    <div class="mx-2 inline-grid *:[grid-area:1/1]">
                        <div class="status status-error animate-ping"></div>
                        <div class="status status-error"></div>
                    </div> Week totals only include full weeks inside this {{ dateSelectionType }}. Partial weeks at
                    the edges are omitted.
                </div>
            </template>

            <label class="label text-sm cursor-pointer ml-auto report-hide">
                <input v-model="compare" type="checkbox" class="toggle toggle-sm" />
                Compare with previous period
            </label>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-3">
            <StatCard v-for="item in comparison" :key="item.key" :label="item.label" :current="item.current"
                :previous="item.previous" />
        </div>

        <div class="card card-border border-base-300 bg-base-100">
            <div class="card-body">
                <BarChart :balances="moneyFlow" :previous="compare ? previousFlow : null" />
            </div>
        </div>
    </template>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import BarChart from '../components/charts/BarChart.vue'
import EmptyState from '../components/EmptyState.vue'
import StatCard from '../components/StatCard.vue'
import { parseLocalDate, toLocalDateStr } from '../services/formatters.js'
import { useTransactionsStore } from '../services/transactions.js'

const transactionsStore = useTransactionsStore()

const props = defineProps({
    dateRange: {
        type: Object,
        required: true,
    },
    dateSelectionType: {
        type: String,
        required: true,
    },
})

const groupBy = ref('day')
const compare = ref(false)
const openAdd = () => transactions_add_modal.showModal()
const hasPeriodData = computed(
    () =>
        transactionsStore.filteredByDateRangeAndTags(props.dateRange.currentStart, props.dateRange.currentEnd).length >
            0 ||
        transactionsStore.filteredByDateRangeAndTags(props.dateRange.previousStart, props.dateRange.previousEnd)
            .length > 0,
)

watch(
    () => props.dateSelectionType,
    () => {
        groupBy.value = 'day'
    },
)

const getPeriodEnd = (dateStr, groupBy) => {
    const date = parseLocalDate(toLocalDateStr(dateStr))

    if (groupBy === 'day') {
        return toLocalDateStr(date)
    }

    if (groupBy === 'week') {
        const day = date.getDay()
        const daysToSunday = day === 0 ? 0 : 7 - day
        const sunday = new Date(date)
        sunday.setDate(sunday.getDate() + daysToSunday)

        return toLocalDateStr(sunday)
    }

    if (groupBy === 'month') {
        const year = date.getFullYear()
        const month = date.getMonth()
        const lastDay = new Date(year, month + 1, 0)

        return toLocalDateStr(lastDay)
    }
}

const getPeriodEnds = (start, end, groupBy) => {
    const ends = []
    const startDate = new Date(start)
    const endDate = new Date(end)

    if (groupBy === 'day') {
        const current = new Date(startDate)

        while (current <= endDate) {
            ends.push(toLocalDateStr(current))
            current.setDate(current.getDate() + 1)
        }
    } else if (groupBy === 'week') {
        const current = new Date(startDate)

        if (current.getDay() !== 0) {
            const daysToSunday = 7 - current.getDay()
            current.setDate(current.getDate() + daysToSunday)
        }

        while (current <= endDate) {
            ends.push(toLocalDateStr(current))
            current.setDate(current.getDate() + 7)
        }
    } else if (groupBy === 'month') {
        const current = new Date(startDate)
        current.setDate(1)

        let lastDay = new Date(current.getFullYear(), current.getMonth() + 1, 0)

        if (lastDay >= startDate) {
            ends.push(toLocalDateStr(lastDay))
        }

        while (true) {
            current.setMonth(current.getMonth() + 1)
            lastDay = new Date(current.getFullYear(), current.getMonth() + 1, 0)
            if (lastDay > endDate) break
            ends.push(toLocalDateStr(lastDay))
        }
    }

    return ends
}

const buildFlow = (currentStart, currentEnd) => {
    const transactions = transactionsStore.filteredByDateRangeAndTags(currentStart, currentEnd)

    const groupTotals = transactions.reduce((acc, t) => {
        const periodEnd = getPeriodEnd(t.at, groupBy.value)
        acc[periodEnd] = acc[periodEnd] || { positive: 0, negative: 0 }
        t.amount >= 0 ? (acc[periodEnd].positive += t.amount) : (acc[periodEnd].negative += t.amount)
        return acc
    }, {})

    const periodEnds = getPeriodEnds(currentStart, currentEnd, groupBy.value)

    const balances = {}
    for (const periodEnd of periodEnds) {
        balances[periodEnd] = groupTotals[periodEnd] || { positive: 0, negative: 0 }
    }

    return balances
}

const moneyFlow = computed(() => buildFlow(props.dateRange.currentStart, props.dateRange.currentEnd))
const previousFlow = computed(() =>
    Object.values(buildFlow(props.dateRange.previousStart, props.dateRange.previousEnd)),
)

const totals = (start, end) =>
    transactionsStore.filteredByDateRangeAndTags(start, end).reduce(
        (acc, t) => {
            t.amount >= 0 ? (acc.income += t.amount) : (acc.expenses += t.amount)
            return acc
        },
        { income: 0, expenses: 0 },
    )

const comparison = computed(() => {
    const cur = totals(props.dateRange.currentStart, props.dateRange.currentEnd)
    const prev = totals(props.dateRange.previousStart, props.dateRange.previousEnd)

    return [
        { key: 'income', label: 'Income', current: cur.income, previous: prev.income },
        { key: 'expenses', label: 'Expenses', current: cur.expenses, previous: prev.expenses },
        { key: 'net', label: 'Net', current: cur.income + cur.expenses, previous: prev.income + prev.expenses },
    ]
})
</script>

<style scoped></style>
