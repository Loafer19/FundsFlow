<template>
    <dialog ref="dialog" class="modal" aria-labelledby="tag_detail_modal_title" @close="isOpen = false">
        <div class="modal-box max-w-2xl w-full overflow-x-hidden p-4 sm:p-6">
            <template v-if="tag && isOpen">
                <div class="flex items-start justify-between gap-2 mb-4 min-w-0">
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="badge badge-soft text-2xl py-5 px-2 shrink-0"
                            :class="isExpenses ? 'badge-error' : 'badge-success'">
                            {{ tag.emoji }}
                        </span>
                        <div class="min-w-0">
                            <h2 id="tag_detail_modal_title" class="card-title text-lg min-w-0">
                                <span class="truncate">{{ tag.title }}</span>
                            </h2>
                            <p class="text-xs text-base-content/60 truncate">
                                {{ isExpenses ? 'Expenses' : 'Income' }} · {{ periodLabel }}
                                <template v-if="parentTag">
                                    · in
                                    <button type="button" class="link link-hover" @click="openTag(parentTag.id)">
                                        {{ parentTag.emoji }} {{ parentTag.title }}
                                    </button>
                                </template>
                            </p>
                        </div>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm btn-square shrink-0" aria-label="Close"
                        @click="close">
                        <X :size="18" />
                    </button>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 sm:gap-3">
                    <StatCard class="col-span-2 sm:col-span-1" label="Total" :current="current.total" :previous="previous.total"
                        :icon="isExpenses ? 'expenses' : 'income'" :lowerIsBetter="isExpenses" />
                    <StatCard label="Transactions" :current="current.count" :previous="previous.count" format="count"
                        icon="count" neutral />
                    <StatCard label="Avg amount" hint="Average per transaction" :current="current.avg" :previous="previous.avg" icon="avg"
                        :lowerIsBetter="isExpenses" />
                </div>

                <section class="mt-5">
                    <h3 class="text-sm font-medium text-base-content/70 mb-1">Last 12 months</h3>
                    <BarChart :balances="monthly" :labelFormat="monthLabel" :tooltipFormat="monthTitle"
                        :aspectRatio="2.6" :maxTickRotation="0" />
                </section>

                <section v-if="subtags.length" class="mt-5">
                    <h3 class="text-sm font-medium text-base-content/70 mb-1">Subtags</h3>
                    <ul class="flex flex-col min-w-0">
                        <li v-for="row in subtags" :key="row.id" class="border-b border-base-300/60 last:border-b-0">
                            <button type="button"
                                class="w-full text-left py-2 flex flex-col gap-1 min-w-0 rounded-field hover:bg-base-200/60 px-1 -mx-1 cursor-pointer"
                                :aria-label="`Open ${row.title} details`" @click="openTag(row.id)">
                                <span class="flex items-center justify-between gap-2 min-w-0">
                                    <span class="flex items-center gap-2 min-w-0">
                                        <span class="shrink-0 text-lg leading-none">{{ row.emoji }}</span>
                                        <span class="truncate">{{ row.title }}</span>
                                    </span>
                                    <span class="flex items-center gap-2 shrink-0">
                                        <span v-if="row.change.label" class="badge badge-sm tabular-nums"
                                            :class="row.change.badge">
                                            {{ row.change.label }}
                                        </span>
                                        <span class="font-semibold tabular-nums">{{ formatMoney(row.amount) }}</span>
                                    </span>
                                </span>
                                <span class="flex items-center gap-2 min-w-0">
                                    <progress class="progress h-1.5 flex-1"
                                        :class="isExpenses ? 'progress-error' : 'progress-success'"
                                        :value="row.share" max="100"></progress>
                                    <span class="text-xs text-base-content/60 tabular-nums w-12 text-right">
                                        {{ formatPercentage(row.share) }}
                                    </span>
                                </span>
                            </button>
                        </li>
                    </ul>
                </section>

                <section class="mt-5">
                    <div class="flex items-center gap-2 mb-1">
                        <h3 class="text-sm font-medium text-base-content/70">Largest transactions</h3>
                        <button type="button" class="btn btn-ghost btn-xs ml-auto font-normal" @click="seeAll">
                            See all
                            <ChevronRight :size="14" />
                        </button>
                    </div>
                    <p v-if="!largest.length" class="text-sm text-base-content/60 py-2">
                        No transactions in this period
                    </p>
                    <ul v-else class="flex flex-col min-w-0">
                        <li v-for="t in largest" :key="t.id"
                            class="py-2 flex items-center gap-3 min-w-0 border-b border-base-300/60 last:border-b-0">
                            <span class="flex flex-col min-w-0 flex-1">
                                <span class="truncate">{{ t.note || t.tagLabel || '-' }}</span>
                                <span class="text-xs text-base-content/60 truncate">
                                    {{ formatDate(t.at) }}<template v-if="t.note && t.tagLabel"> · {{ t.tagLabel }}</template>
                                </span>
                            </span>
                            <span class="font-semibold tabular-nums shrink-0"
                                :class="isExpenses ? 'text-error' : 'text-success'">
                                {{ isExpenses ? '-' : '+' }}{{ formatMoney(t.value) }}
                            </span>
                        </li>
                    </ul>
                </section>
            </template>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button>close</button>
        </form>
    </dialog>
</template>

<script setup>
import { ChevronRight, X } from 'lucide-vue-next'
import { computed, inject, ref } from 'vue'
import { tagChange } from '../services/comparison.js'
import { toLocalDateStr } from '../services/formatters.js'
import { useTagsStore } from '../services/tags.js'
import { useTransactionsStore } from '../services/transactions.js'
import BarChart from './charts/BarChart.vue'
import StatCard from './StatCard.vue'

const props = defineProps({
    dateRange: {
        type: Object,
        required: true,
    },
})

const emit = defineEmits(['jump-to-list'])

const tagsStore = useTagsStore()
const transactionsStore = useTransactionsStore()
const formatMoney = inject('formatMoney')
const formatPercentage = inject('formatPercentage')
const formatDate = inject('formatDate')

const dialog = ref(null)
const isOpen = ref(false)
const tagId = ref(null)
// 'expenses' | 'income': which side of the Tags tab the tag was opened from.
const side = ref('expenses')
const isExpenses = computed(() => side.value === 'expenses')

const tag = computed(() => tagsStore.tags.find((t) => t.id === tagId.value) ?? null)
const parentTag = computed(() =>
    tag.value?.parent_id == null ? null : (tagsStore.tags.find((t) => t.id === tag.value.parent_id) ?? null),
)

// The tag with all of its descendants; the global tag filter is ignored here (the tag is the filter).
const ids = computed(() => (tagId.value == null ? new Set() : tagsStore.descendantIds(tagId.value)))
const onSide = (t) => (isExpenses.value ? t.amount < 0 : t.amount > 0)
const hasAny = (t, set) => (t.tags ?? []).some((x) => set.has(x.id))
const inRange = (start, end) =>
    transactionsStore.filteredByDateRange(start, end).filter((t) => onSide(t) && hasAny(t, ids.value))

const summarize = (list) => {
    const total = list.reduce((acc, t) => acc + Math.abs(t.amount), 0)
    return { list, total, count: list.length, avg: list.length ? total / list.length : null }
}
const current = computed(() => summarize(inRange(props.dateRange.currentStart, props.dateRange.currentEnd)))
const previous = computed(() => summarize(inRange(props.dateRange.previousStart, props.dateRange.previousEnd)))

const periodLabel = computed(() => {
    const start = formatDate(props.dateRange.currentStart)
    const end = formatDate(props.dateRange.currentEnd)
    return start === end ? start : `${start} – ${end}`
})

// ── Monthly trend: the 12 calendar months ending with the month the current period ends in.
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
const monthKeys = computed(() => {
    const [year, month] = toLocalDateStr(props.dateRange.currentEnd).split('-').map(Number)
    return Array.from({ length: 12 }, (_, i) => {
        const date = new Date(year, month - 1 - (11 - i), 1)
        return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`
    })
})
const monthTitle = (key) => `${MONTHS[Number(key.slice(5)) - 1]} ${key.slice(0, 4)}`
// "Nov '25 Dec Jan '26 …": the year only on the first bar and on January.
const monthLabel = (key) => {
    const name = MONTHS[Number(key.slice(5)) - 1]
    return key === monthKeys.value[0] || key.endsWith('-01') ? `${name} '${key.slice(2, 4)}` : name
}

const monthly = computed(() => {
    const out = Object.fromEntries(monthKeys.value.map((key) => [key, { positive: 0, negative: 0 }]))
    const bucket = isExpenses.value ? 'negative' : 'positive'
    for (const t of transactionsStore.transactions) {
        if (!onSide(t) || !hasAny(t, ids.value)) continue
        const row = out[toLocalDateStr(t.at).slice(0, 7)]
        // Expenses are plotted as positive red bars so the chart reads like the totals above.
        if (row) row[bucket] += Math.abs(t.amount)
    }
    return out
})

// ── Direct child tags, each with its own descendants (share of this tag's total).
const subtags = computed(() => {
    const total = current.value.total
    return tagsStore.tags
        .filter((child) => child.parent_id === tagId.value)
        .map((child) => {
            const childIds = tagsStore.descendantIds(child.id)
            const amount = summarize(current.value.list.filter((t) => hasAny(t, childIds))).total
            const previousAmount = summarize(previous.value.list.filter((t) => hasAny(t, childIds))).total
            return {
                id: child.id,
                emoji: child.emoji,
                title: child.title,
                amount,
                share: total > 0 ? (amount / total) * 100 : 0,
                change: tagChange(amount, previousAmount, { lowerIsBetter: isExpenses.value, formatPercentage }),
            }
        })
        .filter((row) => row.amount > 0 || row.change.label)
        .sort((a, b) => b.amount - a.amount)
})

const largest = computed(() =>
    [...current.value.list]
        .sort((a, b) => Math.abs(b.amount) - Math.abs(a.amount))
        .slice(0, 5)
        .map((t) => ({
            id: t.id,
            at: t.at,
            note: t.note,
            value: Math.abs(t.amount),
            tagLabel: (t.tags ?? [])
                .filter((x) => x.id !== tagId.value)
                .map((x) => `${x.emoji} ${x.title}`)
                .join(', '),
        })),
)

const openTag = (id) => {
    tagId.value = id
    dialog.value?.querySelector('.modal-box')?.scrollTo({ top: 0 })
}

const open = (id, fromSide = 'expenses') => {
    side.value = fromSide
    openTag(id)
    isOpen.value = true
    dialog.value?.showModal()
}

const close = () => dialog.value?.close()

// Same as the Analytics jump-to-list: filter by the tag (incl. its children) and switch to the List tab.
const seeAll = () => {
    transactionsStore.filterUntagged = false
    transactionsStore.selectedTagIds = [...ids.value]
    close()
    emit('jump-to-list')
}

defineExpose({ open })
</script>
