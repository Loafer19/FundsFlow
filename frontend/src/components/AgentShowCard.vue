<template>
    <div class="card card-border bg-base-100 w-full">
        <div class="card-body p-3 gap-2">
            <p class="font-semibold text-sm">{{ item.title }}</p>

            <template v-if="item.view === 'transactions'">
                <p v-if="!transactions.length" class="text-sm text-base-content/60">No transactions yet.</p>
                <ul v-else class="flex flex-col divide-y divide-base-200">
                    <li v-for="tx in transactions" :key="tx.id" class="flex justify-between gap-2 py-1.5 text-sm">
                        <div class="min-w-0">
                            <p class="truncate">{{ tx.note || tagText(tx.tags) || '—' }}</p>
                            <p class="text-xs text-base-content/60 truncate">
                                {{ tx.at ? formatDate(tx.at) : '' }}<template v-if="tx.note && tx.tags?.length">
                                    · {{ tagText(tx.tags) }}</template>
                            </p>
                        </div>
                        <span class="tabular-nums shrink-0" :class="tx.amount < 0 ? 'text-error' : 'text-success'">
                            {{ formatMoney(tx.amount) }}
                        </span>
                    </li>
                </ul>
            </template>

            <template v-else-if="item.view === 'budgets'">
                <p v-if="!budgets.length" class="text-sm text-base-content/60">No budgets yet.</p>
                <ul v-else class="flex flex-col gap-2">
                    <li v-for="budget in budgets" :key="budget.id" class="text-sm">
                        <div class="flex justify-between gap-2">
                            <span class="truncate">{{ budget.title }}</span>
                            <span class="tabular-nums text-xs shrink-0">
                                {{ formatMoney(budget.spent) }} / {{ formatMoney(budget.amount_limit) }}
                            </span>
                        </div>
                        <progress class="progress w-full"
                            :class="budget.percent >= 100 ? 'progress-error' : budget.percent >= 80 ? 'progress-warning' : 'progress-success'"
                            :value="Math.min(100, budget.percent ?? 0)" max="100" />
                    </li>
                </ul>
            </template>

            <template v-else-if="item.view === 'tags'">
                <p v-if="!tags.length" class="text-sm text-base-content/60">No tags yet.</p>
                <div v-else class="flex flex-wrap gap-1.5">
                    <span v-for="tag in tags" :key="tag.id" class="badge badge-soft">{{ tag.emoji }} {{ tag.title }}</span>
                </div>
            </template>

            <template v-else-if="item.view === 'recurring'">
                <p v-if="!recurring.length" class="text-sm text-base-content/60">No recurring rules yet.</p>
                <ul v-else class="flex flex-col divide-y divide-base-200">
                    <li v-for="rule in recurring" :key="rule.id" class="flex justify-between gap-2 py-1.5 text-sm"
                        :class="{ 'opacity-50': !rule.active }">
                        <div class="min-w-0">
                            <p class="truncate">{{ rule.note || tagText(tagsByIds(rule.tag_ids)) || '—' }}</p>
                            <p class="text-xs text-base-content/60">
                                {{ rule.frequency }}<template v-if="rule.next_run_at"> · next {{
                                    formatDate(rule.next_run_at) }}</template><template v-if="!rule.active"> ·
                                    paused</template>
                            </p>
                        </div>
                        <span class="tabular-nums shrink-0" :class="rule.amount < 0 ? 'text-error' : 'text-success'">
                            {{ formatMoney(rule.amount) }}
                        </span>
                    </li>
                </ul>
            </template>

            <template v-else-if="item.view === 'month'">
                <div class="grid grid-cols-3 gap-2 text-center">
                    <div>
                        <p class="text-xs text-base-content/60">Income</p>
                        <p class="text-success tabular-nums text-sm">{{ formatMoney(data.income ?? 0) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-base-content/60">Expenses</p>
                        <p class="text-error tabular-nums text-sm">{{ formatMoney(data.expenses ?? 0) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-base-content/60">Net</p>
                        <p class="tabular-nums text-sm">{{ formatMoney(data.net ?? 0) }}</p>
                    </div>
                </div>
                <ul v-if="data.top_expense_tags?.length" class="flex flex-col gap-1 text-sm">
                    <li v-for="tag in data.top_expense_tags" :key="tag.id" class="flex justify-between gap-2">
                        <span class="truncate">{{ tag.emoji }} {{ tag.title }}</span>
                        <span class="tabular-nums text-error">{{ formatMoney(-tag.amount) }}</span>
                    </li>
                </ul>
                <p class="text-xs text-base-content/60">{{ data.transaction_count ?? 0 }} transactions · {{ data.month }}
                </p>
            </template>
        </div>
    </div>
</template>

<script setup>
import { computed, inject } from 'vue'
import { useTagsStore } from '../services/tags.js'

const props = defineProps({
    item: {
        type: Object,
        required: true,
    },
})

const formatDate = inject('formatDate')
const formatMoney = inject('formatMoney')
const tagsStore = useTagsStore()

const data = computed(() => props.item.data ?? {})
const transactions = computed(() => data.value.transactions ?? [])
const budgets = computed(() => data.value.budgets ?? [])
const tags = computed(() => data.value.tags ?? [])
const recurring = computed(() => data.value.recurring ?? [])

const tagText = (list) => (list ?? []).map((tag) => `${tag.emoji ?? ''} ${tag.title ?? ''}`.trim()).join(', ')
const tagsByIds = (ids) => tagsStore.tags.filter((tag) => (ids ?? []).includes(tag.id))
</script>
