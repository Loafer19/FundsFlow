<template>
    <div>
        <div class="grid grid-cols-3 sm:grid-cols-4 lg:grid-cols-6 gap-2">
            <button v-for="m in months" :key="m.month" type="button"
                class="month-card tooltip rounded-box border-2 px-1.5 pt-2 pb-1.5 flex flex-col items-center gap-0.5 cursor-pointer transition-colors"
                :class="[
                    m.isCurrent ? 'border-primary bg-primary/20' : 'border-base-300 bg-base-100 hover:border-primary/60',
                    m.isFuture && 'opacity-60 hover:opacity-100',
                ]"
                :aria-label="ariaLabel(m)" :aria-current="m.isCurrent ? 'date' : undefined" @click="emit('pick', m)">
                <div class="tooltip-content text-left text-xs leading-relaxed p-2">
                    <div class="font-semibold mb-0.5">{{ longName(m) }}</div>
                    <div v-if="m.income || m.expense">
                        Income <span class="tabular-nums">+{{ formatMoney(m.income) }}</span><br />
                        Spent <span class="tabular-nums">−{{ formatMoney(m.expense) }}</span><br />
                        Net <span class="tabular-nums">{{ signed(m.income - m.expense) }}</span>
                    </div>
                    <div v-else-if="!m.isFuture">No transactions</div>
                    <div v-if="m.upcomingCount">
                        {{ m.upcomingCount }} upcoming:
                        <span v-if="m.upcomingIncome" class="tabular-nums">+{{ formatMoney(m.upcomingIncome) }}</span>
                        <span v-if="m.upcomingExpense" class="tabular-nums"> −{{ formatMoney(m.upcomingExpense) }}</span>
                    </div>
                    <div class="opacity-70 mt-0.5">Click to open the month</div>
                </div>

                <div class="h-12 sm:h-14 w-full flex items-end justify-center gap-1" aria-hidden="true">
                    <div class="bar" :style="{ height: pct(m.income + m.upcomingIncome) }">
                        <div v-if="m.upcomingIncome" class="bar-forecast border-success/70" :style="{ height: share(m.upcomingIncome, m.income + m.upcomingIncome) }"></div>
                        <div class="bg-success/75 flex-1"></div>
                    </div>
                    <div class="bar" :style="{ height: pct(m.expense + m.upcomingExpense) }">
                        <div v-if="m.upcomingExpense" class="bar-forecast border-error/70" :style="{ height: share(m.upcomingExpense, m.expense + m.upcomingExpense) }"></div>
                        <div class="bg-error/75 flex-1"></div>
                    </div>
                </div>

                <span class="text-xs font-semibold">{{ shortName(m) }}</span>
                <span class="text-[11px] tabular-nums text-base-content/70 leading-tight">
                    <template v-if="m.expense">−{{ compact(m.expense) }}</template>
                    <template v-else-if="m.upcomingExpense">≈ −{{ compact(m.upcomingExpense) }}</template>
                    <template v-else>—</template>
                </span>
                <span v-if="m.income || m.expense" class="text-[10px] tabular-nums leading-tight"
                    :class="m.income - m.expense >= 0 ? 'text-success' : 'text-error'">
                    net {{ m.income - m.expense >= 0 ? '+' : '−' }}{{ compact(Math.abs(m.income - m.expense)) }}
                </span>
                <span v-else class="text-[10px] leading-tight text-base-content/50">
                    {{ m.upcomingCount ? `${m.upcomingCount} upcoming` : '&nbsp;' }}
                </span>
            </button>
        </div>

        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-3 text-xs text-base-content/60">
            <span class="flex items-center gap-1.5"><span class="legend bg-success/75"></span> Income</span>
            <span class="flex items-center gap-1.5"><span class="legend bg-error/75"></span> Spent</span>
            <span class="flex items-center gap-1.5"><span class="legend bar-forecast border-base-content/50"></span> Upcoming (recurring)</span>
            <span class="sm:ml-auto">Click a month to open it</span>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { formatMoney } from '../services/formatters.js'

const props = defineProps({
    // [{ month, start, income, expense, upcomingIncome, upcomingExpense, upcomingCount, isCurrent, isFuture }]
    // income / expense / upcoming* are positive numbers.
    months: { type: Array, required: true },
})

const emit = defineEmits(['pick'])

const max = computed(() =>
    Math.max(1, ...props.months.flatMap((m) => [m.income + m.upcomingIncome, m.expense + m.upcomingExpense])),
)

const pct = (value) => `${Math.max(value ? 4 : 0, (value / max.value) * 100)}%`
const share = (part, whole) => (whole ? `${(part / whole) * 100}%` : '0%')

const compactFormat = new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 })
const compact = (value) => compactFormat.format(value)
const signed = (value) => `${value >= 0 ? '+' : '−'}${formatMoney(Math.abs(value))}`

const shortName = (m) => m.start.toLocaleDateString('en-US', { month: 'short' })
const longName = (m) => m.start.toLocaleDateString('en-US', { month: 'long', year: 'numeric' })

const ariaLabel = (m) => {
    const parts = [longName(m)]
    if (m.income || m.expense) parts.push(`income ${formatMoney(m.income)}`, `spent ${formatMoney(m.expense)}`)
    if (m.upcomingCount) parts.push(`${m.upcomingCount} upcoming`)

    return parts.join(', ')
}
</script>

<style scoped>
.bar {
    width: 0.75rem;
    display: flex;
    flex-direction: column;
    border-radius: 0.25rem 0.25rem 0 0;
    overflow: hidden;
}

@media (min-width: 1024px) {
    .bar {
        width: 1.125rem;
    }
}

.bar-forecast {
    border-width: 1.5px;
    border-style: dashed;
    border-bottom-width: 0;
    border-radius: 0.25rem 0.25rem 0 0;
    flex-shrink: 0;
}

.legend {
    display: inline-block;
    width: 0.625rem;
    height: 0.625rem;
    border-radius: 0.125rem;
}
</style>
