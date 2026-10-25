<template>
    <div class="drawer drawer-end">
        <input id="agent_drawer" type="checkbox" class="drawer-toggle" :checked="agent.isOpen"
            @change="(event) => (event.target.checked ? agent.open() : agent.close())" />
        <div class="drawer-side z-[60]">
            <label for="agent_drawer" aria-label="Close assistant" class="drawer-overlay" />

            <aside ref="panel" class="bg-base-100 h-dvh w-full sm:w-[28rem] flex flex-col shadow-xl" role="dialog"
                aria-modal="true" aria-labelledby="agent_drawer_title" @keydown="trapFocus">
                <header class="flex items-center gap-2 px-4 py-3 border-b border-base-300">
                    <Sparkles :size="20" class="text-primary shrink-0" />
                    <h2 id="agent_drawer_title" class="font-semibold">Assistant</h2>

                    <span v-if="agent.quota" class="badge badge-sm tooltip tooltip-bottom whitespace-nowrap tabular-nums"
                        :class="quotaBadgeClass" :data-tip="quotaTip" :aria-label="quotaAriaLabel" tabindex="0">
                        {{ agent.quota.remaining }}/{{ agent.quota.limit }}<template v-if="resetTimeLabel"> · resets
                            {{ resetTimeLabel }}</template>
                    </span>

                    <div class="ml-auto flex gap-1">
                        <button type="button" class="btn btn-ghost btn-sm btn-square tooltip tooltip-bottom"
                            data-tip="New chat" aria-label="New chat" :disabled="!agent.items.length && !agent.isStreaming"
                            @click="agent.reset()">
                            <RotateCcw :size="18" />
                        </button>
                        <button ref="closeButton" type="button" class="btn btn-ghost btn-sm btn-square tooltip tooltip-left"
                            data-tip="Close (Esc)" aria-label="Close assistant" aria-keyshortcuts="Escape"
                            @click="agent.close()">
                            <X :size="20" />
                        </button>
                    </div>
                </header>

                <!-- role=log is a polite live region; aria-busy holds announcements until a reply is complete. -->
                <div ref="scroller" class="flex-1 overflow-y-auto px-3 py-4 flex flex-col gap-3" role="log"
                    aria-live="polite" aria-relevant="additions" aria-label="Assistant conversation"
                    :aria-busy="agent.isStreaming || agent.isLoadingHistory">
                    <div v-if="agent.isLoadingHistory && !agent.items.length" class="flex justify-center py-10">
                        <span class="loading loading-dots" />
                    </div>

                    <div v-else-if="!agent.items.length && !agent.isStreaming"
                        class="flex flex-col items-center text-center gap-4 py-8 px-2">
                        <Sparkles :size="36" class="text-primary" />
                        <div>
                            <p class="font-semibold">Ask about your money</p>
                            <p class="text-sm text-base-content/60">
                                Spending, budgets, charts, or add a transaction. Changes always wait for your confirm.
                            </p>
                        </div>
                        <div class="flex flex-wrap justify-center gap-2">
                            <button v-for="prompt in examples" :key="prompt" type="button"
                                class="btn btn-sm btn-outline rounded-full font-normal" :disabled="agent.quotaExhausted"
                                @click="send(prompt)">
                                {{ prompt }}
                            </button>
                        </div>
                    </div>

                    <template v-for="item in agent.items" :key="item.id">
                        <div v-if="item.role === 'user'" class="chat chat-end">
                            <div class="chat-bubble chat-bubble-primary whitespace-pre-wrap break-words">{{ item.text }}
                            </div>
                        </div>

                        <div v-else-if="item.kind === 'text'" class="chat chat-start">
                            <!-- renderMarkdown escapes all HTML first; only its own tags reach v-html. -->
                            <div class="chat-bubble agent-md break-words" v-html="renderMarkdown(item.text)" />
                        </div>

                        <div v-else-if="item.kind === 'notice'" role="status" class="alert alert-soft text-sm py-2"
                            :class="noticeClass(item.tone)">
                            <span class="whitespace-pre-wrap break-words">{{ item.text }}</span>
                        </div>

                        <div v-else-if="item.kind === 'ask'" class="chat chat-start">
                            <div class="chat-bubble flex flex-col gap-2">
                                <span class="whitespace-pre-wrap break-words">{{ item.question }}</span>
                                <div v-if="item.status === 'pending' && item.suggestions?.length"
                                    class="flex flex-wrap gap-1.5">
                                    <button v-for="option in item.suggestions" :key="option" type="button"
                                        class="btn btn-xs btn-outline" :disabled="agent.isStreaming || agent.quotaExhausted"
                                        @click="send(option)">
                                        {{ option }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div v-else-if="item.kind === 'confirm'" class="card card-border bg-base-100">
                            <div class="card-body p-3 gap-2">
                                <div class="flex items-center gap-2">
                                    <ShieldQuestion :size="16" class="text-warning" />
                                    <span class="text-xs uppercase tracking-wide text-base-content/60">
                                        {{ actionLabel(item.action) }}
                                    </span>
                                    <span v-if="item.status !== 'pending'" class="badge badge-xs ml-auto"
                                        :class="statusClass(item.status)">
                                        {{ item.status }}
                                    </span>
                                </div>
                                <p class="text-sm whitespace-pre-wrap break-words">{{ item.preview }}</p>
                                <div v-if="item.status === 'pending'" class="card-actions justify-end">
                                    <button type="button" class="btn btn-sm btn-ghost"
                                        :disabled="agent.busyPendingId !== null" @click="agent.resolve(item, 'cancel')">
                                        Cancel
                                    </button>
                                    <button type="button" class="btn btn-sm btn-primary"
                                        :disabled="agent.busyPendingId !== null" @click="agent.resolve(item, 'confirm')">
                                        <span v-if="agent.busyPendingId === item.pending_id"
                                            class="loading loading-spinner loading-xs" />
                                        Confirm
                                    </button>
                                </div>
                            </div>
                        </div>

                        <AgentChart v-else-if="item.kind === 'chart' && item.chart" :chart="item.chart" />

                        <AgentShowCard v-else-if="item.kind === 'show'" :item="item" />
                    </template>

                    <div v-if="agent.draft" class="chat chat-start">
                        <div class="chat-bubble agent-md break-words" v-html="renderMarkdown(agent.draft)" />
                    </div>

                    <div v-if="agent.isStreaming && !agent.draft"
                        class="flex items-center gap-2 text-sm text-base-content/60 px-1">
                        <span class="loading loading-dots loading-sm" />
                        <span>{{ agent.status || 'Thinking…' }}</span>
                    </div>
                </div>

                <p class="sr-only" role="status">{{ agent.isStreaming ? agent.status || 'Thinking…' : '' }}</p>

                <form class="border-t border-base-300 p-3 flex gap-2 items-end" @submit.prevent="send(input)">
                    <textarea ref="textarea" v-model="input" rows="1" maxlength="2000"
                        class="textarea flex-1 min-h-10 max-h-40 resize-none leading-snug" :placeholder="inputPlaceholder"
                        :disabled="agent.quotaExhausted" aria-label="Message the assistant"
                        @keydown.enter.exact.prevent="send(input)" @input="autoGrow" />
                    <button v-if="agent.isStreaming" type="button" class="btn btn-square btn-ghost"
                        aria-label="Stop" @click="agent.stop()">
                        <Square :size="18" />
                    </button>
                    <button v-else type="submit" class="btn btn-square btn-primary" aria-label="Send"
                        :disabled="!input.trim() || agent.quotaExhausted">
                        <SendHorizontal :size="18" />
                    </button>
                </form>
            </aside>
        </div>
    </div>
</template>

<script setup>
import { RotateCcw, SendHorizontal, ShieldQuestion, Sparkles, Square, X } from 'lucide-vue-next'
import { computed, defineAsyncComponent, h, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useAgentStore } from '../services/agent.js'
import { renderMarkdown } from '../services/markdown.js'
import settings from '../services/settings.js'
import AgentShowCard from './AgentShowCard.vue'

// Chart.js stays out of the main bundle: it loads with the first chart in the chat.
const AgentChart = defineAsyncComponent({
    loader: () => import('./AgentChart.vue'),
    loadingComponent: { render: () => h('div', { class: 'skeleton h-56 w-full' }) },
    delay: 0,
})

const agent = useAgentStore()

const input = ref('')
const scroller = ref(null)
const textarea = ref(null)
const panel = ref(null)
const closeButton = ref(null)

const DEFAULT_EXAMPLES = [
    'How much did I spend this month?',
    'Chart of spending by tag this month',
    'Show my budgets',
    'Income vs expenses for the last 6 months',
    'Add -350 groceries today',
]

// Starter prompts for the tab the user is on (screen context comes from App.vue).
const TAB_EXAMPLES = {
    analytics: [
        'Compare this period with the previous one',
        'What changed most in my spending?',
        'PDF report for this period',
    ],
    calendar: [
        'What recurring charges are coming up this week?',
        'Which day did I spend the most here?',
        'Add -350 groceries today',
    ],
    budgets: ['How are my budgets doing?', 'How much can I spend per day?', 'Pause my budget'],
    tags: ['Compare spending by tag with last month', 'Which tag grew the most?', 'Chart of spending by tag'],
    money_flow: [
        'Income vs expenses compared with the previous period',
        'Where did my money go here?',
        'PDF report for this period',
    ],
    balance_trend: [
        'How did my balance change in this period?',
        'Upcoming charges for the next 30 days',
        'Compare this quarter with last',
    ],
    table: [
        'Summarize the transactions in this period',
        'Biggest expenses in this period',
        'PDF report for this period',
    ],
}

const examples = computed(() => TAB_EXAMPLES[agent.screenContext?.tab] ?? DEFAULT_EXAMPLES)

const ACTION_LABELS = {
    create_transaction: 'New transaction',
    update_transaction: 'Update transaction',
    delete_transaction: 'Delete transaction',
    create_budget: 'New budget',
    update_budget: 'Update budget',
    pause_budget: 'Pause budget',
    resume_budget: 'Resume budget',
    delete_budget: 'Delete budget',
    create_recurring: 'New recurring rule',
    update_recurring: 'Update recurring rule',
    pause_recurring: 'Pause recurring rule',
    resume_recurring: 'Resume recurring rule',
    delete_recurring: 'Delete recurring rule',
    create_tags: 'New tags',
    rename_tags: 'Rename tags',
}

const actionLabel = (action) => ACTION_LABELS[action] ?? 'Confirm change'

const statusClass = (status) =>
    ({
        confirmed: 'badge-success',
        cancelled: 'badge-ghost',
        failed: 'badge-error',
        outdated: 'badge-ghost',
    })[status] ?? 'badge-ghost'

const noticeClass = (tone) =>
    ({
        error: 'alert-error',
        success: 'alert-success',
        muted: '',
    })[tone] ?? 'alert-info'

// --- Quota badge: remaining, reset time (user timezone) and a live countdown ---

const now = ref(Date.now())
let ticker = null

const stopTicker = () => {
    clearInterval(ticker)
    ticker = null
}

watch(
    () => agent.isOpen,
    (open) => {
        stopTicker()
        if (!open) return
        now.value = Date.now()
        ticker = setInterval(() => {
            now.value = Date.now()
        }, 30000)
    },
    { immediate: true },
)

const quotaTimezone = computed(() => agent.quota?.timezone || settings.timezone)

const resetsAt = computed(() => {
    const iso = agent.quota?.resets_at ?? agent.quota?.reset_at
    const date = iso ? new Date(iso) : null
    return date && !Number.isNaN(date.getTime()) ? date : null
})

// Wall-clock time in the user's timezone, in the browser's own 12/24h format ("00:00" or "12:00 AM").
const resetTimeLabel = computed(() => {
    if (!resetsAt.value) return ''
    try {
        return new Intl.DateTimeFormat(undefined, {
            timeZone: quotaTimezone.value,
            hour: '2-digit',
            minute: '2-digit',
        }).format(resetsAt.value)
    } catch {
        return new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' }).format(resetsAt.value)
    }
})

const countdown = computed(() => {
    if (!resetsAt.value) return ''
    const minutes = Math.max(1, Math.ceil((resetsAt.value.getTime() - now.value) / 60000))
    const hours = Math.floor(minutes / 60)
    const rest = minutes % 60
    return hours ? `${hours}h ${rest}m` : `${rest}m`
})

const quotaLow = computed(() => {
    const quota = agent.quota
    if (!quota || quota.limit <= 0 || agent.quotaExhausted) return false
    if (quota.low_threshold != null) return quota.remaining <= quota.low_threshold
    return Boolean(quota.hint)
})

const quotaBadgeClass = computed(() => {
    if (agent.quotaExhausted) return 'badge-error'
    if (quotaLow.value) return 'badge-warning'
    return 'badge-ghost'
})

const quotaTip = computed(() => {
    const quota = agent.quota
    if (!quota) return ''
    const reset = resetsAt.value ? `Resets at midnight (${quotaTimezone.value}), in ${countdown.value}` : ''
    return [reset, `${quota.used}/${quota.limit} AI assists used today, shared with Telegram`]
        .filter(Boolean)
        .join(' · ')
})

const quotaAriaLabel = computed(() => {
    const quota = agent.quota
    if (!quota) return ''
    const reset = resetTimeLabel.value ? `, resets at ${resetTimeLabel.value} (in ${countdown.value})` : ''
    return `${quota.remaining} of ${quota.limit} AI assists left today${reset}`
})

const inputPlaceholder = computed(() =>
    agent.quotaExhausted
        ? countdown.value
            ? `AI assists reset in ${countdown.value}`
            : 'No AI assists left today'
        : 'Ask anything about your finances…',
)

// Once midnight passes, pull the fresh counter instead of showing a stale "0 left".
let refreshedFor = null
watch(now, (value) => {
    const at = resetsAt.value?.getTime()
    if (!at || value < at || refreshedFor === at) return
    refreshedFor = at
    agent.refreshQuota()
})

const autoGrow = () => {
    const el = textarea.value
    if (!el) return
    el.style.height = 'auto'
    el.style.height = `${Math.min(el.scrollHeight, 160)}px`
}

const send = (text) => {
    const message = String(text ?? '').trim()
    if (!message || agent.isStreaming) return
    if (text === input.value) input.value = ''
    nextTick(autoGrow)
    agent.send(message)
}

const scrollToBottom = () => {
    nextTick(() => {
        const el = scroller.value
        if (el) el.scrollTop = el.scrollHeight
    })
}

watch(() => [agent.items.length, agent.draft, agent.status, agent.isOpen], scrollToBottom)

// --- Keyboard: "/" or Cmd/Ctrl+K opens and focuses the input, Esc closes and restores focus ---

let returnFocus = null

// The drawer stays visibility:hidden for ~0.1s after opening (DaisyUI transition delay) and hidden
// elements cannot take focus, so retry briefly until it lands.
let focusTimer = null
const focusInput = (attempts = 12) => {
    clearTimeout(focusTimer)
    const target = textarea.value && !textarea.value.disabled ? textarea.value : closeButton.value
    if (!target || !agent.isOpen) return
    target.focus()
    if (document.activeElement !== target && attempts > 0) {
        focusTimer = setTimeout(() => focusInput(attempts - 1), 50)
    }
}

const isTypingTarget = (el) =>
    el instanceof HTMLElement && (el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName))

// A native modal <dialog> sits in the top layer above the drawer: leave its keys alone.
const modalOpen = () => Boolean(document.querySelector('dialog[open]'))

const openAndFocus = () => {
    if (agent.isOpen) focusInput()
    else agent.open()
}

const onGlobalKeydown = (event) => {
    if (event.defaultPrevented || event.isComposing || event.repeat) return

    const isCmdK =
        (event.metaKey || event.ctrlKey) && !event.altKey && !event.shiftKey && event.key.toLowerCase() === 'k'
    // event.code covers layouts where the "/" key types something else (e.g. "." on Ukrainian).
    const isSlash =
        !event.metaKey &&
        !event.ctrlKey &&
        !event.altKey &&
        (event.key === '/' || (event.code === 'Slash' && !event.shiftKey))

    if (isCmdK || (isSlash && !isTypingTarget(event.target))) {
        if (modalOpen()) return
        event.preventDefault()
        openAndFocus()
        return
    }

    if (event.key === 'Escape' && agent.isOpen && !modalOpen()) {
        event.preventDefault()
        agent.close()
    }
}

const FOCUSABLE =
    'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'

// Keep Tab / Shift+Tab inside the open panel.
const trapFocus = (event) => {
    if (event.key !== 'Tab' || !panel.value) return
    const nodes = [...panel.value.querySelectorAll(FOCUSABLE)].filter((el) => el.getClientRects().length)
    if (!nodes.length) return

    const first = nodes[0]
    const last = nodes[nodes.length - 1]
    const active = document.activeElement
    const inside = panel.value.contains(active)

    if (event.shiftKey && (active === first || !inside)) {
        event.preventDefault()
        last.focus()
    } else if (!event.shiftKey && (active === last || !inside)) {
        event.preventDefault()
        first.focus()
    }
}

watch(
    () => agent.isOpen,
    (open) => {
        if (open) {
            const active = document.activeElement
            returnFocus = active && active !== document.body && !panel.value?.contains(active) ? active : null
            nextTick(() => focusInput())
            return
        }

        const active = document.activeElement
        if (active && active !== document.body && !panel.value?.contains(active)) return
        const target = returnFocus?.isConnected ? returnFocus : document.querySelector('[data-agent-toggle]')
        returnFocus = null
        nextTick(() => target?.focus())
    },
)

onMounted(() => document.addEventListener('keydown', onGlobalKeydown))

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onGlobalKeydown)
    clearTimeout(focusTimer)
    stopTicker()
})
</script>

<style scoped>
.agent-md :deep(p + p),
.agent-md :deep(p + ul),
.agent-md :deep(p + ol),
.agent-md :deep(ul + p),
.agent-md :deep(ol + p) {
    margin-top: 0.5rem;
}

.agent-md :deep(ul) {
    list-style: disc;
    padding-left: 1.25rem;
}

.agent-md :deep(ol) {
    list-style: decimal;
    padding-left: 1.25rem;
}

.agent-md :deep(.agent-code) {
    font-family: ui-monospace, monospace;
    font-size: 0.85em;
    padding: 0 0.25rem;
    border-radius: 0.25rem;
    background: color-mix(in oklab, currentColor 12%, transparent);
}

.agent-md :deep(.agent-pre) {
    font-family: ui-monospace, monospace;
    font-size: 0.8em;
    white-space: pre-wrap;
    margin-top: 0.5rem;
    padding: 0.5rem;
    border-radius: 0.5rem;
    background: color-mix(in oklab, currentColor 10%, transparent);
}
</style>
