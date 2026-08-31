<template>
    <EmptyState v-if="!hasAnyData" icon="📅" title="No payments yet"
        description="Add a transaction or a recurring rule to see it on the calendar" action-label="Add Transaction"
        @action="openAdd" />

    <template v-else>
        <div class="grid grid-cols-3 gap-2 sm:gap-3 mb-3">
            <div class="card card-border border-base-300 bg-base-100 p-2 sm:p-3 min-w-0">
                <div class="text-[10px] sm:text-xs text-base-content/60">Income</div>
                <div class="text-sm sm:text-lg font-semibold text-success truncate">{{ formatMoney(periodTotals.income) }}
                </div>
            </div>
            <div class="card card-border border-base-300 bg-base-100 p-2 sm:p-3 min-w-0">
                <div class="text-[10px] sm:text-xs text-base-content/60">Expenses</div>
                <div class="text-sm sm:text-lg font-semibold text-error truncate">{{ formatMoney(periodTotals.expense) }}
                </div>
            </div>
            <div class="card card-border border-base-300 bg-base-100 p-2 sm:p-3 min-w-0">
                <div class="text-[10px] sm:text-xs text-base-content/60">Total</div>
                <div class="text-sm sm:text-lg font-semibold truncate"
                    :class="periodTotals.total >= 0 ? 'text-success' : 'text-error'">
                    {{ formatMoney(periodTotals.total) }}
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-[1fr_300px] gap-3 items-start">
            <div class="card card-border border-base-300 bg-base-100">
                <div class="card-body p-3 sm:p-6">
                    <div v-if="dateSelectionType === 'year'" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
                        <button v-for="m in yearMonths" :key="m.month" type="button"
                            class="card card-border border-base-300 text-left p-3 hover:border-primary"
                            :aria-label="monthName(m.start)" @click="jumpToMonth(m)">
                            <div class="font-semibold mb-1">{{ monthName(m.start) }}</div>
                            <div v-if="m.income || m.expense" class="text-sm">
                                <span v-if="m.income" class="text-success">+{{ formatMoney(m.income) }}</span>
                                <span v-if="m.expense" class="text-error"> −{{ formatMoney(m.expense) }}</span>
                            </div>
                            <div v-else class="text-sm text-base-content/60">No activity</div>
                            <div v-if="m.upcomingCount" class="text-xs text-base-content/60 mt-1">
                                {{ m.upcomingCount }} upcoming
                            </div>
                        </button>
                    </div>

                    <template v-else>
                        <div class="grid grid-cols-7 gap-0.5 sm:gap-1 mb-1 text-[10px] sm:text-xs font-medium text-base-content/60 text-center">
                            <span v-for="w in weekdayLabels" :key="w.full">
                                <span class="sm:hidden">{{ w.short }}</span>
                                <span class="hidden sm:inline">{{ w.full }}</span>
                            </span>
                        </div>

                        <div class="grid grid-cols-7 gap-0.5 sm:gap-1">
                            <button v-for="cell in gridCells" :key="cell.date" type="button" class="day-cell"
                                :class="{
                                    'opacity-40': !cell.inRange,
                                    'border-primary!': cell.date === selectedDate,
                                    'text-primary font-bold': cell.date === todayStr,
                                }"
                                :aria-label="`${formatDate(cell.date)}, ${cell.items.length} transaction${cell.items.length === 1 ? '' : 's'}`"
                                @click="selectedDate = cell.date">
                                <span class="text-[11px] sm:text-xs leading-none">{{ cell.day }}</span>

                                <!-- Mobile: colored dots (amounts don't fit ~42px cells) -->
                                <div v-if="cell.items.length" class="flex flex-wrap gap-0.5 mt-auto sm:hidden">
                                    <span v-for="item in cell.items.slice(0, 3)" :key="item.key" class="day-dot"
                                        :class="[item.amount > 0 ? 'bg-success' : 'bg-error', !item.actual && 'opacity-50']"></span>
                                    <span v-if="cell.items.length > 3"
                                        class="text-[9px] leading-none text-base-content/60">+{{ cell.items.length - 3 }}</span>
                                </div>

                                <!-- sm+: amount badges -->
                                <div class="hidden sm:flex flex-col gap-0.5 mt-auto w-full min-w-0">
                                    <div v-for="item in cell.items.slice(0, 2)" :key="item.key"
                                        class="badge badge-xs w-full justify-start truncate px-1"
                                        :class="badgeClass(item)">
                                        {{ formatMoney(item.amount) }}
                                    </div>
                                    <div v-if="cell.items.length > 2" class="text-[10px] text-base-content/60">
                                        +{{ cell.items.length - 2 }} more
                                    </div>
                                </div>
                            </button>
                        </div>
                    </template>
                </div>
            </div>

            <div v-if="dateSelectionType !== 'year'" class="flex flex-col gap-3">
                <div>
                    <h3 class="font-semibold text-base mb-2">{{ formatDate(selectedDate) }}</h3>

                    <EmptyState v-if="!selectedItems.length" icon="📭" title="Nothing this day"
                        description="No transactions or upcoming payments on this date" action-label="Add Transaction"
                        @action="addForSelectedDate" />

                    <div v-else class="grid grid-cols-2 gap-2">
                        <button v-for="item in selectedItems" :key="item.key" type="button"
                            class="card card-border border-base-300 bg-base-100 p-2 justify-between text-center cursor-pointer hover:border-primary"
                            :aria-label="itemAriaLabel(item)" @click="openItem(item)">
                            <div class="flex flex-wrap justify-center gap-1 mb-1">
                                <span v-for="tag in item.tags" :key="tag.id" class="text-lg tooltip"
                                    :data-tip="tag.title">{{ tag.emoji }}</span>
                            </div>
                            <div v-if="item.note" class="text-xs text-base-content/60 truncate mb-1">{{ item.note }}</div>
                            <div class="badge w-full justify-center" :class="badgeClass(item)">
                                {{ item.amount > 0 ? '+' : '' }}{{ formatMoney(item.amount) }}
                            </div>
                        </button>

                        <button type="button"
                            class="card border-2 border-dashed border-base-300 p-2 items-center justify-center text-base-content/60 cursor-pointer hover:border-primary hover:text-primary"
                            @click="addForSelectedDate">
                            <Plus :size="20" />
                            <span class="text-xs mt-1">Add</span>
                        </button>
                    </div>
                </div>

                <div>
                    <h3 class="font-semibold text-base mb-2">Upcoming</h3>

                    <EmptyState v-if="!upcoming.length" icon="🔁" title="Nothing upcoming"
                        description="No recurring payments due in the next 30 days" action-label="Add Recurring"
                        @action="showModal('recurring_add_modal')" />

                    <div v-else class="flex flex-col gap-2">
                        <button v-for="item in upcoming" :key="item.key" type="button"
                            class="card card-border border-base-300 bg-base-100 flex-row items-center gap-2 p-2 text-left cursor-pointer hover:border-primary"
                            :aria-label="itemAriaLabel(item)" @click="openItem(item)">
                            <div class="radial-progress shrink-0 text-[10px]" :class="ringClass(item)"
                                :style="{ '--value': ringValue(item), '--size': '2.5rem', '--thickness': '3px' }">
                                {{ item.daysAway === 0 ? 'now' : item.daysAway + 'd' }}
                            </div>
                            <div class="flex-1 min-w-0 flex items-center gap-1 text-sm">
                                <span class="shrink-0 text-base-content/60">{{ formatDate(item.date) }}</span>
                                <span class="text-base-content/40">·</span>
                                <span v-for="tag in item.tags" :key="tag.id" class="tooltip shrink-0"
                                    :data-tip="tag.title">{{ tag.emoji }}</span>
                                <template v-if="item.note">
                                    <span class="text-base-content/40">·</span>
                                    <span class="truncate">{{ item.note }}</span>
                                </template>
                            </div>
                            <div class="badge badge-outline shrink-0"
                                :class="item.amount > 0 ? 'badge-success' : 'badge-error'">
                                {{ item.amount > 0 ? '+' : '' }}{{ formatMoney(item.amount) }}
                            </div>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>
</template>

<script setup>
import { Plus } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'
import EmptyState from '../components/EmptyState.vue'
import { formatDate, formatMoney, parseLocalDate, toLocalDateStr } from '../services/formatters.js'
import { showModal } from '../services/modal.js'
import { projectOccurrences } from '../services/recurringSchedule.js'
import { useRecurringTransactionsStore } from '../services/recurringTransactions.js'
import { useTransactionsStore } from '../services/transactions.js'

const transactionsStore = useTransactionsStore()
const recurringStore = useRecurringTransactionsStore()

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

const emit = defineEmits(['jump-to-month'])

const weekdayLabels = [
    { short: 'M', full: 'Mon' },
    { short: 'T', full: 'Tue' },
    { short: 'W', full: 'Wed' },
    { short: 'T', full: 'Thu' },
    { short: 'F', full: 'Fri' },
    { short: 'S', full: 'Sat' },
    { short: 'S', full: 'Sun' },
]
const todayStr = toLocalDateStr(new Date())
const selectedDate = ref(todayStr)

const hasAnyData = computed(() => transactionsStore.transactions.length > 0 || recurringStore.rules.length > 0)

const openAdd = () => transactions_add_modal.showModal()

const addForSelectedDate = () => {
    transactionsStore.transactionDraftAt = selectedDate.value
    transactions_add_modal.showModal()
}

const itemsInRange = (start, end) => {
    const actual = transactionsStore.filteredByDateRange(start, end).map((t) => ({
        key: 'txn-' + t.id,
        date: toLocalDateStr(t.at),
        actual: true,
        amount: t.amount,
        note: t.note,
        tags: t.tags,
        transaction: t,
    }))

    const projected = recurringStore.rules.flatMap((rule) =>
        projectOccurrences(rule, start, end).map((date) => ({
            key: 'rule-' + rule.id + '-' + date,
            date,
            actual: false,
            amount: rule.amount,
            note: rule.note,
            tags: rule.tags,
            rule,
        })),
    )

    return [...actual, ...projected]
}

const groupByDate = (items) => {
    const map = new Map()

    for (const item of items) {
        const list = map.get(item.date) ?? []
        list.push(item)
        map.set(item.date, list)
    }

    return map
}

const gridRange = computed(() => {
    const start = new Date(props.dateRange.currentStart)
    const end = new Date(props.dateRange.currentEnd)

    if (props.dateSelectionType === 'week') return { start, end }

    const startDay = start.getDay()
    const daysFromMonday = startDay === 0 ? 6 : startDay - 1
    const gridStart = new Date(start)
    gridStart.setDate(gridStart.getDate() - daysFromMonday)

    const endDay = end.getDay()
    const daysToSunday = endDay === 0 ? 0 : 7 - endDay
    const gridEnd = new Date(end)
    gridEnd.setDate(gridEnd.getDate() + daysToSunday)

    return { start: gridStart, end: gridEnd }
})

const gridCells = computed(() => {
    if (props.dateSelectionType === 'year') return []

    const { start, end } = gridRange.value
    const monthStart = new Date(props.dateRange.currentStart)
    const monthEnd = new Date(props.dateRange.currentEnd)
    const items = groupByDate(itemsInRange(start, end))

    const cells = []
    const cursor = new Date(start)

    while (cursor <= end) {
        const date = toLocalDateStr(cursor)

        cells.push({
            date,
            day: cursor.getDate(),
            inRange: cursor >= monthStart && cursor <= monthEnd,
            items: items.get(date) ?? [],
        })

        cursor.setDate(cursor.getDate() + 1)
    }

    return cells
})

const periodTotals = computed(() => {
    const items = itemsInRange(props.dateRange.currentStart, props.dateRange.currentEnd)
    const income = items.filter((i) => i.amount > 0).reduce((sum, i) => sum + i.amount, 0)
    const expense = items.filter((i) => i.amount < 0).reduce((sum, i) => sum + i.amount, 0)

    return { income, expense, total: income + expense }
})

const selectedItems = computed(() => {
    const date = parseLocalDate(selectedDate.value)

    return itemsInRange(date, date)
})

const upcoming = computed(() => {
    const start = new Date()
    const end = new Date()
    end.setDate(end.getDate() + 30)

    return recurringStore.rules
        .flatMap((rule) =>
            projectOccurrences(rule, start, end).map((date) => ({
                key: 'rule-' + rule.id + '-' + date,
                date,
                daysAway: Math.round((parseLocalDate(date) - parseLocalDate(todayStr)) / 86400000),
                amount: rule.amount,
                note: rule.note,
                tags: rule.tags,
                rule,
            })),
        )
        .sort((a, b) => (a.date < b.date ? -1 : a.date > b.date ? 1 : 0))
        .slice(0, 8)
})

const ringValue = (item) => Math.max(0, 100 - (item.daysAway / 30) * 100)
const ringClass = (item) => (item.amount > 0 ? 'text-success' : 'text-error')

const yearMonths = computed(() => {
    if (props.dateSelectionType !== 'year') return []

    const year = new Date(props.dateRange.currentStart).getFullYear()

    return Array.from({ length: 12 }, (_, month) => {
        const start = new Date(year, month, 1)
        const end = new Date(year, month + 1, 0)
        const items = itemsInRange(start, end)

        return {
            month,
            start,
            income: items.filter((i) => i.actual && i.amount > 0).reduce((sum, i) => sum + i.amount, 0),
            expense: items.filter((i) => i.actual && i.amount < 0).reduce((sum, i) => sum - i.amount, 0),
            upcomingCount: items.filter((i) => !i.actual).length,
        }
    })
})

const monthName = (date) => date.toLocaleDateString('en-US', { month: 'long' })

const itemAriaLabel = (item) => {
    const amount = `${item.amount > 0 ? '+' : ''}${formatMoney(item.amount)}`
    const detail = item.note?.trim() || item.tags?.map((tag) => tag.title).filter(Boolean).join(', ')

    return detail ? `${amount}, ${detail}` : amount
}

const jumpToMonth = (m) => emit('jump-to-month', m.start)

const badgeClass = (item) => [
    item.actual ? 'badge-outline' : 'badge-dash',
    item.amount > 0 ? 'badge-success' : 'badge-error',
]

const openItem = (item) => {
    if (item.transaction) {
        transactionsStore.transactionForEdit = item.transaction
        transactions_edit_modal.showModal()
    } else if (item.rule) {
        recurringStore.ruleForEdit = item.rule
        recurring_edit_modal.showModal()
    }
}

watch(
    () => [props.dateRange.currentStart, props.dateSelectionType],
    () => {
        const start = new Date(props.dateRange.currentStart)
        const end = new Date(props.dateRange.currentEnd)
        const today = new Date()

        selectedDate.value = today >= start && today <= end ? todayStr : toLocalDateStr(start)
    },
    { immediate: true },
)
</script>

<style scoped>
.day-cell {
    border: var(--border) solid var(--color-base-300);
    border-radius: var(--radius-field);
    min-height: 2.75rem;
    padding: 0.25rem;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    text-align: left;
    min-width: 0;
    overflow: hidden;
}

@media (min-width: 640px) {
    .day-cell {
        min-height: 5rem;
        padding: 0.375rem;
    }
}

.day-cell:hover {
    border-color: var(--color-primary);
}

.day-dot {
    width: 0.375rem;
    height: 0.375rem;
    border-radius: 999px;
    flex-shrink: 0;
}
</style>
