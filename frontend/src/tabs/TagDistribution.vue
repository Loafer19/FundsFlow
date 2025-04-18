<template>
    <select v-model="viewMode" class="select select-sm select-bordered w-30 mb-3">
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
                    <h3 class="text-xl font-medium mb-0">Income</h3>
                    <table class="table mt-2" v-if="filteredPositiveTagTree.length > 0 || positiveUntagged.count > 0">
                        <tbody>
                            <tr v-if="positiveUntagged.count > 0 || positiveUntagged.previousAmount > 0">
                                <td>
                                    <div class="flex items-center gap-2">
                                        <div class="badge badge-soft badge-success text-xl py-4 px-2">🏷️</div>
                                        <span>Untagged</span>
                                    </div>
                                </td>
                                <td class="w-15 font-medium text-right">
                                    <span class="tooltip" :data-tip="positiveUntagged.count + ' txns'">
                                        {{ formatMoney(positiveUntagged.amount) }}
                                    </span>
                                </td>
                                <td class="w-15 text-right">
                                    <span class="tooltip" data-tip="Previous period">
                                        {{ formatMoney(positiveUntagged.previousAmount) }}
                                    </span>
                                </td>
                                <td class="w-15 text-right">
                                    <span :class="{
                                        'text-success': positiveUntagged.amount > positiveUntagged.previousAmount,
                                        'text-error': positiveUntagged.amount < positiveUntagged.previousAmount,
                                    }">
                                        {{ formatPercentage(calculatePercentageDiff(positiveUntagged.amount,
                                            positiveUntagged.previousAmount)) }}
                                    </span>
                                </td>
                            </tr>
                            <tr v-for="tag in filteredPositiveTagTree" :key="tag.id">
                                <td>
                                    <div class="flex items-center gap-2" :style="getIndent(tag)">
                                        <div class="badge badge-soft badge-success text-xl py-4 px-2">
                                            {{ tag.emoji }}
                                        </div>
                                        <span>{{ tag.title }}</span>
                                    </div>
                                </td>
                                <td class="w-15 font-medium text-right">
                                    <span class="tooltip" :data-tip="tag.count + ' txns'">
                                        {{ formatMoney(tag.amount) }}
                                    </span>
                                </td>
                                <td class="w-15 text-right">
                                    <span class="tooltip" data-tip="Previous period">
                                        {{ formatMoney(tag.previousAmount) }}
                                    </span>
                                </td>
                                <td class="w-15 text-right">
                                    <span :class="{
                                        'text-success': tag.amount > tag.previousAmount,
                                        'text-error': tag.amount < tag.previousAmount,
                                    }">
                                        {{ formatPercentage(calculatePercentageDiff(tag.amount, tag.previousAmount)) }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div>
                    <h3 class="text-xl font-medium mb-0">Expenses</h3>
                    <table class="table mt-2" v-if="filteredNegativeTagTree.length > 0 || negativeUntagged.count > 0">
                        <tbody>
                            <tr v-if="negativeUntagged.count > 0 || negativeUntagged.previousAmount > 0">
                                <td>
                                    <div class="flex items-center gap-2">
                                        <div class="badge badge-soft badge-error text-xl py-4 px-2">🏷️</div>
                                        <span>Untagged</span>
                                    </div>
                                </td>
                                <td class="w-15 font-medium text-right">
                                    <span class="tooltip" :data-tip="negativeUntagged.count + ' txns'">
                                        {{ formatMoney(negativeUntagged.amount) }}
                                    </span>
                                </td>
                                <td class="w-15 text-right">
                                    <span class="tooltip" data-tip="Previous period">
                                        {{ formatMoney(negativeUntagged.previousAmount) }}
                                    </span>
                                </td>
                                <td class="w-15 text-right">
                                    <span :class="{
                                        'text-success': negativeUntagged.amount < negativeUntagged.previousAmount,
                                        'text-error': negativeUntagged.amount > negativeUntagged.previousAmount,
                                    }">
                                        {{ formatPercentage(calculatePercentageDiff(negativeUntagged.amount,
                                            negativeUntagged.previousAmount)) }}
                                    </span>
                                </td>
                            </tr>
                            <tr v-for="tag in filteredNegativeTagTree" :key="tag.id">
                                <td>
                                    <div class="flex items-center gap-2" :style="getIndent(tag)">
                                        <div class="badge badge-soft badge-error text-xl py-4 px-2">
                                            {{ tag.emoji }}
                                        </div>
                                        <span>{{ tag.title }}</span>
                                    </div>
                                </td>
                                <td class="w-15 font-medium text-right">
                                    <span class="tooltip" :data-tip="tag.count + ' txns'">
                                        {{ formatMoney(tag.amount) }}
                                    </span>
                                </td>
                                <td class="w-15 text-right">
                                    <span class="tooltip" data-tip="Previous period">
                                        {{ formatMoney(tag.previousAmount) }}
                                    </span>
                                </td>
                                <td class="w-15 text-right">
                                    <span :class="{
                                        'text-success': tag.amount < tag.previousAmount,
                                        'text-error': tag.amount > tag.previousAmount,
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

ChartJS.register(ArcElement, Tooltip, Legend)

const props = defineProps({
    transactions: { type: Array, required: true },
    dateRange: { type: Object, required: true },
})

const tagsStore = useTagsStore()
const formatMoney = inject('formatMoney')
const formatPercentage = inject('formatPercentage')
const viewMode = ref('donuts')

// Normalize date to UTC midnight to avoid timezone issues
const normalizeDate = (date) => {
    const d = new Date(date)
    return new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate()))
}

// Calculate tag amounts and untagged transactions for a period
const calculateTagAmounts = (start, end) => {
    const startUTC = normalizeDate(start)
    const endUTC = normalizeDate(end)
    endUTC.setUTCDate(endUTC.getUTCDate() + 1)

    const result = {
        positive: {},
        negative: {},
        untaggedPositive: { amount: 0, count: 0 },
        untaggedNegative: { amount: 0, count: 0 },
    }

    props.transactions
        .filter((t) => {
            const date = new Date(t.at)
            const dateUTC = normalizeDate(date)
            return dateUTC >= startUTC && dateUTC < endUTC
        })
        .forEach((t) => {
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
    if (previous === 0) return current > 0 ? Number.POSITIVE_INFINITY : Number.NEGATIVE_INFINITY
    return ((current - previous) / previous) * 100
}

// Build tag tree with separate current and previous amounts
const buildTagTree = (currentData, previousData, isPositive) => {
    const current = isPositive ? currentData.positive : currentData.negative
    const previous = isPositive ? previousData.positive : previousData.negative

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
    const parent = tagMap.get(tag.parent_id)
    return parent ? getTagDepth(parent, tagMap, depth + 1) : depth
}

const getIndent = (tag) => {
    const tagMap = new Map(tagsStore.tags.map((t) => [t.id, t]))
    const depth = getTagDepth(tag, tagMap)
    return `padding-left: ${depth * 20}px;`
}

const currentPeriod = computed(() => calculateTagAmounts(props.dateRange.currentStart, props.dateRange.currentEnd))
const previousPeriod = computed(() => calculateTagAmounts(props.dateRange.previousStart, props.dateRange.previousEnd))

const positiveUntagged = computed(() => ({
    amount: currentPeriod.value.untaggedPositive.amount,
    count: currentPeriod.value.untaggedPositive.count,
    previousAmount: previousPeriod.value.untaggedPositive.amount,
}))

const negativeUntagged = computed(() => ({
    amount: currentPeriod.value.untaggedNegative.amount,
    count: currentPeriod.value.untaggedNegative.count,
    previousAmount: previousPeriod.value.untaggedNegative.amount,
}))

const filteredPositiveTagTree = computed(() => buildTagTree(currentPeriod.value, previousPeriod.value, true))

const filteredNegativeTagTree = computed(() => buildTagTree(currentPeriod.value, previousPeriod.value, false))

const generateRandomColor = () => {
    const lightness = Math.random() * 15 + 65
    const chroma = Math.random() * 0.6 + 0.14
    const hue = Math.random() * 360
    return `oklch(${lightness}% ${chroma} ${hue})`
}

const positiveChartData = computed(() => {
    const tagsWithAmount = filteredPositiveTagTree.value.filter((tag) => tag.amount > 0)
    if (positiveUntagged.value.amount > 0) {
        tagsWithAmount.unshift({ title: 'Untagged', emoji: '🏷️', amount: positiveUntagged.value.amount })
    }
    return {
        labels: tagsWithAmount.map((tag) => (tag.emoji || '💰') + ' ' + tag.title),
        datasets: [
            {
                data: tagsWithAmount.map((tag) => tag.amount),
                backgroundColor: tagsWithAmount.map(() => generateRandomColor()),
            },
        ],
    }
})

const negativeChartData = computed(() => {
    const tagsWithAmount = filteredNegativeTagTree.value.filter((tag) => tag.amount > 0)
    if (negativeUntagged.value.amount > 0) {
        tagsWithAmount.unshift({ title: 'Untagged', emoji: '🏷️', amount: negativeUntagged.value.amount })
    }
    return {
        labels: tagsWithAmount.map((tag) => (tag.emoji || '💸') + ' ' + tag.title),
        datasets: [
            {
                data: tagsWithAmount.map((tag) => tag.amount),
                backgroundColor: tagsWithAmount.map(() => generateRandomColor()),
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
                    `${formatMoney(positiveChartData.value.datasets[0].data[context.dataIndex])} - ${filteredPositiveTagTree.value[context.dataIndex]?.count || positiveUntagged.value.count} txns`,
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
                    `${formatMoney(negativeChartData.value.datasets[0].data[context.dataIndex])} - ${filteredNegativeTagTree.value[context.dataIndex]?.count || negativeUntagged.value.count} txns`,
            },
        },
    },
}
</script>

<style scoped></style>
