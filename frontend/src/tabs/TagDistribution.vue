<template>
    <select v-model="viewMode" class="select select-sm w-30 border-base-300 mb-3">
        <option value="donuts">Donuts</option>
        <option value="list">List</option>
    </select>

    <div class="card card-border border-base-300 bg-base-100">
        <div class="card-body">
            <div v-if="viewMode === 'donuts'" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <h3 class="text-xl font-medium mb-2">Income</h3>
                    <DoughnutChart :data="positiveChartData" :options="positiveChartOptions" />
                </div>
                <div>
                    <h3 class="text-xl font-medium mb-2">Expenses</h3>
                    <DoughnutChart :data="negativeChartData" :options="negativeChartOptions" />
                </div>
            </div>

            <div v-else-if="viewMode === 'list'" class="tags-list flex flex-col gap-2 overflow-x-auto">
                <div>
                    <table class="table mt-2">
                        <tbody>
                            <tr>
                                <td colspan="4">
                                    <span class="text-2xl font-medium">Income</span>
                                </td>
                            </tr>
                            <tr v-for="tag in filteredPositiveTagTree" :key="tag.id || 'untagged-positive'">
                                <td>
                                    <div class="flex items-center gap-2" :style="getIndent(tag)">
                                        <div class="badge badge-soft badge-success text-xl py-4 px-2">
                                            {{ tag.emoji }}
                                        </div>
                                        <span>{{ tag.title }}</span>
                                    </div>
                                </td>
                                <td class="w-15 text-right">
                                    <span class="text-gray-400 tooltip" data-tip="Previous period">
                                        {{ formatMoney(tag.previousAmount) }}
                                    </span>
                                </td>
                                <td class="w-15 font-medium text-right">
                                    <span class="tooltip" :data-tip="tag.count + ' txns'">
                                        {{ formatMoney(tag.amount) }}
                                    </span>
                                </td>
                                <td class="w-15 text-right">
                                    <span :class="{
                                        'text-success': tag.amount > tag.previousAmount,
                                        'text-error': tag.amount < tag.previousAmount,
                                        'text-secondary': tag.amount === tag.previousAmount,
                                    }">
                                        {{ formatPercentage(calculatePercentageDiff(tag.amount, tag.previousAmount)) }}
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="4">
                                    <span class="text-2xl font-medium">Expenses</span>
                                </td>
                            </tr>
                            <tr v-for="tag in filteredNegativeTagTree" :key="tag.id || 'untagged-negative'">
                                <td>
                                    <div class="flex items-center gap-2" :style="getIndent(tag)">
                                        <div class="badge badge-soft badge-error text-xl py-4 px-2">
                                            {{ tag.emoji }}
                                        </div>
                                        <span>{{ tag.title }}</span>
                                    </div>
                                </td>
                                <td class="w-15 text-right">
                                    <span class="text-gray-400 tooltip" data-tip="Previous period">
                                        {{ formatMoney(tag.previousAmount) }}
                                    </span>
                                </td>
                                <td class="w-15 font-medium text-right">
                                    <span class="tooltip" :data-tip="tag.count + ' txns'">
                                        {{ formatMoney(tag.amount) }}
                                    </span>
                                </td>
                                <td class="w-15 text-right">
                                    <span :class="{
                                        'text-success': tag.amount < tag.previousAmount,
                                        'text-error': tag.amount > tag.previousAmount,
                                        'text-secondary': tag.amount === tag.previousAmount,
                                    }">
                                        {{ formatPercentage(calculatePercentageDiff(tag.amount, tag.previousAmount)) }}
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
import { ArcElement, Chart as ChartJS, Legend, Tooltip } from 'chart.js'
import { computed, inject, ref } from 'vue'
import { Doughnut as DoughnutChart } from 'vue-chartjs'
import { useTagsStore } from '../services/tags'
import { useTransactionsStore } from '../services/transactions'

ChartJS.register(ArcElement, Tooltip, Legend)

const props = defineProps({
    dateRange: {
        type: Object,
        required: true,
    },
})

const tagsStore = useTagsStore()
const transactionsStore = useTransactionsStore()
const formatMoney = inject('formatMoney')
const formatPercentage = inject('formatPercentage')
const viewMode = ref('donuts')

// Calculate tag amounts and untagged transactions for a period
const calculateTagAmounts = (start, end) => {
    const result = {
        positive: {},
        negative: {},
        untaggedPositive: { amount: 0, count: 0 },
        untaggedNegative: { amount: 0, count: 0 },
    }

    transactionsStore.filteredByDateRange(start, end).forEach((t) => {
        const isPositive = t.amount > 0
        const target = isPositive ? result.positive : result.negative
        const untaggedTarget = isPositive ? result.untaggedPositive : result.untaggedNegative

        if (t.tags.length === 0) {
            untaggedTarget.amount += Math.abs(t.amount)
            untaggedTarget.count += 1
        } else {
            t.tags.forEach((tag) => {
                target[tag.id] = target[tag.id] || { ...tag, amount: 0, count: 0 }
                target[tag.id].amount += Math.abs(t.amount)
                target[tag.id].count += 1
            })
        }
    })

    return result
}

const calculatePercentageDiff = (current, previous) => {
    if (previous === 0) return current > 0 ? Number.POSITIVE_INFINITY : 0
    return ((current - previous) / previous) * 100
}

// Build tag tree including untagged transactions
const buildTagTree = (currentData, previousData, isPositive) => {
    const current = isPositive ? currentData.positive : currentData.negative
    const previous = isPositive ? previousData.positive : previousData.negative
    const untaggedCurrent = isPositive ? currentData.untaggedPositive : currentData.untaggedNegative
    const untaggedPrevious = isPositive ? previousData.untaggedPositive : previousData.untaggedNegative

    const tagMap = new Map(
        tagsStore.tags.map((tag) => [
            tag.id,
            {
                ...tag,
                amount: current[tag.id]?.amount || 0,
                count: current[tag.id]?.count || 0,
                previousAmount: previous[tag.id]?.amount || 0,
            },
        ]),
    )

    const tree = []

    // Add untagged as a pseudo-tag at root level
    if (untaggedCurrent.amount > 0 || untaggedCurrent.count > 0 || untaggedPrevious.amount > 0) {
        tree.push({
            id: null,
            title: 'Untagged',
            emoji: '🏷️',
            amount: untaggedCurrent.amount,
            count: untaggedCurrent.count,
            previousAmount: untaggedPrevious.amount,
            parent_id: null,
        })
    }

    const addTagWithChildren = (tagId, treeArray) => {
        const tag = tagMap.get(tagId)
        if (tag) {
            const hasActivity = tag.amount > 0 || tag.count > 0 || tag.previousAmount > 0
            const children = Array.from(tagMap.values())
                .filter((t) => t.parent_id === tagId)
                .sort((a, b) => a.title.localeCompare(b.title))

            const hasActiveChildren = children.some((child) => {
                const childTag = tagMap.get(child.id)
                return childTag.amount > 0 || childTag.count > 0 || childTag.previousAmount > 0
            })

            if (hasActivity || hasActiveChildren) {
                treeArray.push(tag)
                children.forEach((child) => addTagWithChildren(child.id, treeArray))
            }
        }
    }

    tagsStore.tags
        .filter((tag) => !tag.parent_id)
        .sort((a, b) => a.title.localeCompare(b.title))
        .forEach((tag) => addTagWithChildren(tag.id, tree))

    return tree
}

const getTagDepth = (tag, tagMap, depth = 0) => {
    if (!tag.parent_id) return depth
    return getTagDepth(tagMap.get(tag.parent_id), tagMap, depth + 1)
}

const getIndent = (tag) => {
    const tagMap = new Map(tagsStore.tags.map((t) => [t.id, t]))
    const depth = getTagDepth(tag, tagMap)
    return `padding-left: ${depth * 20}px;`
}

const currentPeriod = computed(() => calculateTagAmounts(props.dateRange.currentStart, props.dateRange.currentEnd))
const previousPeriod = computed(() => calculateTagAmounts(props.dateRange.previousStart, props.dateRange.previousEnd))

const filteredPositiveTagTree = computed(() => buildTagTree(currentPeriod.value, previousPeriod.value, true))
const filteredNegativeTagTree = computed(() => buildTagTree(currentPeriod.value, previousPeriod.value, false))

const positiveTagsWithAmount = computed(() => {
    return filteredPositiveTagTree.value.filter((tag) => tag.amount > 0)
})

const negativeTagsWithAmount = computed(() => {
    return filteredNegativeTagTree.value.filter((tag) => tag.amount > 0)
})

const generateRandomColor = () => {
    const lightness = Math.random() * 10 + 80
    const chroma = Math.random() * 0.12 + 0.08
    const hue = Math.random() * 360
    return `oklch(${lightness}% ${chroma} ${hue})`
}

const positiveChartData = computed(() => {
    return {
        labels: positiveTagsWithAmount.value.map((tag) => tag.emoji + ' ' + tag.title),
        datasets: [
            {
                data: positiveTagsWithAmount.value.map((tag) => tag.amount),
                backgroundColor: positiveTagsWithAmount.value.map(() => generateRandomColor()),
            },
        ],
    }
})

const negativeChartData = computed(() => {
    return {
        labels: negativeTagsWithAmount.value.map((tag) => tag.emoji + ' ' + tag.title),
        datasets: [
            {
                data: negativeTagsWithAmount.value.map((tag) => tag.amount),
                backgroundColor: negativeTagsWithAmount.value.map(() => generateRandomColor()),
            },
        ],
    }
})

const positiveChartOptions = {
    responsive: true,
    plugins: {
        legend: { position: 'bottom' },
        tooltip: {
            callbacks: {
                label: (context) =>
                    `${formatMoney(positiveChartData.value.datasets[0].data[context.dataIndex])} - ${positiveTagsWithAmount.value[context.dataIndex]?.count} txns`,
            },
        },
    },
}

const negativeChartOptions = {
    responsive: true,
    plugins: {
        legend: { position: 'bottom' },
        tooltip: {
            callbacks: {
                label: (context) =>
                    `${formatMoney(negativeChartData.value.datasets[0].data[context.dataIndex])} - ${negativeTagsWithAmount.value[context.dataIndex]?.count} txns`,
            },
        },
    },
}
</script>

<style scoped></style>
