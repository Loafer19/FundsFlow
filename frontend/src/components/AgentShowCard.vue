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

            <template v-else-if="item.view === 'budget_status'">
                <p v-if="data.error || !budgets.length" class="text-sm text-base-content/60">
                    {{ data.error || 'No active budgets.' }}
                </p>
                <ul v-else class="flex flex-col gap-3">
                    <li v-for="budget in budgets" :key="budget.id" class="text-sm flex flex-col gap-1">
                        <div class="flex justify-between gap-2">
                            <span class="truncate font-medium">{{ budget.title }}</span>
                            <span class="badge badge-sm shrink-0" :class="statusClass(budget.status)">
                                {{ statusLabel(budget.status) }}
                            </span>
                        </div>
                        <progress class="progress w-full"
                            :class="budget.status === 'over' ? 'progress-error' : budget.status === 'at_risk' ? 'progress-warning' : 'progress-success'"
                            :value="Math.min(100, budget.percent ?? 0)" max="100" />
                        <div class="grid grid-cols-2 gap-x-3 text-xs text-base-content/70">
                            <span>Spent <span class="tabular-nums">{{ budget.spent_formatted }}</span> / {{
                                budget.amount_limit_formatted }}</span>
                            <span class="text-right">Left <span class="tabular-nums"
                                    :class="budget.left < 0 ? 'text-error' : ''">{{ budget.left_formatted }}</span></span>
                            <span>{{ budget.days_left }} of {{ budget.days_total }} days left</span>
                            <span class="text-right">Per day <span class="tabular-nums">{{
                                budget.per_day_allowance_formatted }}</span></span>
                            <span class="col-span-2">Projected <span class="tabular-nums"
                                    :class="budget.projected_over_by > 0 ? 'text-error' : ''">{{
                                    budget.projected_spend_formatted }}</span> by {{ formatDate(budget.period_end) }}</span>
                        </div>
                    </li>
                </ul>
            </template>

            <template v-else-if="item.view === 'upcoming'">
                <p class="text-xs text-base-content/60">
                    {{ formatDate(data.from) }} – {{ formatDate(data.to) }}
                </p>
                <p v-if="!upcoming.length" class="text-sm text-base-content/60">Nothing scheduled in this window.</p>
                <ul v-else class="flex flex-col divide-y divide-base-200">
                    <li v-for="(row, index) in upcoming" :key="`${row.rule_id}-${row.date}-${index}`"
                        class="flex justify-between gap-2 py-1.5 text-sm">
                        <div class="min-w-0">
                            <p class="truncate">{{ row.note || (row.tags ?? []).join(', ') || '—' }}</p>
                            <p class="text-xs text-base-content/60 truncate">
                                {{ formatDate(row.date) }} · {{ row.frequency }}
                            </p>
                        </div>
                        <span class="tabular-nums shrink-0" :class="row.amount < 0 ? 'text-error' : 'text-success'">
                            {{ formatMoney(row.amount) }}
                        </span>
                    </li>
                </ul>
                <div v-if="upcoming.length" class="flex flex-wrap gap-x-3 text-xs text-base-content/70">
                    <span>Out <span class="text-error tabular-nums">{{ data.total_expenses_formatted }}</span></span>
                    <span v-if="data.total_income">In <span class="text-success tabular-nums">{{
                        data.total_income_formatted }}</span></span>
                    <span v-if="data.truncated">· list shortened</span>
                </div>
            </template>

            <template v-else-if="item.view === 'compare'">
                <p class="text-xs text-base-content/60">
                    {{ data.current?.label }} vs {{ data.previous?.label }}<template v-if="data.current?.partial"> ·
                        still in progress</template>
                </p>
                <div class="grid grid-cols-3 gap-2 text-center text-sm">
                    <div v-for="metric in compareMetrics" :key="metric.key">
                        <p class="text-xs text-base-content/60">{{ metric.label }}</p>
                        <p class="tabular-nums" :class="metric.class">{{ metric.now }}</p>
                        <p class="text-xs text-base-content/50 tabular-nums">{{ metric.was }}</p>
                        <p v-if="metric.percent != null" class="text-xs tabular-nums">
                            {{ metric.percent > 0 ? '+' : '' }}{{ metric.percent }}%
                        </p>
                    </div>
                </div>
                <ul v-if="compareTags.length" class="flex flex-col gap-1 text-xs">
                    <li v-for="row in compareTags" :key="row.tag_id ?? row.tag" class="flex justify-between gap-2">
                        <span class="truncate">{{ row.tag }}</span>
                        <span class="tabular-nums shrink-0">
                            {{ formatMoney(row.current) }}
                            <span :class="row.delta > 0 ? 'text-error' : row.delta < 0 ? 'text-success' : 'text-base-content/50'">
                                {{ row.delta_formatted }}<template v-if="row.delta_percent != null"> ({{
                                    row.delta_percent > 0 ? '+' : '' }}{{ row.delta_percent }}%)</template>
                            </span>
                        </span>
                    </li>
                </ul>
            </template>

            <template v-else-if="item.view === 'report'">
                <p class="text-xs text-base-content/60">
                    {{ data.period_label }} · {{ formatDate(data.from) }} – {{ formatDate(data.to) }}<template
                        v-if="data.filter"> · {{ data.filter }}</template>
                </p>
                <div class="grid grid-cols-3 gap-2 text-center">
                    <div>
                        <p class="text-xs text-base-content/60">Income</p>
                        <p class="text-success tabular-nums text-sm">{{ data.income_formatted }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-base-content/60">Expenses</p>
                        <p class="text-error tabular-nums text-sm">{{ data.expenses_formatted }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-base-content/60">Net</p>
                        <p class="tabular-nums text-sm">{{ data.net_formatted }}</p>
                    </div>
                </div>
                <p class="text-xs text-base-content/60">
                    {{ data.transaction_count ?? 0 }} transactions<template v-if="data.previous_label"> · {{
                        data.previous_label }}: {{ data.previous_formatted?.expenses }} expenses</template>
                </p>
                <div class="flex flex-wrap gap-2">
                    <template v-if="!inTelegram">
                        <button type="button" class="btn btn-sm btn-primary" :disabled="!!agent.reportBusy"
                            @click="downloadReport">
                            <span v-if="agent.reportBusy === 'download'" class="loading loading-spinner loading-xs" />
                            Download PDF
                        </button>
                        <button type="button" class="btn btn-sm" :disabled="!!agent.reportBusy" @click="printReport">
                            <span v-if="agent.reportBusy === 'print'" class="loading loading-spinner loading-xs" />
                            Open / Print
                        </button>
                    </template>
                    <button v-if="inTelegram || telegramLinked" type="button" class="btn btn-sm"
                        :class="inTelegram ? 'btn-primary' : ''" :disabled="!!agent.reportBusy" @click="sendReport">
                        <span v-if="agent.reportBusy === 'telegram'" class="loading loading-spinner loading-xs" />
                        Send to Telegram
                    </button>
                </div>
            </template>
        </div>
    </div>
</template>

<script setup>
import { computed, inject } from 'vue'
import { useAgentStore } from '../services/agent.js'
import { saveBlob } from '../services/report.js'
import { useTagsStore } from '../services/tags.js'
import { hasTelegramLinked } from '../services/telegramSend.js'
import { isTelegramWebApp } from '../services/telegramWebApp.js'

const props = defineProps({
    item: {
        type: Object,
        required: true,
    },
})

const formatDate = inject('formatDate')
const formatMoney = inject('formatMoney')
const tagsStore = useTagsStore()
const agent = useAgentStore()
const inTelegram = isTelegramWebApp()
const telegramLinked = computed(() => hasTelegramLinked())

const data = computed(() => props.item.data ?? {})
const transactions = computed(() => data.value.transactions ?? [])
const budgets = computed(() => data.value.budgets ?? [])
const tags = computed(() => data.value.tags ?? [])
const recurring = computed(() => data.value.recurring ?? [])

const tagText = (list) => (list ?? []).map((tag) => `${tag.emoji ?? ''} ${tag.title ?? ''}`.trim()).join(', ')
const upcoming = computed(() => data.value.items ?? [])
const compareTags = computed(() => (data.value.tags ?? []).slice(0, 8))

const statusLabel = (status) => ({ over: 'Over', at_risk: 'At risk', on_track: 'On track' })[status] ?? status
const statusClass = (status) =>
    ({ over: 'badge-error', at_risk: 'badge-warning', on_track: 'badge-success' })[status] ?? 'badge-ghost'

const compareMetrics = computed(() => {
    const cur = data.value.current ?? {}
    const prev = data.value.previous ?? {}
    return [
        {
            key: 'income',
            label: 'Income',
            now: cur.income_formatted,
            was: prev.income_formatted,
            percent: data.value.income_change?.percent,
            class: 'text-success',
        },
        {
            key: 'expenses',
            label: 'Expenses',
            now: cur.expenses_formatted,
            was: prev.expenses_formatted,
            percent: data.value.expense_change?.percent,
            class: 'text-error',
        },
        { key: 'net', label: 'Net', now: cur.net_formatted, was: prev.net_formatted, percent: null, class: '' },
    ]
})

const reportFilename = () => `fundsflow-report-${data.value.from}_${data.value.to}.pdf`

const downloadReport = async () => {
    const blob = await agent.fetchReport(data.value.request, 'download')
    if (blob) saveBlob(blob, reportFilename())
}

const printReport = async () => {
    // Open the tab synchronously (popup blockers), then point it at the PDF; the viewer has Print.
    const tab = window.open('', '_blank')
    const blob = await agent.fetchReport(data.value.request, 'print')
    if (!blob) {
        tab?.close()
        return
    }
    const url = URL.createObjectURL(blob)
    if (tab) tab.location.href = url
    else window.location.assign(url)
    setTimeout(() => URL.revokeObjectURL(url), 300_000)
}

const sendReport = () => agent.fetchReport(data.value.request, 'telegram')

const tagsByIds = (ids) => tagsStore.tags.filter((tag) => (ids ?? []).includes(tag.id))
</script>
