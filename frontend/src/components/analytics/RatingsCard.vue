<template>
    <div class="card card-border border-base-300 bg-base-100 shadow min-w-0">
        <div class="card-body p-4 gap-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2 min-w-0">
                    <span class="shrink-0 rounded-lg p-1.5 bg-warning/15 text-warning">
                        <Star :size="16" class="fill-current" />
                    </span>
                    <div class="min-w-0">
                        <h3 class="card-title text-base mb-0 truncate">
                            {{ mode === 'value' ? 'Best value' : 'Best rated' }} · {{ ratings.group_field.label }}
                        </h3>
                        <p class="text-xs text-base-content/60">
                            {{ mode === 'value'
                                ? 'Sorted by rating stars per 100 spent'
                                : `Average ${ratings.rating_field.label.toLowerCase()} out of ${ratings.max}` }}
                        </p>
                    </div>
                </div>

                <div class="join shrink-0" role="group" aria-label="Ratings ranking">
                    <button
                        type="button"
                        class="btn btn-xs sm:btn-sm join-item"
                        :class="mode === 'rated' ? 'btn-primary' : 'border-base-300'"
                        :aria-pressed="mode === 'rated'"
                        @click="mode = 'rated'"
                    >
                        Best rated
                    </button>
                    <button
                        type="button"
                        class="btn btn-xs sm:btn-sm join-item tooltip tooltip-top"
                        data-tip="Rating per 100 spent"
                        :class="mode === 'value' ? 'btn-primary' : 'border-base-300'"
                        :aria-pressed="mode === 'value'"
                        @click="mode = 'value'"
                    >
                        Best value
                    </button>
                </div>
            </div>

            <ul class="flex flex-col gap-2.5 text-sm">
                <li
                    v-for="(row, index) in sortedRows"
                    :key="row.place"
                    class="rounded-field bg-base-200/45 px-3 py-2 flex flex-col gap-1.5"
                >
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2 min-w-0">
                            <span
                                class="badge badge-xs tabular-nums shrink-0"
                                :class="index === 0 ? 'badge-warning' : 'badge-ghost'"
                            >#{{ index + 1 }}</span>
                            <span class="font-medium truncate">{{ row.place }}</span>
                            <span class="text-xs text-base-content/55 shrink-0">
                                {{ row.visits }} {{ row.visits === 1 ? 'visit' : 'visits' }}<template
                                    v-if="row.rated_count !== row.visits"
                                > · {{ row.rated_count }} rated</template>
                            </span>
                        </div>

                        <div class="flex items-center gap-2.5 shrink-0 tabular-nums">
                            <span v-if="row.avg_check > 0" class="text-xs font-medium text-error">
                                {{ money(row.avg_check) }} avg
                            </span>
                            <span
                                v-if="mode === 'value' && row.value !== null"
                                class="badge badge-sm badge-outline font-medium"
                            >
                                {{ formatNum(row.value) }} ★/100
                            </span>
                            <span class="inline-flex items-center gap-1 font-semibold">
                                <Star :size="14" class="text-warning fill-warning shrink-0" />
                                {{ formatNum(row.rating) }}<span class="text-xs font-normal text-base-content/50">/{{ ratings.max }}</span>
                            </span>
                        </div>
                    </div>

                    <div class="h-1.5 w-full rounded-full bg-base-300/70 overflow-hidden">
                        <div
                            class="h-full rounded-full transition-all duration-200"
                            :class="mode === 'value' ? 'bg-primary' : 'bg-warning'"
                            :style="{ width: barWidth(row) + '%' }"
                        ></div>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</template>

<script setup>
import { Star } from '@lucide/vue'
import { computed, inject, ref } from 'vue'
import { formatMoney } from '../../services/formatters.js'

const props = defineProps({
    ratings: { type: Object, required: true },
})

const injectedMoney = inject('formatMoney', null)
const money = (value) => (injectedMoney ? injectedMoney(value) : formatMoney(value))
const mode = ref('rated')

const formatNum = (value) => (Number.isInteger(value) ? String(value) : String(value).replace('.', ','))

const sortedRows = computed(() => {
    const rows = [...(props.ratings?.rows ?? [])]

    if (mode.value === 'value') {
        return rows.sort((a, b) => (b.value ?? -1) - (a.value ?? -1) || b.rating - a.rating)
    }

    return rows.sort((a, b) => b.rating - a.rating || b.rated_count - a.rated_count)
})

const maxValue = computed(() => Math.max(0.01, ...sortedRows.value.map((r) => r.value ?? 0)))

const barWidth = (row) => {
    if (mode.value === 'value') {
        return row.value ? Math.min(100, Math.round((row.value / maxValue.value) * 100)) : 0
    }

    return Math.min(100, Math.round((row.rating / Math.max(1, props.ratings.max || 5)) * 100))
}
</script>
