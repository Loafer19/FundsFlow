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
                    <div v-if="positiveTagsWithAmount.length > 0">
                        <TagDonutChart :tagsWithAmount="positiveTagsWithAmount" />
                    </div>
                    <div v-else>
                        <p class="text-gray-400">No income transactions found :(</p>
                    </div>
                </div>
                <div>
                    <h3 class="text-xl font-medium mb-2">Expenses</h3>
                    <div v-if="negativeTagsWithAmount.length > 0">
                        <TagDonutChart :tagsWithAmount="negativeTagsWithAmount" />
                    </div>
                    <div v-else>
                        <p class="text-gray-400">No expense transactions found :)</p>
                    </div>
                </div>
            </div>

            <div v-else-if="viewMode === 'list'" class="tags-list">
                <table class="table mt-2">
                    <tbody>
                        <tr>
                            <td colspan="4">
                                <span class="text-2xl font-medium">Income</span>
                            </td>
                        </tr>
                        <tr v-for="tag in filteredPositiveTagTree" :key="tag.id || 'untagged-positive'">
                            <td>
                                <div class="flex items-center gap-2" :style="{ paddingLeft: `${tag.depth * 20}px` }">
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
                                <div class="flex items-center gap-2" :style="{ paddingLeft: `${tag.depth * 20}px` }">
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
</template>

<script setup>
import { computed, inject, ref } from 'vue'
import TagDonutChart from '../components/charts/TagDonutChart.vue'
import { useTagsStore } from '../services/tags'
import { useTransactionsStore } from '../services/transactions'

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

const positiveTagsWithAmount = computed(() => {
    return filteredPositiveTagTree.value.filter((tag) => tag.amount > 0)
})

const negativeTagsWithAmount = computed(() => {
    return filteredNegativeTagTree.value.filter((tag) => tag.amount > 0)
})
</script>

<style scoped></style>
