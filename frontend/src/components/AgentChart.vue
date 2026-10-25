<template>
    <div class="card card-border bg-base-100 w-full">
        <div class="card-body p-3 gap-2">
            <div>
                <p class="font-semibold text-sm">{{ chart.title }}</p>
                <p class="text-xs text-base-content/60">{{ chart.subtitle }}</p>
            </div>

            <template v-if="chart.type === 'expenses_by_tag'">
                <p v-if="!tagRows.length" class="text-sm text-base-content/60">No expenses in this period.</p>
                <TagDonutChart v-else-if="chart.style !== 'bar'" :tags-with-amount="tagRows" />
                <ul v-else class="flex flex-col gap-1.5">
                    <li v-for="row in tagRows" :key="row.id" class="text-xs">
                        <div class="flex justify-between gap-2">
                            <span class="truncate">{{ row.emoji }} {{ row.title }}</span>
                            <span class="tabular-nums">{{ formatMoney(row.amount) }}</span>
                        </div>
                        <progress class="progress progress-error w-full" :value="row.amount" :max="maxTag" />
                    </li>
                </ul>
            </template>

            <template v-else-if="chart.type === 'income_vs_expenses'">
                <p v-if="!hasBuckets" class="text-sm text-base-content/60">No transactions in this period.</p>
                <BarChart v-else :balances="bars" />
            </template>

            <template v-else-if="chart.type === 'balance_trend'">
                <TrendChart :balances="trend" />
            </template>

            <div class="flex flex-wrap gap-x-3 gap-y-1 text-xs text-base-content/70">
                <span>Income <span class="text-success tabular-nums">{{ formatMoney(chart.income ?? 0) }}</span></span>
                <span>Expenses <span class="text-error tabular-nums">{{ formatMoney(chart.expenses ?? 0) }}</span></span>
                <span>Net <span class="tabular-nums">{{ formatMoney(chart.net ?? 0) }}</span></span>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, inject } from 'vue'
import BarChart from './charts/BarChart.vue'
import TagDonutChart from './charts/TagDonutChart.vue'
import TrendChart from './charts/TrendChart.vue'

const props = defineProps({
    chart: {
        type: Object,
        required: true,
    },
})

const formatMoney = inject('formatMoney')

const tagRows = computed(() =>
    (props.chart.rows ?? []).map((row) => ({
        id: row.id ?? row.label,
        emoji: row.emoji ?? '',
        title: row.title ?? row.label ?? '',
        amount: Number(row.value ?? 0),
        count: row.count ?? 0,
    })),
)

const maxTag = computed(() => Math.max(1, ...tagRows.value.map((row) => row.amount)))

const hasBuckets = computed(() =>
    (props.chart.buckets ?? []).some((bucket) => Number(bucket.income) !== 0 || Number(bucket.expenses) !== 0),
)

const bars = computed(() => {
    const out = {}
    for (const bucket of props.chart.buckets ?? []) {
        out[bucket.date ?? bucket.label] = {
            positive: Number(bucket.income ?? 0),
            negative: -Math.abs(Number(bucket.expenses ?? 0)),
        }
    }
    return out
})

const trend = computed(() => {
    const currentBalances = {}
    for (const point of props.chart.points ?? []) {
        currentBalances[point.date ?? point.label] = Number(point.value ?? 0)
    }
    return { currentBalances, previousBalances: {} }
})
</script>
