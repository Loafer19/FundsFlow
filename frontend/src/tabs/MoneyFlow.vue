<template>
    <div class="flex mb-3">
        <select v-model="groupBy" class="select select-sm w-30 border-base-300 focus:border-base-content cursor-pointer">
            <option value="day">Day</option>
            <option value="week" :hidden="dateSelectionType == 'week'">Week</option>
            <option value="month" :hidden="dateSelectionType == 'week' || dateSelectionType == 'month'">Month</option>
        </select>

        <template v-if="groupBy == 'week'">
            <div class="flex items-center text-sm text-error">
                <div class="mx-2 inline-grid *:[grid-area:1/1]">
                    <div class="status status-error animate-ping"></div>
                    <div class="status status-error"></div>
                </div> only transactions from this {{ dateSelectionType }} are considered, late days may be excluded
            </div>
        </template>
    </div>

    <div class="card card-border border-base-300 bg-base-100">
        <div class="card-body">
            <BarChart :balances="moneyFlow" />
        </div>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import BarChart from '../components/charts/BarChart.vue'
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

watch(
    () => props.dateSelectionType,
    () => {
        groupBy.value = 'day'
    },
)

const toLocalDateStr = (date) => {
    const year = date.getFullYear()
    const month = String(date.getMonth() + 1).padStart(2, '0')
    const day = String(date.getDate()).padStart(2, '0')

    return `${year}-${month}-${day}`
}

const getPeriodEnd = (utcDateStr, groupBy) => {
    const date = new Date(utcDateStr)

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

const moneyFlow = computed(() => {
    const { currentStart, currentEnd } = props.dateRange
    const endDate = new Date(currentEnd)
    endDate.setDate(endDate.getDate() + 1)

    const transactions = transactionsStore.filteredByDateRange(currentStart, endDate)

    const groupTotals = transactions.reduce((acc, t) => {
        const periodEnd = getPeriodEnd(t.at, groupBy.value)
        acc[periodEnd] = acc[periodEnd] || { positive: 0, negative: 0 }
        t.amount >= 0 ? (acc[periodEnd].positive += t.amount) : (acc[periodEnd].negative += t.amount)
        return acc
    }, {})

    const periodEnds = getPeriodEnds(currentStart, endDate, groupBy.value)

    const balances = {}
    for (const periodEnd of periodEnds) {
        balances[periodEnd] = groupTotals[periodEnd] || { positive: 0, negative: 0 }
    }

    return balances
})
</script>

<style scoped></style>
