<template>
    <EmptyState v-if="!budgetsStore.budgets.length" icon="🐷" title="No budgets yet"
        description="Set a limit on a few tags to keep spending in check." action-label="Add Budget"
        @action="openAdd" />

    <template v-else>
        <div class="flex justify-end mb-3">
            <button type="button" class="btn btn-primary btn-sm" @click="openAdd">
                <Plus :size="20" />
                Add Budget
            </button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div v-for="budget in budgetsStore.budgets" :key="budget.id"
                class="card card-border border-base-300 bg-base-100">
                <div class="card-body">
                    <div class="flex justify-between items-start gap-2">
                        <h3 class="card-title">
                            <template v-if="budget.title">{{ budget.title }}</template>
                            <template v-else-if="currentTags(budget).length">
                                <span v-for="tag in currentTags(budget)" :key="tag.id" class="tooltip"
                                    :data-tip="tag.title">{{ tag.emoji }}</span>
                            </template>
                            <template v-else>Budget</template>
                        </h3>

                        <div class="flex gap-1 shrink-0">
                            <button v-if="currentPeriod(budget)" type="button"
                                class="btn btn-outline btn-warning btn-square btn-sm" aria-label="Pause"
                                :disabled="budgetsStore.isLoading === budget.id" @click="budgetsStore.pause(budget.id)">
                                <Pause :size="20" />
                            </button>
                            <button v-else type="button" class="btn btn-outline btn-success btn-square btn-sm"
                                aria-label="Resume" :disabled="budgetsStore.isLoading === budget.id"
                                @click="budgetsStore.resume(budget.id)">
                                <Play :size="20" />
                            </button>

                            <button type="button" class="btn btn-outline btn-secondary btn-square btn-sm"
                                aria-label="Edit" @click="openEdit(budget)">
                                <Pencil :size="20" />
                            </button>

                            <DeleteHold :id="budget.id" :disabled="budgetsStore.isLoading"
                                :isLoading="budgetsStore.isLoading === budget.id"
                                @delete="(id) => budgetsStore.delete(id)" />
                        </div>
                    </div>

                    <template v-if="currentPeriod(budget)">
                        <div class="flex items-center gap-1 mb-1">
                            <span v-for="tag in currentPeriod(budget).tags" :key="tag.id" class="tooltip"
                                :data-tip="tag.title">{{ tag.emoji }}</span>
                            <span class="text-sm text-base-content/60 ml-auto tooltip"
                                :data-tip="percentTooltip(spentForActive(currentPeriod(budget)), currentPeriod(budget).amount)">
                                {{ formatMoney(spentForActive(currentPeriod(budget))) }} /
                                {{ formatMoney(currentPeriod(budget).amount) }} ·
                                {{ lengthLabel(currentPeriod(budget).length) }}
                            </span>
                        </div>
                        <div class="tooltip w-full"
                            :data-tip="percentTooltip(spentForActive(currentPeriod(budget)), currentPeriod(budget).amount)">
                            <progress class="progress w-full" :class="progressClass(currentPeriod(budget))"
                                :value="spentForActive(currentPeriod(budget))" :max="currentPeriod(budget).amount"></progress>
                        </div>
                    </template>
                    <div v-else class="flex items-center gap-1 mb-1">
                        <span v-for="tag in currentTags(budget)" :key="tag.id" class="tooltip"
                            :data-tip="tag.title">{{ tag.emoji }}</span>
                        <span class="text-sm text-base-content/60 ml-auto italic">Paused</span>
                    </div>

                    <template v-if="historyFor(budget).length">
                        <div class="divider text-xs text-base-content/60 my-2">History</div>

                        <template v-for="period in historyFor(budget)" :key="period.id">
                            <div class="flex items-center gap-1 mt-2">
                                <span v-for="tag in period.tags" :key="tag.id" class="tooltip"
                                    :data-tip="tag.title">{{ tag.emoji }}</span>
                                <span class="text-xs text-base-content/60 ml-auto">
                                    {{ formatMoney(period.amount) }} {{ lengthLabel(period.length) }}
                                </span>
                            </div>

                            <div v-for="bucket in historyBuckets(period)" :key="bucket.start"
                                class="flex justify-between text-sm text-base-content/60">
                                <span>{{ formatDate(bucket.start) }} – {{ formatDate(bucket.end) }}</span>
                                <span class="tooltip"
                                    :data-tip="percentTooltip(spentBetween(bucket.start, bucket.end, period.tags), period.amount)">
                                    {{ formatMoney(spentBetween(bucket.start, bucket.end, period.tags)) }} /
                                    {{ formatMoney(period.amount) }}
                                </span>
                            </div>
                        </template>
                    </template>
                </div>
            </div>
        </div>
    </template>
</template>

<script setup>
import { Pause, Pencil, Play, Plus } from 'lucide-vue-next'
import DeleteHold from '../components/buttons/DeleteHold.vue'
import EmptyState from '../components/EmptyState.vue'
import { formatDate, formatMoney, toLocalDateStr } from '../services/formatters.js'
import { useBudgetsStore } from '../services/budgets.js'
import { useTransactionsStore } from '../services/transactions.js'

const budgetsStore = useBudgetsStore()
const transactionsStore = useTransactionsStore()

const currentPeriod = (budget) => budget.periods.find((period) => period.active) ?? null
const historyFor = (budget) => budget.periods.filter((period) => !period.active)

const lengthLabel = (length) => ({ week: 'per week', month: 'per month', year: 'per year' })[length]

// "2026-01-16" as a wall-clock Date — new Date(dateOnlyString) parses as UTC
// midnight and can land on the wrong local day, which is exactly what
// toLocalDateStr()/filteredByDateRange() elsewhere are built to avoid.
const parseLocalDate = (dateStr) => {
    const [year, month, day] = dateStr.split('-').map(Number)

    return new Date(year, month - 1, day)
}

const bucketStartFor = (date, length) => {
    const d = new Date(date)

    if (length === 'week') d.setDate(d.getDate() - ((d.getDay() + 6) % 7))
    else if (length === 'month') d.setDate(1)
    else d.setMonth(0, 1)

    return d
}

const nextBucketStart = (date, length) => {
    const d = new Date(date)

    if (length === 'week') d.setDate(d.getDate() + 7)
    else if (length === 'month') d.setMonth(d.getMonth() + 1)
    else d.setFullYear(d.getFullYear() + 1)

    return d
}

const dayBefore = (dateStr) => {
    const d = parseLocalDate(dateStr)
    d.setDate(d.getDate() - 1)

    return toLocalDateStr(d)
}

const currentBucketStart = (period) => {
    const boundary = toLocalDateStr(bucketStartFor(new Date(), period.length))

    return boundary > period.starts_at ? boundary : period.starts_at
}

// Slices a closed period's whole [starts_at, ends_at] range into
// calendar-aligned week/month/year buckets, clamped at both ends, so a
// budget that ran unedited for months shows one comparable row per bucket
// instead of one misleading total against a per-bucket amount.
const historyBuckets = (period) => {
    const buckets = []
    let cursor = period.starts_at

    while (cursor <= period.ends_at) {
        const nextStr = toLocalDateStr(nextBucketStart(bucketStartFor(parseLocalDate(cursor), period.length), period.length))
        const end = nextStr <= period.ends_at ? dayBefore(nextStr) : period.ends_at

        buckets.push({ start: cursor, end })
        cursor = nextStr
    }

    return buckets
}

const spentBetween = (start, end, tags) => {
    const tagIds = new Set(tags.map((tag) => tag.id))

    return transactionsStore
        .filteredByDateRange(start, end)
        .filter((t) => t.amount < 0 && t.tags.some((tag) => tagIds.has(tag.id)))
        .reduce((sum, t) => sum - t.amount, 0)
}

// Only ever called with the active period — its current week/month/year
// bucket so far. Closed periods go through historyBuckets() instead.
const spentForActive = (period) => spentBetween(currentBucketStart(period), toLocalDateStr(new Date()), period.tags)

const currentTags = (budget) => (currentPeriod(budget) ?? budget.periods[0])?.tags ?? []

const percentTooltip = (spent, amount) => {
    if (amount <= 0) return '0%'

    return Math.round((spent / amount) * 100) + '%'
}

const progressClass = (period) => {
    const ratio = period.amount > 0 ? spentForActive(period) / period.amount : 0

    if (ratio >= 1) return 'progress-error'
    if (ratio >= 0.8) return 'progress-warning'

    return 'progress-success'
}

const openAdd = () => budgets_add_modal.showModal()

const openEdit = (budget) => {
    budgetsStore.budgetForEdit = budget
    budgets_edit_modal.showModal()
}
</script>

<style scoped></style>
