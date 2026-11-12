<template>
    <EmptyState v-if="!hasPeriodData" :icon="Tags" title="No tag activity for this period"
        description="Nothing in this period. Add a transaction or change the range."
        action-label="Add Transaction" @action="openAdd" />

    <template v-else>
        <div v-if="!forcedViewMode" class="flex flex-wrap items-center gap-3 mb-3">
            <SegmentedControl v-model="viewMode" size="sm" label="Tag distribution view" :options="viewModeOptions" />
        </div>

        <div v-if="!inReport" class="grid grid-cols-2 gap-3 mb-3">
            <StatCard icon="income" label="Income" :current="sectionTotals.income" :previous="null" :compare="false" />
            <StatCard icon="expenses" label="Expenses" :current="sectionTotals.expenses" :previous="null" :compare="false" />
        </div>

        <div class="card card-border border-base-300 bg-base-100">
            <div class="card-body">
                <div v-if="viewMode === 'donuts'" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <h3 class="card-title text-base sm:text-lg mb-2">
                            <TrendingUp :size="22" class="text-success shrink-0" />
                            Income
                        </h3>
                        <div v-if="positiveTagsWithAmount.length > 0">
                            <TagDonutChart :tagsWithAmount="positiveTagsWithAmount" :clickable="!inReport"
                                @select="openDetail($event, 'income')" />
                        </div>
                        <div v-else class="text-base-content/60 py-8 text-center">No income in this period</div>
                    </div>
                    <div>
                        <h3 class="card-title text-base sm:text-lg mb-2">
                            <TrendingDown :size="22" class="text-error shrink-0" />
                            Expenses
                        </h3>
                        <div v-if="negativeTagsWithAmount.length > 0">
                            <TagDonutChart :tagsWithAmount="negativeTagsWithAmount" :clickable="!inReport"
                                @select="openDetail($event, 'expenses')" />
                        </div>
                        <div v-else class="text-base-content/60 py-8 text-center">No expenses in this period</div>
                    </div>
                </div>

                <div v-else-if="viewMode === 'list'" class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div v-for="section in listSections" :key="section.key" class="tags-list min-w-0">
                        <h3 class="card-title text-base sm:text-lg mb-2">
                            <component :is="section.key === 'income' ? TrendingUp : TrendingDown" :size="22"
                                class="shrink-0" :class="section.key === 'income' ? 'text-success' : 'text-error'" />
                            {{ section.title }}
                        </h3>
                        <table class="table table-sm w-full">
                            <tbody>
                                <tr v-if="!section.rows.length">
                                    <td colspan="3" class="text-base-content/60">{{ section.empty }}</td>
                                </tr>
                                <tr v-for="tag in section.rows" :key="tag.id || `untagged-${section.key}`"
                                    :class="{ 'hover:bg-base-200/60': tag.clickable }">
                                    <!-- In the app the button fills the cell (its padding replaces the cell's); the print report
                                         keeps plain cells so the report table styles apply -->
                                    <td :class="{ 'p-0': !inReport }">
                                        <component :is="tag.clickable ? 'button' : 'div'"
                                            :type="tag.clickable ? 'button' : undefined"
                                            class="w-full flex items-center gap-2 text-left min-w-0"
                                            :class="{ 'px-3 py-2': !inReport, 'cursor-pointer': tag.clickable }"
                                            :style="{ paddingLeft: `${(inReport ? 0 : 12) + tag.depth * 20}px` }"
                                            :aria-label="tag.clickable ? `Open ${tag.title} details` : undefined"
                                            @click="tag.clickable && openDetail(tag, section.key)">
                                            <span class="badge badge-soft text-xl py-4 px-2" :class="section.badge">
                                                {{ tag.emoji }}
                                            </span>
                                            <span class="truncate">{{ tag.title }}</span>
                                        </component>
                                    </td>
                                    <td class="w-0 text-right whitespace-nowrap">
                                        <span v-if="tag.change.label"
                                            class="badge badge-sm tabular-nums tooltip tooltip-left"
                                            :class="tag.change.badge"
                                            :data-tip="`Previous period: ${formatMoney(tag.previousAmount)}`">
                                            {{ tag.change.label }}
                                        </span>
                                    </td>
                                    <td class="w-0 font-medium text-right whitespace-nowrap tabular-nums">
                                        <span class="tooltip tooltip-left" :data-tip="tag.count + ' txns'">
                                            {{ formatMoney(tag.amount) }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <TagDetailModal v-if="!inReport" ref="detailModal" :dateRange="dateRange"
            @jump-to-list="emit('jump-to-list')" />
    </template>
</template>

<script setup>
import { Tags, TrendingDown, TrendingUp } from '@lucide/vue'
import { computed, inject, ref } from 'vue'
import TagDonutChart from '../components/charts/TagDonutChart.vue'
import EmptyState from '../components/EmptyState.vue'
import SegmentedControl from '../components/SegmentedControl.vue'
import StatCard from '../components/StatCard.vue'
import TagDetailModal from '../components/TagDetailModal.vue'
import { tagChange } from '../services/comparison.js'
import { useTagsStore } from '../services/tags'
import { useTransactionsStore } from '../services/transactions'

const props = defineProps({
    dateRange: {
        type: Object,
        required: true,
    },
    /** When set (report print), lock Donuts/List and hide the switcher. */
    forcedViewMode: {
        type: String,
        default: null,
        validator: (value) => value == null || value === 'donuts' || value === 'list',
    },
})

const emit = defineEmits(['jump-to-list'])

const tagsStore = useTagsStore()
const transactionsStore = useTransactionsStore()
const formatMoney = inject('formatMoney')
const formatPercentage = inject('formatPercentage')
const viewMode = ref(props.forcedViewMode || 'donuts')
const openAdd = () => transactions_add_modal.showModal()
// ReportDocument renders this tab for print: no detail modal, tag rows are plain text.
const inReport = inject('inReport', false)
const detailModal = ref(null)
const openDetail = (tag, side) => detailModal.value?.open(tag.id, side)

const hasPeriodData = computed(
    () => transactionsStore.filteredByDateRangeAndTags(props.dateRange.currentStart, props.dateRange.currentEnd).length > 0,
)
const viewModeOptions = [
    { value: 'donuts', label: 'Donuts' },
    { value: 'list', label: 'List' },
]

const sectionTotals = computed(() => {
    const list = transactionsStore.filteredByDateRangeAndTags(props.dateRange.currentStart, props.dateRange.currentEnd)
    let income = 0
    let expenses = 0
    for (const row of list) {
        if (row.amount >= 0) income += row.amount
        else expenses += Math.abs(row.amount)
    }
    return { income, expenses }
})

const parentById = computed(() => new Map(tagsStore.tags.map((tag) => [tag.id, tag.parent_id ?? null])))

const selfAndAncestors = (id) => {
    const out = [id]
    let parent = parentById.value.get(id) ?? null
    while (parent != null && !out.includes(parent)) {
        out.push(parent)
        parent = parentById.value.get(parent) ?? null
    }
    return out
}

const calculateTagAmounts = (start, end) => {
    const result = {
        positive: {},
        negative: {},
        untaggedPositive: { amount: 0, count: 0 },
        untaggedNegative: { amount: 0, count: 0 },
    }

    transactionsStore.filteredByDateRangeAndTags(start, end).forEach((t) => {
        const isPositive = t.amount > 0
        const target = isPositive ? result.positive : result.negative
        const untaggedTarget = isPositive ? result.untaggedPositive : result.untaggedNegative

        if (t.tags.length === 0) {
            untaggedTarget.amount += Math.abs(t.amount)
            untaggedTarget.count += 1
        } else {
            // Each tag and all of its ancestors, once: a parent's total includes its child tags and a
            // transaction with several tags under one parent counts once for it (like Analytics).
            const ids = new Set()
            for (const tag of t.tags) for (const id of selfAndAncestors(tag.id)) ids.add(id)
            for (const id of ids) {
                target[id] = target[id] || { amount: 0, count: 0 }
                target[id].amount += Math.abs(t.amount)
                target[id].count += 1
            }
        }
    })

    return result
}

const buildTagTree = (currentData, previousData, isPositive) => {
    const current = isPositive ? currentData.positive : currentData.negative
    const previous = isPositive ? previousData.positive : previousData.negative
    const untaggedCurrent = isPositive ? currentData.untaggedPositive : currentData.untaggedNegative
    const untaggedPrevious = isPositive ? previousData.untaggedPositive : previousData.untaggedNegative

    const tagList = tagsStore.list()
    const tree = []

    if (untaggedCurrent.amount > 0 || untaggedCurrent.count > 0 || untaggedPrevious.amount > 0) {
        tree.push({
            id: null,
            clickable: false,
            title: 'Untagged',
            emoji: '🏷️',
            amount: untaggedCurrent.amount,
            count: untaggedCurrent.count,
            previousAmount: untaggedPrevious.amount,
            parent_id: null,
            depth: 0,
        })
    }

    tagList.forEach((tag) => {
        const tagData = {
            ...tag,
            amount: current[tag.id]?.amount || 0,
            count: current[tag.id]?.count || 0,
            previousAmount: previous[tag.id]?.amount || 0,
            clickable: !inReport,
        }

        const hasActivity = tagData.amount > 0 || tagData.count > 0 || tagData.previousAmount > 0
        const hasActiveChildren = tagList.some((child) => {
            if (child.parent_id === tag.id) {
                const childCurrent = current[child.id]?.amount || 0
                const childCount = current[child.id]?.count || 0
                const childPrevious = previous[child.id]?.amount || 0
                return childCurrent > 0 || childCount > 0 || childPrevious > 0
            }
            return false
        })

        if (hasActivity || hasActiveChildren) {
            tree.push(tagData)
        }
    })

    return tree
}

const currentPeriod = computed(() => calculateTagAmounts(props.dateRange.currentStart, props.dateRange.currentEnd))
const previousPeriod = computed(() => calculateTagAmounts(props.dateRange.previousStart, props.dateRange.previousEnd))

const filteredPositiveTagTree = computed(() => buildTagTree(currentPeriod.value, previousPeriod.value, true))
const filteredNegativeTagTree = computed(() => buildTagTree(currentPeriod.value, previousPeriod.value, false))

// Expenses: a drop is good; income: growth is good. "new" when the previous period had nothing.
const withChange = (tree, lowerIsBetter) =>
    tree.map((tag) => ({
        ...tag,
        change: tagChange(tag.amount, tag.previousAmount, { lowerIsBetter, formatPercentage }),
    }))

const listSections = computed(() => [
    {
        key: 'income',
        title: 'Income',
        empty: 'No income tags',
        badge: 'badge-success',
        rows: withChange(filteredPositiveTagTree.value, false),
    },
    {
        key: 'expenses',
        title: 'Expenses',
        empty: 'No expense tags',
        badge: 'badge-error',
        rows: withChange(filteredNegativeTagTree.value, true),
    },
])

// Donuts show top-level tags only (each includes its child tags), so no amount is drawn twice.
const positiveTagsWithAmount = computed(() => {
    return filteredPositiveTagTree.value.filter((tag) => tag.amount > 0 && tag.depth === 0)
})

const negativeTagsWithAmount = computed(() => {
    return filteredNegativeTagTree.value.filter((tag) => tag.amount > 0 && tag.depth === 0)
})
</script>
