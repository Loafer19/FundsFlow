<template>
    <EmptyState v-if="!hasPeriodData" icon="📊" title="No data for this period"
        description="Nothing in the selected or previous period. Add a transaction or change the range"
        action-label="Add Transaction" @action="openAdd" />

    <div v-else class="flex flex-col gap-3 w-full min-w-0">
        <div class="grid grid-cols-2 md:grid-cols-3 gap-3 min-w-0">
            <StatCard v-for="card in statCards" :key="card.key" :icon="card.key" v-bind="card" />
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 min-w-0">
            <div v-if="forecast" class="card card-border border-base-300 bg-base-100 min-w-0"
                :class="{ 'md:col-span-2': !insights.length }">
                <div class="card-body p-4 gap-3 min-w-0">
                    <h2 class="card-title text-base sm:text-lg min-w-0">
                        <Telescope :size="22" class="text-accent shrink-0" />
                        <span class="truncate">Forecast · end of {{ periodName }}</span>
                        <span class="tooltip tooltip-bottom before:max-w-52 sm:before:max-w-64 font-normal text-sm report-hide" :data-tip="forecast.explanation">
                            <Info :size="16" class="opacity-60" />
                        </span>
                    </h2>
                    <div class="grid grid-cols-3 gap-2 min-w-0">
                        <div v-for="item in forecast.items" :key="item.label" class="min-w-0">
                            <div class="text-xs uppercase tracking-wide text-base-content/60 truncate">{{ item.label }}</div>
                            <div class="text-base sm:text-lg font-semibold tabular-nums truncate" :class="item.class">
                                {{ formatMoney(item.value) }}
                            </div>
                        </div>
                    </div>
                    <p class="text-xs text-base-content/60">
                        {{ forecast.summary }}
                    </p>
                </div>
            </div>

            <div v-if="insights.length" class="card card-border border-base-300 bg-base-100 min-w-0"
                :class="{ 'md:col-span-2': !forecast }">
                <div class="card-body p-4 gap-2 min-w-0">
                    <h2 class="card-title text-base sm:text-lg">
                        <Lightbulb :size="22" class="text-warning shrink-0" />
                        Insights
                    </h2>
                    <ul class="flex flex-col gap-1.5 text-sm min-w-0">
                        <li v-for="insight in insights" :key="insight.key" class="flex gap-2 min-w-0">
                            <span class="shrink-0 w-5 text-center">{{ insight.icon }}</span>
                            <span class="min-w-0">{{ insight.text }}</span>
                        </li>
                    </ul>
                </div>
            </div>

            <div v-if="topTags.length" class="card card-border border-base-300 bg-base-100 min-w-0">
                <div class="card-body p-4 gap-2 min-w-0">
                    <h2 class="card-title text-base sm:text-lg min-w-0">
                        <ChartPie :size="22" class="text-primary shrink-0" />
                        <span class="truncate">Top spending tags</span>
                        <span class="tooltip tooltip-bottom before:max-w-52 sm:before:max-w-64 font-normal text-sm report-hide" :data-tip="topTagsHint">
                            <Info :size="16" class="opacity-60" />
                        </span>
                    </h2>
                    <ul class="flex flex-col min-w-0">
                        <li v-for="row in topTags" :key="row.key" class="border-b border-base-300/60 last:border-b-0">
                            <button type="button"
                                class="w-full text-left py-2 flex flex-col gap-1 min-w-0 rounded-field hover:bg-base-200/60 px-1 -mx-1 cursor-pointer"
                                :class="{ 'bg-info/10': row.active }" :aria-pressed="row.active"
                                :aria-label="`Filter by ${row.title}`" @click="toggleTagFilter(row)">
                                <span class="flex items-center justify-between gap-2 min-w-0">
                                    <span class="flex items-center gap-2 min-w-0">
                                        <span class="shrink-0 text-lg leading-none">{{ row.emoji }}</span>
                                        <span class="truncate">{{ row.title }}</span>
                                    </span>
                                    <span class="flex items-center gap-2 shrink-0">
                                        <span v-if="row.diff.label" class="badge badge-sm tabular-nums"
                                            :class="row.diff.badge">
                                            {{ row.diff.label }}
                                        </span>
                                        <span class="font-semibold tabular-nums">{{ formatMoney(row.amount) }}</span>
                                    </span>
                                </span>
                                <span class="flex items-center gap-2 min-w-0">
                                    <progress class="progress progress-primary h-1.5 flex-1" :value="row.share" max="100"></progress>
                                    <span class="text-xs text-base-content/60 tabular-nums w-12 text-right">{{ formatPercentage(row.share) }}</span>
                                </span>
                            </button>
                        </li>
                    </ul>
                </div>
            </div>

            <div v-if="largestExpenses.length" class="card card-border border-base-300 bg-base-100 min-w-0"
                :class="{ 'md:col-span-2': !topTags.length }">
                <div class="card-body p-4 gap-2 min-w-0">
                    <h2 class="card-title text-base sm:text-lg min-w-0">
                        <Flame :size="22" class="text-error shrink-0" />
                        <span class="truncate">Largest expenses</span>
                        <button type="button" class="btn btn-ghost btn-xs ml-auto report-hide font-normal"
                            @click="emit('jump-to-list')">
                            See all
                            <ChevronRight :size="14" />
                        </button>
                    </h2>
                    <ul class="flex flex-col min-w-0">
                        <li v-for="t in largestExpenses" :key="t.id" class="border-b border-base-300/60 last:border-b-0">
                            <button type="button"
                                class="w-full text-left py-2 flex items-center gap-3 min-w-0 rounded-field hover:bg-base-200/60 px-1 -mx-1 cursor-pointer"
                                @click="emit('jump-to-list')">
                                <span class="badge badge-soft badge-info shrink-0 text-lg py-4 px-2 w-10 justify-center">
                                    {{ t.emoji || '🏷️' }}
                                </span>
                                <span class="flex flex-col min-w-0 flex-1">
                                    <span class="truncate">{{ t.label }}</span>
                                    <span class="text-xs text-base-content/60">{{ formatDate(t.at) }}</span>
                                </span>
                                <span class="flex flex-col items-end shrink-0">
                                    <span class="font-semibold tabular-nums">{{ formatMoney(t.amount) }}</span>
                                    <span class="text-xs text-base-content/60 tabular-nums">{{ formatPercentage(t.share) }} of spend</span>
                                </span>
                            </button>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div v-if="!inReport || balancesByTags.length" class="card card-border border-base-300 bg-base-100 min-w-0 overflow-hidden">
            <div class="card-body p-4 gap-3 min-w-0">
                <div class="flex flex-wrap items-center gap-2 min-w-0">
                    <button type="button" class="card-title text-base sm:text-lg min-w-0 cursor-pointer"
                        :aria-expanded="balancesOpen" aria-controls="analytics-tag-balances"
                        :disabled="inReport" @click="toggleBalances">
                        <ChevronDown :size="20" class="shrink-0 transition-transform report-hide"
                            :class="{ '-rotate-90': !balancesOpen }" />
                        <span class="tooltip tooltip-right font-semibold"
                            data-tip="Parent tags include child tags">
                            Balances Per Tag
                        </span>
                        <span v-if="inReport" class="text-sm font-normal text-base-content/60">
                            · {{ balancesScope === 'period' ? 'this period' : 'all time' }}
                        </span>
                    </button>
                    <div class="join ml-auto report-hide" role="radiogroup" aria-label="Balances scope">
                        <button v-for="option in balanceScopes" :key="option.value" type="button" role="radio"
                            class="join-item btn btn-xs" :class="balancesScope === option.value ? 'btn-primary' : 'btn-ghost border-base-300'"
                            :aria-checked="balancesScope === option.value" @click="setBalancesScope(option.value)">
                            {{ option.label }}
                        </button>
                    </div>
                </div>
                <div v-show="balancesOpen" id="analytics-tag-balances"
                    class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 sm:gap-x-8 gap-y-0 min-w-0">
                    <div v-for="tag in balancesByTags" :key="tag.id"
                        class="flex items-center justify-between gap-2 min-w-0 py-2.5 border-b border-base-300/60">
                        <div class="flex min-w-0 items-center gap-2">
                            <div class="badge badge-soft badge-info shrink-0 text-xl py-4 px-2">
                                {{ tag.emoji }}
                            </div>
                            <span class="truncate">{{ tag.title }}</span>
                        </div>
                        <span class="min-w-0 max-w-[45%] shrink text-base sm:text-xl tabular-nums tooltip tooltip-left truncate text-right"
                            :data-tip="tag.txns + ' transaction' + (tag.txns === 1 ? '' : 's')">
                            {{ formatMoney(tag.balance) }}
                        </span>
                    </div>
                    <p v-if="!balancesByTags.length" class="text-sm text-base-content/60 py-2">
                        No tags with "Calculate balance" enabled
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ChartPie, ChevronDown, ChevronRight, Flame, Info, Lightbulb, Telescope } from 'lucide-vue-next'
import { computed, inject, ref } from 'vue'
import EmptyState from '../components/EmptyState.vue'
import StatCard from '../components/StatCard.vue'
import { today } from '../services/clock.js'
import { tagChange } from '../services/comparison.js'
import { parseLocalDate, toLocalDateStr } from '../services/formatters.js'
import { projectOccurrences } from '../services/recurringSchedule.js'
import { useRecurringTransactionsStore } from '../services/recurringTransactions.js'
import { useTagsStore } from '../services/tags.js'
import { useTransactionsStore } from '../services/transactions.js'

const emit = defineEmits(['jump-to-list'])

const tagsStore = useTagsStore()
const transactionsStore = useTransactionsStore()
const recurringStore = useRecurringTransactionsStore()
const formatMoney = inject('formatMoney')
const formatPercentage = inject('formatPercentage')
const formatDate = inject('formatDate')
// Provided by ReportDocument: controls are hidden (.report-hide), Balances Per Tag is always expanded and
// lists only the filtered tags with a non-zero balance (the app tab is unchanged).
const inReport = inject('inReport', false)
const openAdd = () => transactions_add_modal.showModal()

const props = defineProps({
    dateRange: {
        type: Object,
        required: true,
    },
})

const DAY_MS = 86_400_000
const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']
// Inclusive day count between two YYYY-MM-DD strings.
const daySpan = (fromStr, toStr) => Math.round((parseLocalDate(toStr) - parseLocalDate(fromStr)) / DAY_MS) + 1
const shiftDays = (dateStr, days) => {
    const date = parseLocalDate(dateStr)
    date.setDate(date.getDate() + days)
    return toLocalDateStr(date)
}
const dayOf = (t) => toLocalDateStr(t.at)
const sumExpenses = (list) => list.reduce((acc, t) => (t.amount < 0 ? acc - t.amount : acc), 0)
const sumIncome = (list) => list.reduce((acc, t) => (t.amount > 0 ? acc + t.amount : acc), 0)

const firstTransactionDay = computed(() => {
    let first = null
    for (const t of transactionsStore.transactions) {
        const day = dayOf(t)
        if (!first || day < first) first = day
    }
    return first
})

const range = computed(() => {
    const start = toLocalDateStr(props.dateRange.currentStart)
    const end = toLocalDateStr(props.dateRange.currentEnd)
    const previousStart = toLocalDateStr(props.dateRange.previousStart)
    const previousEnd = toLocalDateStr(props.dateRange.previousEnd)
    // A running period (today inside it) is measured up to today: averages use the days so far,
    // and tag changes compare against the same number of days at the start of the previous period.
    const running = start <= today.value && today.value <= end
    const elapsedEnd = running ? today.value : end
    const days = daySpan(start, end)
    const elapsedDays = daySpan(start, elapsedEnd)
    const previousDays = daySpan(previousStart, previousEnd)
    const toDateEnd = running ? shiftDays(previousStart, Math.min(elapsedDays, previousDays) - 1) : previousEnd
    // Daily averages, weekdays and no-spend days only count days since the first transaction ever, so a
    // year (or a first month) that started before the user did is not padded with empty days.
    const first = firstTransactionDay.value
    const activeStart = first && first > start && first <= elapsedEnd ? first : start
    const activeDays = daySpan(activeStart, elapsedEnd)
    const previousActiveStart = first && first > previousStart ? first : previousStart
    const previousActiveDays =
        previousActiveStart <= previousEnd ? daySpan(previousActiveStart, previousEnd) : previousDays

    return {
        start,
        end,
        previousStart,
        previousEnd,
        running,
        elapsedEnd,
        days,
        elapsedDays,
        previousDays,
        toDateEnd,
        activeStart,
        activeDays,
        previousActiveDays,
    }
})

const periodName = computed(() => {
    const { days, start } = range.value
    if (days <= 7) return 'week'
    if (days <= 31) return parseLocalDate(start).toLocaleDateString('en-US', { month: 'long' })
    if (days <= 366) return 'year'
    return 'period'
})
const periodWord = computed(() => {
    const { days } = range.value
    if (days <= 7) return 'week'
    if (days <= 31) return 'month'
    if (days <= 366) return 'year'
    return 'period'
})

const filteredTransactions = computed(() =>
    transactionsStore.filteredByDateRangeAndTags(props.dateRange.currentStart, props.dateRange.currentEnd),
)

const previousTransactions = computed(() =>
    transactionsStore.filteredByDateRangeAndTags(props.dateRange.previousStart, props.dateRange.previousEnd),
)

const previousToDate = computed(() => {
    const end = range.value.toDateEnd
    return previousTransactions.value.filter((t) => dayOf(t) <= end)
})

const hasPeriodData = computed(() => filteredTransactions.value.length > 0 || previousTransactions.value.length > 0)

const totals = (list) => {
    const income = sumIncome(list)
    const expenses = sumExpenses(list)
    return { income, expenses, net: income - expenses, count: list.length }
}

const current = computed(() => totals(filteredTransactions.value))
const previous = computed(() => totals(previousTransactions.value))
const savingsRate = (t) => (t.income > 0 ? (t.net / t.income) * 100 : null)

const statCards = computed(() => {
    const cur = current.value
    const prev = previous.value
    const { running, activeDays, previousActiveDays } = range.value

    return [
        { key: 'income', label: 'Income', current: cur.income, previous: prev.income },
        { key: 'expenses', label: 'Expenses', current: cur.expenses, previous: prev.expenses, lowerIsBetter: true },
        { key: 'net', label: 'Net', current: cur.net, previous: prev.net, hint: 'Balance change: income − expenses' },
        {
            key: 'savings',
            label: 'Savings rate',
            current: savingsRate(cur),
            previous: savingsRate(prev),
            format: 'percent',
            hint: 'Share of income kept (net ÷ income). Change in percentage points',
        },
        {
            key: 'count',
            label: 'Transactions',
            current: cur.count,
            previous: prev.count,
            format: 'count',
            neutral: true,
        },
        {
            key: 'daily',
            label: 'Avg daily expense',
            current: cur.expenses / activeDays,
            previous: prev.expenses / previousActiveDays,
            lowerIsBetter: true,
            hint: running ? `Expenses ÷ ${activeDays} days so far` : `Expenses ÷ ${activeDays} days`,
        },
    ]
})

// ── Top spending tags: top-level tags, each including its child tags (a transaction counts once per
// top-level tag even when it carries several of its children). Transactions with tags from two
// top-level tags count in both, like the Tags tab.
const UNTAGGED = 'untagged'
const rootGroups = computed(() =>
    tagsStore.tags.filter((tag) => tag.parent_id == null).map((tag) => ({ tag, ids: tagsStore.descendantIds(tag.id) })),
)

const expensesByRoot = (list) => {
    const map = new Map()
    for (const t of list) {
        if (t.amount >= 0) continue
        const tags = t.tags ?? []
        if (!tags.length) {
            map.set(UNTAGGED, (map.get(UNTAGGED) ?? 0) - t.amount)
            continue
        }
        for (const { tag, ids } of rootGroups.value) {
            if (tags.some((x) => ids.has(x.id))) map.set(tag.id, (map.get(tag.id) ?? 0) - t.amount)
        }
    }
    return map
}

// Changes under 5% are noise for a tag: grey pill instead of red/green (see tagChange).
const tagDiff = (amount, previousAmount) => tagChange(amount, previousAmount, { lowerIsBetter: true, formatPercentage })

const tagRows = computed(() => {
    const cur = expensesByRoot(filteredTransactions.value)
    const prev = expensesByRoot(previousToDate.value)
    const total = current.value.expenses
    const byId = new Map(rootGroups.value.map((g) => [g.tag.id, g]))
    const selected = new Set(transactionsStore.selectedTagIds)

    return [...cur.entries()]
        .map(([key, amount]) => {
            const group = byId.get(key)
            const ids = group ? [...group.ids] : []
            const previousAmount = prev.get(key) ?? 0
            const active =
                key === UNTAGGED
                    ? transactionsStore.filterUntagged && !selected.size
                    : !transactionsStore.filterUntagged &&
                      selected.size === ids.length &&
                      ids.every((id) => selected.has(id))
            return {
                key,
                ids,
                emoji: group ? group.tag.emoji : '🏷️',
                title: group ? group.tag.title : 'Untagged',
                amount,
                previous: previousAmount,
                share: total > 0 ? (amount / total) * 100 : 0,
                diff: tagDiff(amount, previousAmount),
                active,
            }
        })
        .sort((a, b) => b.amount - a.amount)
})

const topTags = computed(() => tagRows.value.slice(0, 5))
const topTagsHint = computed(() =>
    range.value.running
        ? `Top-level tags incl. child tags. Change vs the first ${range.value.elapsedDays} days of the previous ${periodWord.value}. Click to filter`
        : `Top-level tags incl. child tags. Change vs the previous ${periodWord.value}. Click to filter`,
)

const toggleTagFilter = (row) => {
    if (inReport) return
    if (row.active) {
        transactionsStore.clearTagFilter()
        return
    }
    if (row.key === UNTAGGED) {
        transactionsStore.selectedTagIds = []
        transactionsStore.filterUntagged = true
        return
    }
    transactionsStore.filterUntagged = false
    transactionsStore.selectedTagIds = row.ids
}

// ── Largest expenses
const tagEmoji = (t) => (t.tags ?? []).find((tag) => tag.emoji)?.emoji ?? ''
const largestExpenses = computed(() => {
    const total = current.value.expenses
    return filteredTransactions.value
        .filter((t) => t.amount < 0)
        .sort((a, b) => a.amount - b.amount)
        .slice(0, 5)
        .map((t) => ({
            id: t.id,
            at: t.at,
            amount: t.amount,
            emoji: tagEmoji(t),
            label: t.note || (t.tags ?? []).map((tag) => tag.title).join(', ') || 'Untitled',
            share: total > 0 ? (-t.amount / total) * 100 : 0,
        }))
})

// "Typical" spend for averages: non-recurring expenses up to today, without large one-offs (a hotel,
// a laptop) — anything over 5× the median expense once there are enough expenses to tell.
const typicalSpending = computed(() => {
    const { elapsedEnd } = range.value
    const variable = filteredTransactions.value.filter(
        (t) => t.amount < 0 && t.source !== 'recurring' && dayOf(t) <= elapsedEnd,
    )
    if (variable.length < 5) return variable
    const sorted = variable.map((t) => -t.amount).sort((a, b) => a - b)
    const median = sorted[Math.floor(sorted.length / 2)]
    return variable.filter((t) => -t.amount <= median * 5)
})

// ── Forecast (running period only): what is booked so far + the average daily non-recurring spend for
// the remaining days + recurring rules still scheduled in the period. Non-recurring income is not
// extrapolated (salary/rent come from recurring rules; one-off income would be invented money).
const forecast = computed(() => {
    const { running, end, activeDays: elapsedDays, elapsedEnd } = range.value
    if (!running) return null

    const remainingDays = daySpan(elapsedEnd, end) - 1
    const dailyVariable = sumExpenses(typicalSpending.value) / elapsedDays

    let scheduledIncome = 0
    let scheduledExpenses = 0
    let scheduledCount = 0
    for (const rule of recurringStore.rules) {
        if (!rule.active || !transactionsStore.matchesTagFilter(rule)) continue
        // From today: a rule due today whose transaction is not created yet still has next_run_at = today.
        const count = projectOccurrences(rule, elapsedEnd, end).length
        if (!count) continue
        scheduledCount += count
        if (rule.amount >= 0) scheduledIncome += rule.amount * count
        else scheduledExpenses -= rule.amount * count
    }

    const income = current.value.income + scheduledIncome
    const expenses = current.value.expenses + dailyVariable * remainingDays + scheduledExpenses
    const net = income - expenses
    const parts = [`${remainingDays} day${remainingDays === 1 ? '' : 's'} left`]
    parts.push(`~${formatMoney(Math.round(dailyVariable))}/day typical spend`)
    if (scheduledCount) {
        parts.push(
            `${scheduledCount} recurring payment${scheduledCount === 1 ? '' : 's'} due (${scheduledIncome ? `+${formatMoney(scheduledIncome)}` : ''}${scheduledIncome && scheduledExpenses ? ' / ' : ''}${scheduledExpenses ? `−${formatMoney(scheduledExpenses)}` : ''})`,
        )
    }

    return {
        items: [
            { label: 'Income', value: income, class: '' },
            { label: 'Expenses', value: expenses, class: '' },
            { label: 'Net', value: net, class: net >= 0 ? 'text-success' : 'text-error' },
        ],
        summary: parts.join(' · '),
        explanation: `Booked so far + typical daily spend (non-recurring, large one-offs left out) over ${elapsedDays} day${elapsedDays === 1 ? '' : 's'} × days left + active recurring transactions still scheduled before the ${periodWord.value} ends. One-off income is not extrapolated`,
    }
})

// ── Insights: deterministic one-liners, each shown only past a threshold.
const insights = computed(() => {
    const list = []
    const { activeDays: elapsedDays, activeStart: start, elapsedEnd, running } = range.value
    const total = current.value.expenses
    if (total <= 0) return list
    const vs = running ? `vs the same days last ${periodWord.value}` : `vs last ${periodWord.value}`

    // Biggest tag change (by money impact) among tags that matter (≥5% of spend), ≥25% and ≥2% of spend.
    const previousTotal = sumExpenses(previousToDate.value)
    const change = tagRows.value
        .filter((row) => row.key !== UNTAGGED && row.previous > 0)
        .filter((row) => Math.max(row.amount / total, row.previous / (previousTotal || 1)) >= 0.05)
        .map((row) => ({
            row,
            delta: row.amount - row.previous,
            pct: ((row.amount - row.previous) / row.previous) * 100,
        }))
        .filter((c) => Math.abs(c.pct) >= 25 && Math.abs(c.delta) >= total * 0.02)
        .sort((a, b) => Math.abs(b.delta) - Math.abs(a.delta))[0]
    if (change) {
        const sign = change.delta > 0 ? '+' : ''
        list.push({
            key: 'tag-change',
            icon: change.row.emoji || (change.delta > 0 ? '📈' : '📉'),
            text: `${change.row.title} ${sign}${change.pct.toFixed(0)}% ${vs} (${sign}${formatMoney(Math.round(change.delta))})`,
        })
    }

    const spending = filteredTransactions.value.filter((t) => t.amount < 0 && dayOf(t) <= elapsedEnd)

    // Priciest weekday: average typical spend per occurrence of that weekday, ≥25% above a typical day.
    if (elapsedDays >= 14) {
        const sums = Array(7).fill(0)
        const counts = Array(7).fill(0)
        for (let i = 0; i < elapsedDays; i++) counts[parseLocalDate(shiftDays(start, i)).getDay()]++
        for (const t of typicalSpending.value) sums[parseLocalDate(dayOf(t)).getDay()] -= t.amount
        const daily = sums.reduce((a, b) => a + b, 0) / elapsedDays
        const averages = sums.map((sum, day) => (counts[day] ? sum / counts[day] : 0))
        const top = averages.indexOf(Math.max(...averages))
        if (daily > 0 && averages[top] >= daily * 1.25) {
            list.push({
                key: 'weekday',
                icon: '📅',
                text: `${WEEKDAYS[top]}s cost the most: ~${formatMoney(Math.round(averages[top]))} vs ~${formatMoney(Math.round(daily))} on a typical day`,
            })
        }
    }

    // No-spend days (needs a week of data).
    if (elapsedDays >= 7) {
        const spendDays = new Set(spending.map(dayOf))
        const noSpend = elapsedDays - spendDays.size
        if (noSpend > 0) {
            list.push({
                key: 'no-spend',
                icon: '🌱',
                text: `${noSpend} no-spend day${noSpend === 1 ? '' : 's'} out of ${elapsedDays}${running ? ' so far' : ''}`,
            })
        }
    }

    // One expense taking a big bite (≥15% of spend, among at least 10 expenses).
    const biggest = largestExpenses.value[0]
    if (biggest && spending.length >= 10 && biggest.share >= 15) {
        list.push({
            key: 'biggest',
            icon: '💸',
            text: `${biggest.label} alone is ${formatPercentage(biggest.share)} of this ${periodWord.value}'s spending (${formatMoney(-biggest.amount)})`,
        })
    }

    return list.slice(0, 4)
})

// ── Balances Per Tag: all-time by default (the long-standing behaviour), or the selected period.
const STORAGE_SCOPE = 'analytics:tagBalances:scope'
const STORAGE_OPEN = 'analytics:tagBalances:open'
const balanceScopes = [
    { value: 'period', label: 'This period' },
    { value: 'all', label: 'All time' },
]
const balancesScope = ref(localStorage.getItem(STORAGE_SCOPE) === 'period' ? 'period' : 'all')
const balancesOpenPref = ref(localStorage.getItem(STORAGE_OPEN) !== 'false')
const balancesOpen = computed(() => inReport || balancesOpenPref.value)
const setBalancesScope = (value) => {
    balancesScope.value = value
    localStorage.setItem(STORAGE_SCOPE, value)
}
const toggleBalances = () => {
    balancesOpenPref.value = !balancesOpenPref.value
    localStorage.setItem(STORAGE_OPEN, String(balancesOpenPref.value))
}

const groupByTag = (list) => {
    const map = new Map()
    for (const t of list) {
        for (const tag of t.tags ?? []) {
            const group = map.get(tag.id) ?? map.set(tag.id, []).get(tag.id)
            group.push(t)
        }
    }
    return map
}

const balancesByTags = computed(() => {
    const grouped =
        balancesScope.value === 'period'
            ? groupByTag(
                  transactionsStore.filteredByDateRange(props.dateRange.currentStart, props.dateRange.currentEnd),
              )
            : transactionsStore.groupedByTags()
    const selected = transactionsStore.tagFilterActive ? new Set(transactionsStore.selectedTagIds) : null

    const rows = tagsStore.forBalances().map((tag) => {
        const ids = tagsStore.descendantIds(tag.id)
        const seen = new Set()
        let balance = 0

        for (const id of ids) {
            for (const transaction of grouped.get(id) || []) {
                if (seen.has(transaction.id)) continue
                seen.add(transaction.id)
                balance += transaction.amount
            }
        }

        return { ...tag, balance, txns: seen.size }
    })

    if (!inReport) return rows
    return rows.filter((tag) => tag.balance !== 0 && (!selected || selected.has(tag.id)))
})
</script>

<style scoped></style>
