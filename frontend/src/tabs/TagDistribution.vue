<template>
    <EmptyState v-if="!hasPeriodData" :icon="Tags" title="No tag activity for this period"
        description="Nothing in this period. Add a transaction or change the range."
        action-label="Add Transaction" @action="openAdd" />

    <template v-else>
        <div v-if="!forcedViewMode" class="flex flex-wrap items-center gap-3 mb-3">
            <SegmentedControl v-model="viewMode" size="sm" label="Tag distribution view" :options="viewModeOptions" />
        </div>

        <div class="grid grid-cols-1 gap-3 min-w-0" :class="viewMode === 'donuts' ? 'md:grid-cols-2' : 'lg:grid-cols-2'">
            <template v-for="section in sections" :key="section.key">
                <EmptyState v-if="!section.rows.length" size="sm" :icon="section.icon" :icon-class="section.iconClass"
                    :title="section.empty" description="Add a transaction or change the range."
                    :action-label="inReport ? '' : 'Add Transaction'" @action="openAdd" />

                <div v-else class="card card-border border-base-300 bg-base-100 min-w-0">
                    <div class="card-body p-4 gap-3 min-w-0">
                        <div class="min-w-0">
                            <h3 class="card-title text-base sm:text-lg min-w-0">
                                <component :is="section.icon" :size="22" class="shrink-0" :class="section.textClass" />
                                <span class="truncate">{{ section.title }}</span>
                                <span class="ml-auto tabular-nums shrink-0">{{ formatMoney(section.total) }}</span>
                            </h3>
                            <p class="text-xs text-base-content/60">
                                {{ formatPercentage(section.turnoverShare) }} of turnover
                            </p>
                        </div>

                        <template v-if="viewMode === 'donuts'">
                            <nav v-if="section.crumbs.length" class="flex flex-wrap items-center gap-1 text-sm min-w-0"
                                :aria-label="`${section.title} drill-down`">
                                <button type="button" class="link link-hover" @click="drillTo(section.key, 0)">All</button>
                                <template v-for="(crumb, index) in section.crumbs" :key="crumb.id">
                                    <ChevronRight :size="14" class="text-base-content/40 shrink-0" />
                                    <button v-if="index < section.crumbs.length - 1" type="button"
                                        class="link link-hover truncate" @click="drillTo(section.key, index + 1)">
                                        {{ crumb.emoji }} {{ crumb.title }}
                                    </button>
                                    <span v-else class="font-medium truncate">{{ crumb.emoji }} {{ crumb.title }}</span>
                                </template>
                                <button type="button" class="btn btn-ghost btn-xs ml-auto font-normal"
                                    @click="openDetail(section.crumbs.at(-1), section.key)">
                                    Details
                                    <ChevronRight :size="14" />
                                </button>
                            </nav>

                            <TagDonutChart :items="section.donutItems" :center-label="section.centerLabel"
                                :center-value="formatMoney(section.centerValue)" :clickable="!inReport"
                                @select="selectSlice(section.key, $event)" />
                        </template>

                        <TagBreakdownList v-else :rows="section.visibleRows" :badge="section.badge" :in-report="inReport"
                            @select="openDetail($event, section.key)" @toggle="toggleRow($event)"
                            @more="showAllRoots[section.key] = !showAllRoots[section.key]" />
                    </div>
                </div>
            </template>
        </div>

        <TagDetailModal v-if="!inReport" ref="detailModal" :dateRange="dateRange"
            @jump-to-list="emit('jump-to-list')" />
    </template>
</template>

<script setup>
import { ChevronRight, Tags, TrendingDown, TrendingUp } from '@lucide/vue'
import { computed, inject, reactive, ref } from 'vue'
import TagDonutChart from '../components/charts/TagDonutChart.vue'
import EmptyState from '../components/EmptyState.vue'
import SegmentedControl from '../components/SegmentedControl.vue'
import TagDetailModal from '../components/TagDetailModal.vue'
import TagBreakdownList from '../components/tags/TagBreakdownList.vue'
import { useChartTheme } from '../services/chartTheme.js'
import { tagChange } from '../services/comparison.js'
import { NEUTRAL_TAG_COLOR, tagColor } from '../services/tagColors.js'
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
const { narrow } = useChartTheme()

const hasPeriodData = computed(
    () =>
        transactionsStore.filteredByDateRangeAndTags(props.dateRange.currentStart, props.dateRange.currentEnd).length >
        0,
)
const viewModeOptions = [
    { value: 'donuts', label: 'Donuts' },
    { value: 'list', label: 'List' },
]

const totalsFor = (start, end) => {
    let income = 0
    let expenses = 0
    for (const row of transactionsStore.filteredByDateRangeAndTags(start, end)) {
        if (row.amount >= 0) income += row.amount
        else expenses += Math.abs(row.amount)
    }
    return { income, expenses }
}

const sectionTotals = computed(() => totalsFor(props.dateRange.currentStart, props.dateRange.currentEnd))

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

    if (untaggedCurrent.amount > 0 || untaggedCurrent.count > 0) {
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

        // Only tags active in this period (a parent's own totals include its sub-tags). A tag that was used
        // only in the previous period is not listed: it would be a row of zeros.
        if (tagData.amount > 0 || tagData.count > 0) tree.push(tagData)
    })

    return tree
}

const currentPeriod = computed(() => calculateTagAmounts(props.dateRange.currentStart, props.dateRange.currentEnd))
const previousPeriod = computed(() => calculateTagAmounts(props.dateRange.previousStart, props.dateRange.previousEnd))

const filteredPositiveTagTree = computed(() => buildTagTree(currentPeriod.value, previousPeriod.value, true))
const filteredNegativeTagTree = computed(() => buildTagTree(currentPeriod.value, previousPeriod.value, false))

// ── Sections (Income / Expenses) ─────────────────────────────────────────────────────────────────
// Rows are ordered as a tree with the biggest amount first at every level; "Untagged" always goes last.
const arrange = (tree, lowerIsBetter) => {
    const present = new Set(tree.filter((row) => row.id != null).map((row) => row.id))
    const children = new Map()
    const roots = []

    for (const row of tree) {
        const parentId = row.id != null && row.parent_id != null && present.has(row.parent_id) ? row.parent_id : null
        const entry = { ...row, parentId }
        if (parentId == null) roots.push(entry)
        else children.set(parentId, [...(children.get(parentId) || []), entry])
    }

    const byAmount = (a, b) => b.amount - a.amount
    const rowsOut = []
    const visit = (entry, depth) => {
        const kids = (children.get(entry.id) || []).sort(byAmount)
        rowsOut.push({
            ...entry,
            key: entry.id ?? 'untagged',
            depth,
            hasChildren: kids.length > 0,
            color: tagColor(entry.id),
            change: tagChange(entry.amount, entry.previousAmount, { lowerIsBetter, formatPercentage }),
        })
        for (const kid of kids) visit(kid, depth + 1)
    }

    roots.sort((a, b) => (a.id == null) - (b.id == null) || byAmount(a, b))
    for (const root of roots) visit(root, 0)

    const rootsSum = rowsOut.filter((row) => row.parentId == null).reduce((sum, row) => sum + row.amount, 0)

    return rowsOut.map((row) => ({ ...row, share: rootsSum > 0 ? (row.amount / rootsSum) * 100 : 0 }))
}

const SECTION_META = {
    income: {
        title: 'Income',
        empty: 'No income in this period',
        icon: TrendingUp,
        textClass: 'text-success',
        iconClass: 'bg-success/15 text-success',
        badge: 'badge-success',
    },
    expenses: {
        title: 'Expenses',
        empty: 'No expenses in this period',
        icon: TrendingDown,
        textClass: 'text-error',
        iconClass: 'bg-error/15 text-error',
        badge: 'badge-error',
    },
}

const incomeRows = computed(() => arrange(filteredPositiveTagTree.value, false))
const expenseRows = computed(() => arrange(filteredNegativeTagTree.value, true))

// Donut drill-down: the path of tag ids opened per side, and the list's per-row open/closed overrides.
const drillPath = reactive({ income: [], expenses: [] })
const showAllRoots = reactive({ income: false, expenses: false })
const openOverrides = reactive({})

// Phones start with sub-tags closed and only the top tags listed; desktop shows everything.
const TOP_ON_PHONE = 6
const isOpen = (row) => (inReport ? true : (openOverrides[row.key] ?? !narrow.value))
const toggleRow = (row) => {
    openOverrides[row.key] = !isOpen(row)
}

const listRows = (rows, key) => {
    const out = []
    const collapse = !inReport && narrow.value && !showAllRoots[key]
    const roots = rows.filter((row) => row.parentId == null)
    const hiddenRoots = collapse && roots.length > TOP_ON_PHONE + 1 ? roots.slice(TOP_ON_PHONE) : []
    const hidden = new Set(hiddenRoots.map((row) => row.key))
    let skipBelow = null
    let inHidden = false

    for (const row of rows) {
        if (row.parentId == null) {
            inHidden = hidden.has(row.key)
            skipBelow = null
        }
        if (inHidden) continue
        if (skipBelow !== null && row.depth > skipBelow) continue
        skipBelow = null

        const open = isOpen(row)
        out.push({ ...row, open })
        if (row.hasChildren && !open) skipBelow = row.depth
    }

    if (hiddenRoots.length) {
        out.push({
            key: 'more',
            more: hiddenRoots.length,
            amount: hiddenRoots.reduce((sum, row) => sum + row.amount, 0),
        })
    }

    return out
}

const donutFor = (rows, key, total) => {
    const crumbs = []
    for (const id of drillPath[key]) {
        const row = rows.find((candidate) => candidate.id === id)
        if (!row) break
        crumbs.push(row)
    }

    const parent = crumbs.at(-1) ?? null
    const slices = rows
        .filter((row) => (row.parentId ?? null) === (parent?.id ?? null) && row.amount > 0)
        .map((row) => ({
            key: row.key,
            id: row.id,
            title: row.title,
            emoji: row.emoji,
            amount: row.amount,
            count: row.count,
            color: row.color,
            clickable: row.clickable,
            drillable: row.hasChildren && !inReport,
        }))

    if (parent) {
        // Part of the tag that has no sub-tag of its own.
        const direct = parent.amount - slices.reduce((sum, slice) => sum + slice.amount, 0)
        if (direct > 0.005) {
            slices.push({
                key: `direct-${parent.id}`,
                id: null,
                title: `${parent.title} (no sub-tag)`,
                emoji: parent.emoji,
                amount: direct,
                count: 0,
                color: NEUTRAL_TAG_COLOR,
                clickable: false,
                drillable: false,
            })
        }
    }

    slices.sort((a, b) => b.amount - a.amount)

    return {
        crumbs,
        donutItems: slices,
        centerLabel: parent ? parent.title : 'Total',
        centerValue: parent ? parent.amount : total,
    }
}

const sections = computed(() => {
    const turnover = sectionTotals.value.income + sectionTotals.value.expenses
    const build = (key, rows, total) => ({
        key,
        ...SECTION_META[key],
        rows,
        total,
        turnoverShare: turnover > 0 ? (total / turnover) * 100 : 0,
        visibleRows: listRows(rows, key),
        ...donutFor(rows, key, total),
    })

    return [
        build('income', incomeRows.value, sectionTotals.value.income),
        build('expenses', expenseRows.value, sectionTotals.value.expenses),
    ]
})

const drillTo = (key, length) => {
    drillPath[key] = drillPath[key].slice(0, length)
}

// A slice with sub-tags opens them; a leaf slice opens the tag's details.
const selectSlice = (key, item) => {
    if (item.drillable) drillPath[key] = [...drillPath[key], item.id]
    else if (item.clickable && item.id != null) detailModal.value?.open(item.id, key)
}
</script>
