<template>
    <div class="card card-border border-base-300 bg-base-100 mb-4">
        <div class="card-body">
            <div class="flex justify-between items-center">
                <h2 class="card-title">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"
                        xmlns="http://www.w3.org/2000/svg">
                        <path fill-rule="evenodd" clip-rule="evenodd"
                            d="M17.2059 1.85607C16.1431 1.43738 15.116 1.72081 14.389 2.36741C13.6798 2.99812 13.25 3.96977 13.25 4.99991V8.99991C13.25 9.9664 14.0335 10.7499 15 10.7499H19C20.0301 10.7499 21.0018 10.3201 21.6325 9.6109C22.2791 8.88387 22.5625 7.85678 22.1438 6.794C21.2558 4.53979 19.4601 2.74411 17.2059 1.85607ZM14.75 8.99991V4.99991C14.75 4.37318 15.0149 3.81818 15.3858 3.48826C15.7389 3.17423 16.1774 3.06307 16.6561 3.25167C18.5233 3.98725 20.0127 5.47661 20.7482 7.34379C20.9368 7.82255 20.8257 8.26097 20.5117 8.61406C20.1817 8.98502 19.6267 9.24991 19 9.24991H15C14.8619 9.24991 14.75 9.13798 14.75 8.99991Z" />
                        <path fill-rule="evenodd" clip-rule="evenodd"
                            d="M10.9953 2.86985C10.3847 2.47432 9.79417 2.36045 9.1457 2.47353C8.59499 2.56955 8.00554 2.83371 7.37816 3.11487L7.31056 3.14515C6.78871 3.37886 6.28506 3.65684 5.80541 3.97733C4.11981 5.10362 2.80604 6.70445 2.03024 8.57739C1.25444 10.4503 1.05146 12.5113 1.44696 14.4996C1.84245 16.4879 2.81867 18.3143 4.25216 19.7477C5.68565 21.1812 7.51202 22.1574 9.50033 22.5529C11.4886 22.9484 13.5496 22.7455 15.4225 21.9697C17.2955 21.1939 18.8963 19.8801 20.0226 18.1945C20.3431 17.7148 20.621 17.2112 20.8547 16.6893L20.885 16.6217C21.1662 15.9944 21.4303 15.4049 21.5264 14.8542C21.6394 14.2057 21.5256 13.6152 21.1301 13.0046C20.7036 12.3465 20.1199 12.0248 19.406 11.8791C18.7721 11.7497 17.98 11.7498 17.0722 11.7499L15.5 11.7499C14.536 11.7499 13.8884 11.7483 13.4054 11.6834C12.9439 11.6213 12.7464 11.5141 12.6161 11.3838C12.4858 11.2535 12.3786 11.056 12.3165 10.5945C12.2516 10.1115 12.25 9.46391 12.25 8.49989L12.25 6.92772C12.2501 6.0199 12.2502 5.22783 12.1208 4.59394C11.9751 3.88004 11.6534 3.29625 10.9953 2.86985ZM7.92364 4.51414C8.64284 4.19206 9.05972 4.01115 9.40336 3.95123C9.66896 3.90492 9.87762 3.93306 10.1797 4.12874C10.434 4.29354 10.5687 4.49025 10.6511 4.89395C10.7464 5.36092 10.75 5.99833 10.75 6.99989L10.75 8.55189C10.75 9.45036 10.7499 10.1996 10.8299 10.7944C10.9143 11.4222 11.1 11.989 11.5555 12.4444C12.0109 12.8999 12.5777 13.0856 13.2055 13.17C13.8003 13.2499 14.5495 13.2499 15.448 13.2499L17 13.2499C18.0016 13.2499 18.639 13.2535 19.1059 13.3488C19.5096 13.4312 19.7064 13.5659 19.8712 13.8202C20.0668 14.1223 20.095 14.3309 20.0487 14.5965C19.9887 14.9402 19.8078 15.3571 19.4858 16.0763C19.2863 16.5217 19.049 16.9517 18.7754 17.3611C17.8139 18.8001 16.4473 19.9216 14.8485 20.5838C13.2496 21.2461 11.4903 21.4194 9.79296 21.0818C8.09563 20.7441 6.53653 19.9108 5.31282 18.6871C4.08911 17.4634 3.25575 15.9043 2.91813 14.2069C2.58051 12.5096 2.75379 10.7503 3.41606 9.15141C4.07833 7.55256 5.19983 6.186 6.63876 5.22454C7.04824 4.95093 7.47817 4.71364 7.92364 4.51414Z" />
                    </svg>
                    Tag Distribution
                </h2>

                <select v-model="viewMode" class="select select-sm select-bordered w-30">
                    <option value="donuts">Donuts</option>
                    <option value="list">List</option>
                </select>
            </div>

            <div v-if="viewMode === 'donuts'" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <h3 class="text-lg font-medium mb-2">Income</h3>
                    <DoughnutChart :data="positiveChartData" :options="positiveChartOptions" />
                </div>
                <div>
                    <h3 class="text-lg font-medium mb-2">Expenses</h3>
                    <DoughnutChart :data="negativeChartData" :options="negativeChartOptions" />
                </div>
            </div>

            <!-- Table View -->
            <div v-else-if="viewMode === 'list'" class="list gap-2 overflow-x-auto">
                <div>
                    <h3 class="text-lg font-medium mb-0">Income</h3>
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
                                    {{ formatPercentage(calculatePercentageDiff(positiveUntagged.amount,
                                        positiveUntagged.previousAmount)) }}
                                </td>
                            </tr>
                            <tr v-for="tag in filteredPositiveTagTree" :key="tag.id">
                                <td>
                                    <div class="flex items-center gap-2" :class="getIndentClass(tag)">
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
                                    {{ formatPercentage(calculatePercentageDiff(tag.amount, tag.previousAmount)) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div>
                    <h3 class="text-lg font-medium mb-0">Expenses</h3>
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
                                    {{ formatPercentage(calculatePercentageDiff(negativeUntagged.amount,
                                        negativeUntagged.previousAmount)) }}
                                </td>
                            </tr>
                            <tr v-for="tag in filteredNegativeTagTree" :key="tag.id">
                                <td>
                                    <div class="flex items-center gap-2" :class="getIndentClass(tag)">
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
                                    {{ formatPercentage(calculatePercentageDiff(tag.amount, tag.previousAmount)) }}
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
    if (previous === 0) return current > 0 ? Number.POSITIVE_INFINITY : 0
    return ((current - previous) / previous) * 100
}

const formatPercentage = (value) => {
    if (value === Number.POSITIVE_INFINITY) return '∞'
    if (value === Number.NEGATIVE_INFINITY) return '-∞'
    if (Number.isNaN(value)) return 'N/A'
    return `${value.toFixed(1)}%`
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
        if (tag && (tag.amount > 0 || tag.count > 0 || tag.previousAmount > 0)) {
            // Include tags with previous amounts
            treeArray.push(tag)
            const children = Array.from(tagMap.values())
                .filter((t) => t.parent_id === tagId)
                .sort((a, b) => a.title.localeCompare(b.title))
            children.forEach((child) => addTagWithChildren(child.id, treeArray))
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

const getIndentClass = (tag) => {
    const tagMap = new Map(tagsStore.tags.map((t) => [t.id, t]))
    const depth = getTagDepth(tag, tagMap)
    return `ml-${depth * 4}`
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

<style scoped>
.list .table td:first-child {
    padding-left: 0;
}

.list .table tr:first-child {
    border-top: var(--border) solid color-mix(in oklch, var(--color-base-content) 5%, #0000);
}

.list .table tr:last-child {
    border-bottom: var(--border) solid color-mix(in oklch, var(--color-base-content) 5%, #0000);
}
</style>
