<template>
    <div class="drawer drawer-end">
        <input id="agent_drawer" type="checkbox" class="drawer-toggle" :checked="agent.isOpen"
            @change="(event) => (event.target.checked ? agent.open() : agent.close())" />
        <div class="drawer-side agent-drawer-side z-[60]">
            <label for="agent_drawer" aria-label="Close assistant" class="drawer-overlay" />

            <aside ref="panel" class="agent-panel relative bg-base-100 h-dvh w-full sm:w-[28rem] flex flex-col shadow-xl"
                role="dialog" aria-modal="true" aria-labelledby="agent_drawer_title" @keydown="trapFocus"
                @dragenter="onDragEnter" @dragover="onDragOver" @dragleave="onDragLeave" @drop="onDrop">
                <header class="flex items-center gap-2 px-4 py-3 border-b border-base-300">
                    <Sparkles :size="20" class="text-primary shrink-0" />
                    <h2 id="agent_drawer_title" class="font-semibold">Assistant</h2>

                    <span v-if="agent.quota" class="badge badge-sm tooltip tooltip-bottom whitespace-nowrap tabular-nums"
                        :class="quotaBadgeClass" :data-tip="quotaTip" :aria-label="quotaAriaLabel" tabindex="0">
                        {{ agent.quota.remaining }}/{{ agent.quota.limit }}<template v-if="resetTimeLabel"> · resets
                            {{ resetTimeLabel }}</template>
                    </span>

                    <div class="ml-auto flex gap-1">
                        <button type="button"
                            class="btn btn-ghost btn-sm btn-square tooltip tooltip-bottom agent-press agent-spin-hover-ccw"
                            data-tip="New chat" aria-label="New chat" :disabled="!agent.items.length && !agent.isStreaming"
                            @click="agent.reset()">
                            <RotateCcw :size="18" />
                        </button>
                        <button ref="closeButton" type="button" class="btn btn-ghost btn-sm btn-square tooltip tooltip-left agent-press"
                            data-tip="Close (Esc)" aria-label="Close assistant" aria-keyshortcuts="Escape"
                            @click="agent.close()">
                            <X :size="20" />
                        </button>
                    </div>
                </header>

                <div class="relative flex-1 min-h-0">
                <!-- role=log is a polite live region; aria-busy holds announcements until a reply is complete. -->
                <div ref="scroller" class="h-full overflow-y-auto px-3 py-4" role="log"
                    aria-live="polite" aria-relevant="additions" aria-label="Assistant conversation"
                    :aria-busy="agent.isStreaming || agent.isLoadingHistory" @scroll.passive="onScroll">
                    <div ref="content" class="flex flex-col gap-3">
                    <div v-if="agent.isLoadingHistory && !agent.items.length"
                        class="agent-skeleton-wrap flex flex-col gap-3 py-2" aria-label="Loading conversation">
                        <div class="agent-skeleton self-end h-9 w-2/5 rounded-box" />
                        <div class="agent-skeleton h-16 w-3/4 rounded-box" />
                        <div class="agent-skeleton self-end h-9 w-1/3 rounded-box" />
                    </div>

                    <div v-else-if="!agent.items.length && !agent.isStreaming"
                        class="agent-fade flex flex-col items-center text-center gap-4 py-8 px-2">
                        <Sparkles :size="36" class="text-primary" />
                        <div>
                            <p class="font-semibold">Ask about your money</p>
                            <p class="text-sm text-base-content/60">
                                Spending, budgets, charts, or add a transaction. Changes always wait for your confirm.
                            </p>
                        </div>
                        <!-- Fixed-height slots: a rotating chip never shifts the layout. aria-live off: no announcements. -->
                        <div class="flex flex-col items-center gap-2 w-full" role="group" aria-label="Suggested questions"
                            aria-live="off" @mouseenter="chipsHovered = true" @mouseleave="chipsHovered = false"
                            @focusin="chipsFocused = true" @focusout="chipsFocused = false">
                            <div v-for="(chip, slot) in chips" :key="slot" class="h-8 w-full flex justify-center">
                                <Transition name="agent-swap" mode="out-in">
                                    <button :key="chip.key" type="button"
                                        class="btn btn-sm btn-outline rounded-full font-normal max-w-full agent-press"
                                        :disabled="agent.quotaExhausted" @click="useChip(chip)">
                                        <span class="truncate">{{ chip.text }}</span>
                                    </button>
                                </Transition>
                            </div>
                            <button type="button" class="btn btn-ghost btn-xs gap-1 text-base-content/60 agent-press"
                                @click="shuffleChips">
                                <Shuffle :size="14" aria-hidden="true" />
                                More ideas
                            </button>
                        </div>
                    </div>

                    <template v-for="(item, index) in agent.items" :key="itemKey(item, index)">
                        <div v-if="item.role === 'user'" class="chat chat-end" :class="enterClass(item)">
                            <div class="chat-bubble chat-bubble-primary break-words flex flex-col gap-1.5">
                                <div v-if="item.localImages?.length" class="grid gap-1"
                                    :class="item.localImages.length > 1 ? 'grid-cols-3' : 'grid-cols-1'">
                                    <img v-for="(src, index) in item.localImages" :key="src" :src="src"
                                        :alt="`Receipt photo ${index + 1}`" class="rounded-md object-cover"
                                        :class="item.localImages.length > 1 ? 'size-20' : 'max-h-48 max-w-full'" />
                                </div>
                                <span v-else-if="item.photos" class="text-sm opacity-90">📷 {{ photoLabel(item.photos) }}</span>
                                <template v-if="item.documents?.length">
                                    <span v-for="(doc, i) in item.documents" :key="i" class="text-sm opacity-90 break-all">📄 {{ doc.name }} · {{ formatSize(doc.size) }}</span>
                                </template>
                                <span v-else-if="item.document" class="text-sm opacity-90 break-all">📄 {{ item.document.name }} · {{ formatSize(item.document.size) }}</span>
                                <span v-if="item.text" class="whitespace-pre-wrap">{{ item.text }}</span>
                            </div>
                        </div>

                        <div v-else-if="item.kind === 'text'" class="chat chat-start" :class="enterClass(item)">
                            <!-- renderMarkdown escapes all HTML first; only its own tags reach v-html. -->
                            <div class="chat-bubble agent-md break-words" v-html="renderMarkdown(item.text)" />
                            <div class="chat-footer flex gap-0.5 pt-0.5">
                                <button type="button" class="btn btn-ghost btn-xs btn-square tooltip tooltip-right agent-press"
                                    :data-tip="copiedId === item.id ? 'Copied' : 'Copy'"
                                    :aria-label="copiedId === item.id ? 'Copied' : 'Copy answer'" @click="copyAnswer(item)">
                                    <Check v-if="copiedId === item.id" :size="14" class="text-success agent-pop" aria-hidden="true" />
                                    <Copy v-else :size="14" class="agent-pop" aria-hidden="true" />
                                </button>
                                <button v-if="item.id === agent.regenerateTargetId" type="button"
                                    class="btn btn-ghost btn-xs btn-square tooltip tooltip-right agent-press agent-spin-hover"
                                    :data-tip="REGENERATE_TIP"
                                    :aria-label="REGENERATE_TIP" @click="regenerate">
                                    <RefreshCw :size="14" aria-hidden="true" />
                                </button>
                            </div>
                        </div>

                        <div v-else-if="item.kind === 'notice'" role="status" class="alert alert-soft text-sm py-2"
                            :class="[noticeClass(item.tone), noticeMotion(item)]">
                            <span class="whitespace-pre-wrap break-words">{{ item.text }}</span>
                        </div>

                        <div v-else-if="item.kind === 'ask'" class="chat chat-start" :class="enterClass(item)">
                            <div class="chat-bubble flex flex-col gap-2">
                                <span class="whitespace-pre-wrap break-words">{{ item.question }}</span>
                                <div v-if="item.status === 'pending' && item.suggestions?.length"
                                    class="flex flex-wrap gap-1.5">
                                    <button v-for="(option, i) in item.suggestions" :key="option" type="button"
                                        class="btn btn-xs btn-outline agent-press agent-pop"
                                        :style="{ animationDelay: `${120 + i * 50}ms` }" :disabled="agent.isStreaming || agent.quotaExhausted"
                                        @click="send(option)">
                                        {{ option }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div v-else-if="item.kind === 'confirm'" class="card card-border bg-base-100"
                            :class="confirmClass(item)">
                            <div class="card-body p-3 gap-2">
                                <div class="flex items-center gap-2">
                                    <ShieldQuestion :size="16" class="text-warning" />
                                    <span class="text-xs uppercase tracking-wide text-base-content/60">
                                        {{ actionLabel(item.action) }}
                                    </span>
                                    <span v-if="item.status !== 'pending'" class="badge badge-xs ml-auto agent-pop"
                                        :class="statusClass(item.status)">
                                        <Check v-if="item.status === 'confirmed'" :size="10" aria-hidden="true" />
                                        {{ item.status }}
                                    </span>
                                </div>
                                <p class="text-sm whitespace-pre-wrap break-words">{{ item.preview }}</p>
                                <div v-if="item.status === 'pending'" class="card-actions justify-end">
                                    <button type="button" class="btn btn-sm btn-ghost agent-press"
                                        :disabled="agent.busyPendingId !== null" @click="resolvePending(item, 'cancel')">
                                        Cancel
                                    </button>
                                    <button type="button" class="btn btn-sm btn-primary agent-press"
                                        :disabled="agent.busyPendingId !== null" @click="resolvePending(item, 'confirm')">
                                        <span v-if="agent.busyPendingId === item.pending_id"
                                            class="loading loading-spinner loading-xs" />
                                        Confirm
                                    </button>
                                </div>
                            </div>
                        </div>

                        <AgentChart v-else-if="item.kind === 'chart' && item.chart" class="agent-card-in" :chart="item.chart" />

                        <AgentShowCard v-else-if="item.kind === 'show'" class="agent-card-in" :item="item" />
                    </template>

                    <!-- Regenerate for a turn that ended in a chart / card / error rather than text. -->
                    <div v-if="agent.regenerateTargetId && agent.items[agent.items.length - 1]?.kind !== 'text'"
                        class="flex agent-fade">
                        <button type="button"
                            class="btn btn-ghost btn-xs gap-1 tooltip tooltip-bottom agent-press agent-spin-hover"
                            :data-tip="REGENERATE_TIP" :aria-label="REGENERATE_TIP" @click="regenerate">
                            <RefreshCw :size="14" aria-hidden="true" />
                            Regenerate
                        </button>
                    </div>

                    <div v-if="agent.draft" class="chat chat-start agent-fade">
                        <div class="chat-bubble agent-md agent-streaming break-words" v-html="renderMarkdown(agent.draft)" />
                    </div>

                    <div v-if="agent.isStreaming && !agent.draft"
                        class="agent-fade flex items-center gap-2 text-sm text-base-content/60 px-1">
                        <span class="agent-dots" aria-hidden="true"><span /><span /><span /></span>
                        <span>{{ agent.status || 'Thinking…' }}</span>
                    </div>
                    </div>
                </div>

                <!-- Shown while scrolled up; new content no longer yanks the view down. -->
                <button v-if="showJump" type="button"
                    class="btn btn-sm rounded-full shadow-lg absolute bottom-3 left-1/2 -translate-x-1/2 gap-1 agent-pop agent-press"
                    :class="hasNew ? 'btn-primary' : 'btn-neutral'"
                    :aria-label="hasNew ? 'New messages: jump to latest' : 'Jump to latest message'"
                    @click="jumpToLatest">
                    <ArrowDown :size="16" aria-hidden="true" />
                    {{ hasNew ? 'New messages' : 'Jump to latest' }}
                </button>
                </div>

                <p class="sr-only" role="status">{{ agent.isStreaming ? agent.status || 'Thinking…' : '' }}</p>
                <p class="sr-only" role="status">{{ copiedId ? 'Answer copied to the clipboard' : '' }}</p>

                <div v-if="editing" class="agent-fade border-t border-base-300 px-3 pt-2 flex items-center gap-2 text-xs"
                    role="status">
                    <Pencil :size="14" class="shrink-0 text-base-content/60" aria-hidden="true" />
                    <span class="flex-1 text-base-content/70">
                        {{ editNote }}
                    </span>
                    <button type="button" class="btn btn-ghost btn-xs" aria-keyshortcuts="Escape" @click="cancelEdit">
                        Cancel
                    </button>
                </div>

                <Transition name="agent-chip">
                <div v-if="attachedDocuments.length" class="border-t border-base-300 px-3 pt-3 flex flex-col gap-1.5" role="list"
                    :aria-label="attachedDocuments.length > 1 ? 'Files to send' : 'File to send'">
                    <div v-for="(doc, i) in attachedDocuments" :key="`${doc.name}-${doc.size}-${i}`"
                        class="flex items-center gap-2 rounded-lg border border-base-300 bg-base-200 px-2 py-1.5 max-w-full"
                        role="listitem">
                        <FileText :size="18" class="shrink-0 text-primary" aria-hidden="true" />
                        <span class="text-sm truncate min-w-0 flex-1" :title="doc.name">{{ doc.name }}</span>
                        <span class="text-xs text-base-content/60 shrink-0">{{ formatSize(doc.size) }}</span>
                        <button type="button" class="btn btn-xs btn-circle btn-ghost shrink-0"
                            :aria-label="`Remove ${doc.name}`" @click="removeDocument(i)">
                            <X :size="12" aria-hidden="true" />
                        </button>
                    </div>
                </div>
                </Transition>

                <TransitionGroup v-if="attachments.length || preparing" tag="div" name="agent-chip"
                    class="agent-chip-group border-t border-base-300 px-3 pt-3 flex flex-wrap gap-2"
                    role="list" aria-label="Photos to send">
                    <div v-for="(image, index) in attachments" :key="image.url" class="relative" role="listitem">
                        <img :src="image.url" :alt="`Photo ${index + 1} to send`"
                            class="size-16 rounded-lg object-cover border border-base-300" />
                        <button type="button" class="btn btn-xs btn-circle btn-neutral absolute -top-2 -right-2"
                            :aria-label="`Remove photo ${index + 1}`" @click="removeAttachment(index)">
                            <X :size="12" aria-hidden="true" />
                        </button>
                    </div>
                    <div v-for="n in preparing" :key="`preparing-${n}`" class="size-16 rounded-lg skeleton" role="listitem"
                        aria-label="Preparing photo" />
                </TransitionGroup>

                <form class="p-3 flex gap-2 items-end"
                    :class="{ 'border-t border-base-300': !attachments.length && !preparing && !editing && !attachedDocument }"
                    @submit.prevent="send(input)">
                    <input ref="fileInput" type="file" class="hidden" multiple :accept="FILE_ACCEPT" tabindex="-1"
                        aria-hidden="true" @change="onFilePicked" />
                    <button type="button" class="btn btn-square btn-ghost tooltip tooltip-top agent-press" :data-tip="attachTip"
                        :aria-label="attachTip" :disabled="attachDisabled" @click="fileInput?.click()">
                        <Paperclip :size="18" aria-hidden="true" />
                    </button>
                    <textarea ref="textarea" v-model="input" rows="1" maxlength="2000"
                        class="textarea agent-input flex-1 min-h-10 max-h-40 resize-none leading-snug" :placeholder="inputPlaceholder"
                        :disabled="agent.quotaExhausted" aria-label="Message the assistant"
                        aria-keyshortcuts="ArrowUp" @keydown.enter.exact.prevent="send(input)"
                        @keydown.up.exact="editLast" @keydown.esc="onComposerEscape" @input="autoGrow" @paste="onPaste" />
                    <button v-if="agent.isStreaming" type="button" class="btn btn-square btn-ghost agent-press agent-stop"
                        aria-label="Stop" @click="agent.stop()">
                        <Square :size="18" />
                    </button>
                    <button v-else type="submit" class="btn btn-square btn-primary agent-press agent-send" aria-label="Send"
                        :disabled="(!input.trim() && !attachments.length && !attachedDocument) || agent.quotaExhausted || preparing > 0">
                        <SendHorizontal :size="18" />
                    </button>
                </form>

                <div v-if="dragging" class="agent-fade absolute inset-0 z-10 m-2 rounded-box border-2 border-dashed border-primary
                    bg-base-100/90 flex flex-col items-center justify-center gap-2 pointer-events-none" aria-hidden="true">
                    <ImagePlus :size="32" class="text-primary" />
                    <p class="font-medium">Drop receipt photos or a file</p>
                    <p class="text-xs text-base-content/60">Photos: JPEG, PNG, WebP or HEIC · up to {{ MAX_IMAGES }}</p>
                    <p class="text-xs text-base-content/60">Files: PDF, Word, CSV, Excel, JSON, OFX, QIF, HTML · up to {{ MAX_DOCUMENTS }} at once</p>
                </div>
            </aside>
        </div>
    </div>
</template>

<script setup>
import {
    ArrowDown,
    Check,
    Copy,
    FileText,
    ImagePlus,
    Paperclip,
    Pencil,
    RefreshCw,
    RotateCcw,
    SendHorizontal,
    ShieldQuestion,
    Shuffle,
    Sparkles,
    Square,
    X,
} from 'lucide-vue-next'
import { computed, defineAsyncComponent, h, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { lastTurnIndex, rewindBlocker, useAgentStore } from '../services/agent.js'
import {
    DOCUMENT_ACCEPT,
    documentLimit,
    formatSize,
    isSupportedDocument,
    legacyDocumentHint,
    MAX_DOCUMENTS,
    MAX_DOCUMENTS_TOTAL_BYTES,
} from '../services/agentDocuments.js'
import { suggestionPool, topExpenseTag, useSuggestionChips } from '../services/agentSuggestions.js'
import { useBudgetsStore } from '../services/budgets.js'
import { renderMarkdown } from '../services/markdown.js'
import {
    ACCEPT,
    isSupportedImage,
    MAX_IMAGES,
    MAX_INPUT_BYTES,
    prepareReceiptImage,
} from '../services/receiptImages.js'
import { useRecurringTransactionsStore } from '../services/recurringTransactions.js'
import settings from '../services/settings.js'
import toasts from '../services/toasts.js'
import { useTransactionsStore } from '../services/transactions.js'
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
const content = ref(null)
const fileInput = ref(null)
const textarea = ref(null)
const panel = ref(null)
const closeButton = ref(null)

// Starter chips (services/agentSuggestions.js): 3 from different categories, one swaps every ~7s.
// Lightly personalized from the already-loaded stores (budgets / recurring / top tag), no requests.
const budgetsStore = useBudgetsStore()
const recurringStore = useRecurringTransactionsStore()
const transactionsStore = useTransactionsStore()
const topTag = computed(() => topExpenseTag(transactionsStore.transactions))
const chipsHovered = ref(false)
const chipsFocused = ref(false)
const { chips, shuffle: shuffleChips } = useSuggestionChips({
    pool: computed(() =>
        suggestionPool({
            tab: agent.screenContext?.tab,
            hasBudgets: budgetsStore.budgets.some(
                (budget) => budget.type !== 'goal' && budget.periods?.some((period) => period.active),
            ),
            hasGoals: budgetsStore.budgets.some((budget) => budget.type === 'goal'),
            hasRecurring: recurringStore.rules.some((rule) => rule.active),
            hasTransactions: transactionsStore.transactions.length > 0,
            topTag: topTag.value,
        }),
    ),
    active: computed(() => agent.isOpen && !agent.items.length && !agent.isStreaming && !agent.isLoadingHistory),
    paused: computed(() => chipsHovered.value || chipsFocused.value || Boolean(input.value.trim())),
})

const ACTION_LABELS = {
    create_transaction: 'New transaction',
    create_transactions: 'New transactions',
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
        warning: 'alert-warning',
        muted: '',
    })[tone] ?? 'alert-info'

// --- Motion helpers (styles in styles/agent-motion.css) ---

// User bubbles are keyed by position: the server echo swaps the local id, which must not
// remount (and re-animate) the bubble that was just sent.
const itemKey = (item, index) => (item.role === 'user' ? `user-${index}` : item.id)

// The final answer replaces the streamed draft in place: no enter animation, or it would blink.
let streamedText = ''
watch(
    () => agent.draft,
    (draft, previous) => {
        if (!draft && previous) streamedText = previous.trim()
    },
    { flush: 'sync' },
)

// Decided once per item, so a later re-render never restarts the animation.
const enterMemo = new Map()
const enterClass = (item) => {
    if (enterMemo.has(item.id)) return enterMemo.get(item.id)
    const head = streamedText.slice(0, 40)
    const settled = item.kind === 'text' && item.role !== 'user' && head && String(item.text ?? '').startsWith(head)
    if (item.role !== 'user') streamedText = ''
    const value = settled ? '' : 'agent-enter'
    enterMemo.set(item.id, value)
    return value
}

const noticeMotion = (item) => (['error', 'warning'].includes(item.tone) ? 'agent-shake' : 'agent-enter')

// Confirms resolved in this session: only these flash success / shake on failure (not history).
const resolvedHere = ref(new Set())
const resolvePending = (item, action) => {
    resolvedHere.value.add(item.pending_id)
    return agent.resolve(item, action)
}

const confirmClass = (item) => {
    const here = resolvedHere.value.has(item.pending_id)
    return [
        'agent-card-in',
        {
            'agent-card-dim': item.status === 'cancelled' || item.status === 'outdated',
            'agent-card-confirmed': here && item.status === 'confirmed',
            'agent-shake': here && item.status === 'failed',
        },
    ]
}

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

const inputPlaceholder = computed(() => {
    if (agent.quotaExhausted) {
        return countdown.value ? `AI assists reset in ${countdown.value}` : 'No AI assists left today'
    }
    if (attachedDocument.value) return 'Note (optional), e.g. card statement'
    return attachments.value.length ? 'Note or amount (optional), e.g. -350' : 'Ask anything about your finances…'
})

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

// --- Receipt photos: paperclip, drag & drop, paste; downscaled to JPEG before upload ---

const attachments = ref([])
const preparing = ref(0)
// Documents (PDF, Word, CSV, Excel, JSON, OFX, QIF, HTML; up to MAX_DOCUMENTS) instead of photos;
// several files become one import on the server.
const attachedDocuments = ref([])
const attachedDocument = computed(() => attachedDocuments.value[0] ?? null)
const FILE_ACCEPT = `${ACCEPT},${DOCUMENT_ACCEPT}`

const attachDisabled = computed(
    () =>
        agent.quotaExhausted ||
        attachedDocuments.value.length >= MAX_DOCUMENTS ||
        attachments.value.length + preparing.value >= MAX_IMAGES,
)

const attachTip = computed(() => {
    if (agent.quotaExhausted) return 'No AI assists left today'
    if (attachedDocuments.value.length >= MAX_DOCUMENTS) return `Up to ${MAX_DOCUMENTS} files per message`
    if (attachments.value.length + preparing.value >= MAX_IMAGES) return `Up to ${MAX_IMAGES} photos per message`
    return 'Attach receipt photos or files (PDF, Word, CSV, Excel, OFX, QIF…)'
})

// Documents go without photos: returns true when they were taken (or refused with a toast).
const addDocument = (files) => {
    const docs = files.filter(
        (file) => !isSupportedImage(file) && (isSupportedDocument(file) || legacyDocumentHint(file)),
    )
    if (!docs.length) return false
    if (attachments.value.length || preparing.value) {
        toasts.error('Send files on their own, without photos.')
        return true
    }
    if (files.length > docs.length) toasts.info('Files and photos go in separate messages: only the files were added.')
    for (const file of docs) {
        const name = file.name || 'The file'
        const hint = legacyDocumentHint(file)
        if (hint) {
            toasts.error(hint)
            continue
        }
        if (attachedDocuments.value.length >= MAX_DOCUMENTS) {
            toasts.info(`Up to ${MAX_DOCUMENTS} files per message.`)
            break
        }
        if (!file.size) {
            toasts.error(`${name} is empty.`)
            continue
        }
        if (file.size > documentLimit(file)) {
            toasts.error(`${name} is larger than ${formatSize(documentLimit(file))}.`)
            continue
        }
        const total = attachedDocuments.value.reduce((sum, doc) => sum + doc.size, 0) + file.size
        if (total > MAX_DOCUMENTS_TOTAL_BYTES) {
            toasts.error(`Files in one message can be up to ${formatSize(MAX_DOCUMENTS_TOTAL_BYTES)} together.`)
            continue
        }
        attachedDocuments.value = [...attachedDocuments.value, file]
    }
    nextTick(() => textarea.value?.focus())
    return true
}

const removeDocument = (index) => {
    attachedDocuments.value = attachedDocuments.value.filter((_, i) => i !== index)
    nextTick(() => textarea.value?.focus())
}

const photoLabel = (count) => `${count} photo${count === 1 ? '' : 's'}`

const addFiles = async (list) => {
    const files = [...(list ?? [])]
    if (!files.length) return
    if (agent.quotaExhausted) {
        toasts.info('No AI assists left today: photos can be sent again after the reset.')
        return
    }

    if (addDocument(files)) return
    if (attachedDocument.value) {
        toasts.error('Send photos separately from the file.')
        return
    }

    const images = files.filter(isSupportedImage)
    if (images.length < files.length) {
        toasts.error('Attach JPEG, PNG, WebP or HEIC photos, or PDF, Word, CSV, Excel, JSON, OFX, QIF or HTML files.')
    }

    const room = MAX_IMAGES - attachments.value.length - preparing.value
    if (images.length > room) toasts.error(`Up to ${MAX_IMAGES} photos per message.`)

    for (const file of images.slice(0, Math.max(0, room))) {
        const name = file.name || 'Photo'
        if (file.size > MAX_INPUT_BYTES) {
            toasts.error(`${name} is larger than 10 MB.`)
            continue
        }

        preparing.value++
        try {
            attachments.value.push(await prepareReceiptImage(file))
        } catch {
            const heic = /hei[cf]$/i.test(file.type) || /\.hei[cf]$/i.test(name)
            toasts.error(
                heic
                    ? `This browser cannot open HEIC photos (${name}). Send it as JPEG or PNG.`
                    : `Couldn't read ${name}.`,
            )
        } finally {
            preparing.value--
        }
    }
}

const removeAttachment = (index) => {
    const [removed] = attachments.value.splice(index, 1)
    if (removed?.url) URL.revokeObjectURL(removed.url)
    nextTick(() => textarea.value?.focus())
}

const onFilePicked = (event) => {
    addFiles(event.target.files)
    // Same file can be picked again after removing it.
    event.target.value = ''
}

const onPaste = (event) => {
    const files = [...(event.clipboardData?.files ?? [])].filter((file) => file.type.startsWith('image/'))
    if (!files.length) return
    event.preventDefault()
    addFiles(files)
}

const dragging = ref(false)
let dragDepth = 0
const hasFiles = (event) => [...(event.dataTransfer?.types ?? [])].includes('Files')

const onDragEnter = (event) => {
    if (!hasFiles(event)) return
    event.preventDefault()
    dragDepth++
    dragging.value = !attachDisabled.value
}

const onDragOver = (event) => {
    if (!hasFiles(event)) return
    event.preventDefault()
    event.dataTransfer.dropEffect = attachDisabled.value ? 'none' : 'copy'
}

// Leave and drop always unwind the overlay (some browsers report no types on dragleave).
const onDragLeave = () => {
    if (!dragDepth) return
    dragDepth--
    if (!dragDepth) dragging.value = false
}

const onDrop = (event) => {
    dragDepth = 0
    dragging.value = false
    if (!hasFiles(event)) return
    event.preventDefault()
    if (!attachDisabled.value) addFiles(event.dataTransfer.files)
}

// --- Smart auto-scroll: follow new content only while the reader is at the bottom ---

const NEAR_BOTTOM_PX = 80
let stick = true
let jumping = false
let lastHeight = 0
const showJump = ref(false)
const hasNew = ref(false)

const prefersReducedMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false

const distanceFromBottom = (el) => el.scrollHeight - el.scrollTop - el.clientHeight

const scrollToBottom = (smooth = false) => {
    const el = scroller.value
    if (!el) return
    el.scrollTo({ top: el.scrollHeight, behavior: smooth && !prefersReducedMotion() ? 'smooth' : 'auto' })
}

const onScroll = () => {
    const el = scroller.value
    if (!el) return
    if (distanceFromBottom(el) <= NEAR_BOTTOM_PX) {
        stick = true
        jumping = false
        showJump.value = false
        hasNew.value = false
        return
    }
    // A smooth jump passes through "not at the bottom" positions: keep following until it lands.
    if (jumping) return
    stick = false
    showJump.value = true
}

// Content grew (new item, streamed text, chart or photo loaded) or the list got shorter:
// follow it or flag it.
const onContentResize = () => {
    const el = scroller.value
    if (!el) return
    const grew = el.scrollHeight > lastHeight
    lastHeight = el.scrollHeight
    if (stick) {
        scrollToBottom()
        return
    }
    if (grew && distanceFromBottom(el) > NEAR_BOTTOM_PX) {
        hasNew.value = true
        showJump.value = true
    }
}

// The user just sent something or opened the drawer: always go to the latest.
const stickToBottom = () => {
    stick = true
    showJump.value = false
    hasNew.value = false
    nextTick(() => scrollToBottom())
}

const jumpToLatest = () => {
    stick = true
    jumping = true
    showJump.value = false
    hasNew.value = false
    scrollToBottom(true)
    nextTick(() => textarea.value?.focus({ preventScroll: true }))
}

let resizeObserver = null

watch(
    () => agent.isOpen,
    (open) => {
        if (open) stickToBottom()
    },
)

// Fallback where ResizeObserver is missing: the old "on every change" behaviour, gated by stick.
watch(
    () => [agent.items.length, agent.draft, agent.status],
    () => {
        if (!resizeObserver) nextTick(onContentResize)
    },
)

// Import chips prefill the composer and open the file picker; the rest send their text right away.
const useChip = (chip) => {
    if (!chip.attach) return send(chip.text)
    input.value = chip.prompt ?? chip.text
    nextTick(() => {
        autoGrow()
        textarea.value?.focus()
    })
    if (!attachDisabled.value) fileInput.value?.click()
}

const send = async (text) => {
    const message = String(text ?? '').trim()
    const fromComposer = text === input.value
    const images = fromComposer ? [...attachments.value] : []
    const docs = fromComposer ? [...attachedDocuments.value] : []
    const file = docs[0] ?? null
    if ((!message && !images.length && !file) || agent.isStreaming || (images.length && preparing.value)) return
    // Only a composer send of the prefilled edit replaces the turn; suggestion chips etc. are new messages.
    const rewind =
        fromComposer && editing.value?.replace && !images.length && !file
            ? { mode: 'replace_last', turnId: editing.value.id }
            : null
    editing.value = null
    if (fromComposer) {
        input.value = ''
        attachments.value = []
        attachedDocuments.value = []
    }
    nextTick(autoGrow)
    stickToBottom()
    const ok = await agent.send(message, images, rewind, docs)
    // Rejected before streaming (422 / 409 / 429): give the photos / edited text back instead of losing them.
    if (ok === false && images.length && !attachments.value.length) attachments.value = images
    if (ok === false && file && !attachedDocuments.value.length && !attachments.value.length) {
        attachedDocuments.value = docs
        if (!input.value) input.value = message
    }
    if (ok === false && rewind && !input.value) {
        input.value = message
        nextTick(autoGrow)
    }
}

// --- Answer actions: copy, ↑ to edit the last message, regenerate ---

const REGENERATE_TIP = 'Regenerate answer (uses 1 AI assist)'

const copiedId = ref(null)
let copiedTimer = null

const copyText = async (text) => {
    try {
        await navigator.clipboard.writeText(text)
        return true
    } catch {
        // Older browsers / non-secure contexts.
        const el = document.createElement('textarea')
        el.value = text
        el.setAttribute('readonly', '')
        el.style.position = 'fixed'
        el.style.opacity = '0'
        document.body.appendChild(el)
        el.select()
        const ok = document.execCommand('copy')
        el.remove()
        return ok
    }
}

// Copies the markdown source (what the assistant wrote), not the rendered HTML.
const copyAnswer = async (item) => {
    if (!(await copyText(String(item.text ?? '')))) {
        toasts.error('Could not copy to the clipboard.')
        return
    }
    copiedId.value = item.id
    clearTimeout(copiedTimer)
    copiedTimer = setTimeout(() => {
        copiedId.value = null
    }, 1500)
}

// { id: last user item id, replace: whether sending replaces that turn (else it goes as a new message) }
const editing = ref(null)

const editNote = computed(() => {
    if (!editing.value) return ''
    if (editing.value.replace) return 'Editing your last message: sending replaces it and its answer.'
    if (editing.value.blocker === 'receipt')
        return 'That message answered a receipt question, so this goes as a new message.'
    if (editing.value.blocker === 'applied') return 'A change from that turn was saved, so this goes as a new message.'
    return 'This goes as a new message.'
})

const editLast = (event) => {
    if (input.value || attachments.value.length || attachedDocument.value || agent.isStreaming || agent.quotaExhausted)
        return
    const index = lastTurnIndex(agent.items)
    const user = agent.items[index]
    if (!user?.text) return
    event.preventDefault()
    const blocker = rewindBlocker(agent.items, 'replace_last')
    editing.value = { id: user.id, replace: blocker === null, blocker }
    input.value = user.text
    nextTick(() => {
        autoGrow()
        textarea.value?.setSelectionRange(input.value.length, input.value.length)
    })
}

const cancelEdit = () => {
    editing.value = null
    input.value = ''
    nextTick(autoGrow)
    textarea.value?.focus()
}

const onComposerEscape = (event) => {
    if (!editing.value) return
    // Esc cancels the edit first; the next Esc closes the drawer.
    event.stopPropagation()
    event.preventDefault()
    cancelEdit()
}

// The edit is void once the text is cleared, photos are added, or the conversation moves on.
watch(
    () => [
        input.value === '',
        attachments.value.length + attachedDocuments.value.length,
        agent.items[lastTurnIndex(agent.items)]?.id,
        agent.isStreaming,
    ],
    ([empty, photos, lastId, streaming]) => {
        if (!editing.value) return
        if (empty || photos || streaming || lastId !== editing.value.id) editing.value = null
    },
)

const regenerate = () => {
    stickToBottom()
    agent.regenerate()
}

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

onMounted(() => {
    document.addEventListener('keydown', onGlobalKeydown)
    if (typeof ResizeObserver !== 'undefined' && content.value) {
        resizeObserver = new ResizeObserver(onContentResize)
        resizeObserver.observe(content.value)
        // The list also shrinks when the composer grows (photo previews, a taller textarea).
        if (scroller.value) resizeObserver.observe(scroller.value)
    }
})

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onGlobalKeydown)
    resizeObserver?.disconnect()
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
