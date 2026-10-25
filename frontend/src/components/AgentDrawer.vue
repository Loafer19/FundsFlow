<template>
    <div class="drawer drawer-end">
        <input id="agent_drawer" type="checkbox" class="drawer-toggle" :checked="agent.isOpen"
            @change="(event) => (event.target.checked ? agent.open() : agent.close())" />
        <div class="drawer-side z-[60]">
            <label for="agent_drawer" aria-label="Close assistant" class="drawer-overlay" />

            <aside class="bg-base-100 h-dvh w-full sm:w-[28rem] flex flex-col shadow-xl" aria-label="Assistant">
                <header class="flex items-center gap-2 px-4 py-3 border-b border-base-300">
                    <Sparkles :size="20" class="text-primary" />
                    <h2 class="font-semibold">Assistant</h2>

                    <span v-if="agent.quota" class="badge badge-sm tooltip tooltip-bottom"
                        :class="agent.quotaExhausted ? 'badge-error' : agent.quota.hint ? 'badge-warning' : 'badge-ghost'"
                        :data-tip="quotaTip">
                        {{ agent.quota.remaining }}/{{ agent.quota.limit }} left
                    </span>

                    <div class="ml-auto flex gap-1">
                        <button type="button" class="btn btn-ghost btn-sm btn-square tooltip tooltip-bottom"
                            data-tip="New chat" aria-label="New chat" :disabled="!agent.items.length && !agent.isStreaming"
                            @click="agent.reset()">
                            <RotateCcw :size="18" />
                        </button>
                        <button type="button" class="btn btn-ghost btn-sm btn-square" aria-label="Close assistant"
                            @click="agent.close()">
                            <X :size="20" />
                        </button>
                    </div>
                </header>

                <div ref="scroller" class="flex-1 overflow-y-auto px-3 py-4 flex flex-col gap-3">
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
                                class="btn btn-sm btn-outline rounded-full font-normal" @click="send(prompt)">
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
                                        class="btn btn-xs btn-outline" :disabled="agent.isStreaming"
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

                <form class="border-t border-base-300 p-3 flex gap-2 items-end" @submit.prevent="send(input)">
                    <textarea ref="textarea" v-model="input" rows="1" maxlength="2000"
                        class="textarea flex-1 min-h-10 max-h-40 resize-none leading-snug"
                        placeholder="Ask anything about your finances…" aria-label="Message the assistant"
                        @keydown.enter.exact.prevent="send(input)" @input="autoGrow" />
                    <button v-if="agent.isStreaming" type="button" class="btn btn-square btn-ghost"
                        aria-label="Stop" @click="agent.stop()">
                        <Square :size="18" />
                    </button>
                    <button v-else type="submit" class="btn btn-square btn-primary" aria-label="Send"
                        :disabled="!input.trim()">
                        <SendHorizontal :size="18" />
                    </button>
                </form>
            </aside>
        </div>
    </div>
</template>

<script setup>
import { RotateCcw, SendHorizontal, ShieldQuestion, Sparkles, Square, X } from 'lucide-vue-next'
import { computed, nextTick, ref, watch } from 'vue'
import { useAgentStore } from '../services/agent.js'
import { renderMarkdown } from '../services/markdown.js'
import AgentChart from './AgentChart.vue'
import AgentShowCard from './AgentShowCard.vue'

const agent = useAgentStore()

const input = ref('')
const scroller = ref(null)
const textarea = ref(null)

const examples = [
    'How much did I spend this month?',
    'Chart of spending by tag this month',
    'Show my budgets',
    'Income vs expenses for the last 6 months',
    'Add -350 groceries today',
]

const ACTION_LABELS = {
    create_transaction: 'New transaction',
    update_transaction: 'Update transaction',
    delete_transaction: 'Delete transaction',
    create_budget: 'New budget',
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

const quotaTip = computed(() => {
    const quota = agent.quota
    if (!quota) return ''
    const reset = quota.reset_at ? new Date(quota.reset_at).toLocaleString() : ''
    return `AI requests today: ${quota.used}/${quota.limit} (shared with Telegram)${reset ? `. Resets ${reset}` : ''}`
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

watch(
    () => agent.isOpen,
    (open) => {
        if (open) nextTick(() => textarea.value?.focus())
    },
)
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
