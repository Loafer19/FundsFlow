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

            <span v-if="groupBy == 'week'" tabindex="0" class="tooltip tooltip-bottom -ml-1 before:max-w-52"
                :data-tip="`Weeks run Mon–Sun. The first and last weeks only count days inside this ${dateSelectionType}.`"
                aria-label="About week totals">
                <Info :size="16" class="opacity-60" />
            </span>

            <label class="label text-sm cursor-pointer ml-auto report-hide">
                <input v-model="compare" type="checkbox" class="toggle toggle-sm" />
                Compare with previous period
            </label>
        </div>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-3">
            <StatCard v-for="item in comparison" :key="item.key" :icon="item.icon" :label="item.label"
                :current="item.current" :previous="item.previous" :compare="compare" />
        </div>

        <div class="card card-border border-base-300 bg-base-100">
            <div class="card-body gap-3 p-3 sm:p-6">
                <div class="h-64 sm:h-96">
                    <BarChart :balances="moneyFlow" :previous="compare ? previousFlow : null" fill clickable
                        @bar-click="openBar" />
                </div>
            </div>
        </div>
    </template>
</template>

<script setup>
import { Info } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'
import BarChart from '../components/charts/BarChart.vue'
import EmptyState from '../components/EmptyState.vue'
import StatCard from '../components/StatCard.vue'
import { nowDateStr, parseLocalDate, toLocalDateStr } from '../services/formatters.js'
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

// App passes the same listeners to every tab; Flow uses jump-to-list for bar clicks.
const emit = defineEmits(['jump-to-list', 'jump-to-month'])

const groupBy = ref('day')
const compare = ref(true)
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

        // Days after the last Sunday form a partial week too (keyed by its Sunday, like the first one).
        if (!ends.length || ends.at(-1) < toLocalDateStr(endDate)) {
            ends.push(toLocalDateStr(current))
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

// Average expenses per bar (day / week / month). A running period only counts the buckets up to the
// one holding today, so the average is not diluted by days that have not happened yet.
const elapsedBuckets = (start, end) => {
    const ends = getPeriodEnds(start, end, groupBy.value)
    const today = nowDateStr()
    if (today < toLocalDateStr(start) || today > toLocalDateStr(end)) return ends.length || 1

    return Math.min(ends.length, ends.filter((periodEnd) => periodEnd < today).length + 1) || 1
}

const BUCKET_LABELS = { day: 'Avg daily expenses', week: 'Avg weekly expenses', month: 'Avg monthly expenses' }

const comparison = computed(() => {
    const { currentStart, currentEnd, previousStart, previousEnd } = props.dateRange
    const cur = totals(currentStart, currentEnd)
    const prev = totals(previousStart, previousEnd)

    return [
        { key: 'income', icon: 'income', label: 'Income', current: cur.income, previous: prev.income },
        { key: 'expenses', icon: 'expenses', label: 'Expenses', current: cur.expenses, previous: prev.expenses },
        {
            key: 'net',
            icon: 'net',
            label: 'Net',
            current: cur.income + cur.expenses,
            previous: prev.income + prev.expenses,
        },
        {
            key: 'avg',
            icon: 'daily',
            label: BUCKET_LABELS[groupBy.value],
            current: cur.expenses / elapsedBuckets(currentStart, currentEnd),
            previous: prev.expenses / elapsedBuckets(previousStart, previousEnd),
        },
    ]
})

// A bar opens the List for its range: the date picker jumps to that week / month, a day bar keeps the
// period and filters the List to that day (App handles both).
const openBar = (key) => emit('jump-to-list', { groupBy: groupBy.value, date: key })
</script>

<style scoped></style>
