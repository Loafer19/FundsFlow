<template>
    <div v-if="loading && !tickets.length && !active" class="flex justify-center py-8">
        <span class="loading loading-dots loading-md text-base-content/40"></span>
    </div>

    <!-- One request: the conversation and a reply box. -->
    <div v-else-if="active">
        <div class="flex items-start gap-2 mb-3">
            <button type="button" class="btn btn-ghost btn-square btn-sm shrink-0" aria-label="Back to my requests"
                @click="active = null">
                <ChevronLeft :size="20" />
            </button>
            <div class="min-w-0 flex-1">
                <div class="font-medium break-words [overflow-wrap:anywhere]">{{ active.subject }}</div>
                <div class="text-xs text-base-content/50">#{{ active.id }} · {{ formatDate(active.created_at) }}</div>
            </div>
            <span class="badge badge-sm shrink-0" :class="statusOf(active).badge">{{ statusOf(active).label }}</span>
        </div>

        <div ref="threadRef" class="support-thread overflow-y-auto overflow-x-hidden mb-3">
            <div v-for="message in thread" :key="message.key" class="chat"
                :class="message.author === 'admin' ? 'chat-start' : 'chat-end'">
                <div class="chat-header text-xs text-base-content/50 mb-0.5">
                    {{ message.author === 'admin' ? 'FundsFlow support' : 'You' }}
                    <time class="ml-1">{{ formatDate(message.created_at) }}</time>
                </div>
                <div class="chat-bubble text-sm whitespace-pre-wrap break-words [overflow-wrap:anywhere] max-w-[85%]"
                    :class="message.author === 'admin' ? 'chat-bubble-info' : ''">{{ message.body }}</div>
            </div>
        </div>

        <form class="flex items-end gap-2" @submit.prevent="sendReply">
            <textarea v-model="reply" class="textarea textarea-sm flex-1 min-h-10 h-16" maxlength="5000"
                :placeholder="active.status === 'closed' ? 'Write to reopen this request' : 'Write a reply'"
                aria-label="Reply" @keydown.enter.exact.meta.prevent="sendReply"
                @keydown.enter.exact.ctrl.prevent="sendReply"></textarea>
            <button type="submit" class="btn btn-primary btn-square" aria-label="Send reply"
                :disabled="replying || !reply.trim()">
                <span v-if="replying" class="loading loading-spinner loading-sm"></span>
                <Send v-else :size="20" />
            </button>
        </form>
    </div>

    <!-- My requests -->
    <template v-else>
        <div class="flex items-center justify-between gap-2 mb-3">
            <span class="text-sm text-base-content/60">My requests</span>
            <button type="button" class="btn btn-primary btn-sm" @click="showModal('support_modal')">
                <Plus :size="20" />
                New request
            </button>
        </div>

        <p v-if="!tickets.length" class="text-sm text-base-content/50 text-center py-6">
            No requests yet. Questions, problems or ideas: send them here and we'll reply.
        </p>

        <ul v-else class="flex flex-col gap-1 support-list overflow-y-auto overflow-x-hidden">
            <li v-for="ticket in tickets" :key="ticket.id">
                <button type="button"
                    class="w-full text-left rounded-box px-3 py-2 hover:bg-base-200 focus-visible:bg-base-200 flex items-center gap-3"
                    @click="open(ticket)">
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-medium truncate">{{ ticket.subject }}</span>
                        <span class="block text-xs text-base-content/50">#{{ ticket.id }} · {{ formatDate(ticket.updated_at) }}</span>
                    </span>
                    <span v-if="ticket.last_author === 'admin' && ticket.status !== 'closed'"
                        class="badge badge-sm badge-info shrink-0">Replied</span>
                    <span v-else class="badge badge-sm shrink-0" :class="statusOf(ticket).badge">{{ statusOf(ticket).label }}</span>
                </button>
            </li>
        </ul>

        <p class="text-xs text-base-content/50 mt-3">
            Replies also come to Telegram when it's linked. Quick answers:
            <a :href="FAQ_URL" target="_blank" rel="noopener" class="link">FAQ</a>.
        </p>
    </template>
</template>

<script setup>
import { ChevronLeft, Plus, Send } from 'lucide-vue-next'
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { apiErrorMessage, formatDate } from '../services/formatters.js'
import { showModal } from '../services/modal.js'
import {
    FAQ_URL,
    getSupportTicket,
    listSupportTickets,
    replyToSupportTicket,
    SUPPORT_STATUS,
} from '../services/support.js'
import toasts from '../services/toasts.js'

const tickets = ref([])
const loading = ref(false)
const active = ref(null)
const reply = ref('')
const replying = ref(false)
const threadRef = ref(null)

const statusOf = (ticket) => SUPPORT_STATUS[ticket.status] ?? SUPPORT_STATUS.new

// The opening message is the ticket body; replies follow.
const thread = computed(() => {
    if (!active.value) return []
    return [
        { key: 'body', author: 'user', body: active.value.body, created_at: active.value.created_at },
        ...(active.value.messages || []).map((message) => ({ ...message, key: message.id })),
    ]
})

const scrollToEnd = async () => {
    await nextTick()
    if (threadRef.value) threadRef.value.scrollTop = threadRef.value.scrollHeight
}

const refresh = async () => {
    loading.value = true
    try {
        const { data } = await listSupportTickets()
        tickets.value = data
    } catch (error) {
        toasts.error(apiErrorMessage(error, 'Could not load requests: '))
    } finally {
        loading.value = false
    }
}

const open = async (ticket) => {
    active.value = { ...ticket, messages: [] }
    try {
        const { data } = await getSupportTicket(ticket.id)
        active.value = data
        scrollToEnd()
    } catch (error) {
        active.value = null
        toasts.error(apiErrorMessage(error, 'Could not open the request: '))
    }
}

const sendReply = async () => {
    const text = reply.value.trim()
    if (!text || replying.value || !active.value) return
    replying.value = true
    try {
        const { data } = await replyToSupportTicket(active.value.id, text)
        active.value = data
        reply.value = ''
        scrollToEnd()
        refresh()
    } catch (error) {
        toasts.error(apiErrorMessage(error, 'Could not send: '))
    } finally {
        replying.value = false
    }
}

// A request sent from the Feedback form shows up right away.
const onCreated = () => refresh()

onMounted(() => {
    refresh()
    window.addEventListener('support:created', onCreated)
})
onBeforeUnmount(() => window.removeEventListener('support:created', onCreated))

defineExpose({ refresh })
</script>

<style scoped>
.support-thread {
    max-height: 38vh;
}

.support-list {
    max-height: 50vh;
}
</style>
