import { defineStore } from 'pinia'
import api from './api.js'
import { bootstrapStores } from './bootstrap.js'
import { apiErrorMessage } from './formatters.js'
import { reportErrorMessage, requestReport } from './report.js'
import toasts from './toasts.js'

// Kept outside Pinia state: a reactive proxy of AbortSignal is rejected by fetch().
let controller = null

const apiBase = () => String(import.meta.env.VITE_API_URL ?? '').replace(/\/$/, '')

// Server-Sent Events over fetch (EventSource cannot POST or send the bearer token).
const parseEvents = (buffer, onEvent) => {
    const blocks = buffer.split(/\r?\n\r?\n/)
    const rest = blocks.pop() ?? ''

    for (const block of blocks) {
        let event = 'message'
        const data = []

        for (const line of block.split(/\r?\n/)) {
            if (line.startsWith(':')) continue
            if (line.startsWith('event:')) event = line.slice(6).trim()
            else if (line.startsWith('data:')) data.push(line.slice(5).replace(/^ /, ''))
        }

        if (!data.length) continue

        try {
            onEvent(event, JSON.parse(data.join('\n')))
        } catch {
            // ignore malformed event
        }
    }

    return rest
}

export const useAgentStore = defineStore('agent', {
    state: () => ({
        isOpen: false,
        conversationId: null,
        items: [],
        draft: '',
        status: '',
        isStreaming: false,
        isLoadingHistory: false,
        historyLoaded: false,
        quota: null,
        busyPendingId: null,
        // What the user is looking at (published by App.vue): { tab, period_type, from, to, tag_ids, untagged }.
        screenContext: null,
        reportBusy: '',
    }),

    getters: {
        quotaExhausted: (state) => state.quota != null && state.quota.limit > 0 && state.quota.remaining < 1,
    },

    actions: {
        open() {
            this.isOpen = true
            if (!this.historyLoaded) this.loadHistory()
        },

        close() {
            this.isOpen = false
        },

        toggle() {
            if (this.isOpen) this.close()
            else this.open()
        },

        async loadHistory() {
            this.isLoadingHistory = true

            try {
                const { data } = await api.get('/agent/history', { timeout: 15000 })
                this.conversationId = data.conversation_id ?? null
                this.items = Array.isArray(data.items) ? data.items : []
                this.quota = data.quota ?? null
                this.historyLoaded = true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Failed to load the assistant chat: '))
            } finally {
                this.isLoadingHistory = false
            }
        },

        // Quota only (after the daily reset passes while the drawer is open). Non-fatal on failure.
        async refreshQuota() {
            try {
                const { data } = await api.get('/account/ai-quota', { timeout: 15000 })
                if (!data || typeof data !== 'object') return
                this.quota = { ...this.quota, ...data, resets_at: data.resets_at ?? data.reset_at, hint: null }
            } catch {
                // keep the last known quota
            }
        },

        upsert(item) {
            if (!item || typeof item !== 'object') return
            const index = this.items.findIndex((existing) => existing.id === item.id)
            if (index === -1) this.items.push(item)
            else this.items[index] = item
        },

        handleEvent(event, data, localUserId) {
            switch (event) {
                case 'meta':
                    if (data.stale) this.items = this.items.filter((item) => item.id === localUserId)
                    this.conversationId = data.conversation_id ?? this.conversationId
                    break
                case 'user': {
                    const index = this.items.findIndex((item) => item.id === localUserId)
                    // Keep the in-memory thumbnails of the photos just sent (the server only stores a count).
                    if (index !== -1 && data.item) {
                        const { localImages } = this.items[index]
                        this.items[index] = localImages ? { ...data.item, localImages } : data.item
                    }
                    break
                }
                case 'delta':
                    this.draft += data.text ?? ''
                    this.status = ''
                    break
                case 'tool':
                    // Text streamed before a tool call is only a preamble; the real answer comes later.
                    this.draft = ''
                    this.status = data.label || 'Working'
                    break
                case 'answer':
                case 'notice':
                case 'show':
                case 'chart':
                case 'ask':
                    this.draft = ''
                    this.status = ''
                    this.upsert(data.item)
                    break
                case 'confirm':
                    this.draft = ''
                    this.status = ''
                    for (const item of this.items) {
                        if (item.kind === 'confirm' && item.status === 'pending') item.status = 'outdated'
                    }
                    this.upsert(data.item)
                    break
                case 'quota':
                    this.quota = data
                    break
                case 'error':
                    // The matching notice item is pushed too; only surface errors that have no item.
                    if (!data.item_id && data.message) {
                        this.upsert({
                            id: crypto.randomUUID(),
                            role: 'assistant',
                            kind: 'notice',
                            tone: 'error',
                            text: data.message,
                        })
                    }
                    break
                case 'done':
                    if (data.conversation_id) this.conversationId = data.conversation_id
                    break
            }
        },

        // images: [{ file: Blob (downscaled JPEG), name, url (object URL for the bubble thumbnail) }]
        async send(text, images = []) {
            const message = String(text ?? '').trim()
            const photos = Array.isArray(images) ? images.filter((image) => image?.file) : []
            if ((!message && !photos.length) || this.isStreaming) return

            const localUserId = `local-${crypto.randomUUID()}`
            this.items.push({
                id: localUserId,
                role: 'user',
                kind: 'text',
                text: message,
                ...(photos.length ? { photos: photos.length, localImages: photos.map((image) => image.url) } : {}),
            })
            this.isStreaming = true
            this.draft = ''
            this.status = 'Thinking…'
            controller = new AbortController()

            try {
                const token = localStorage.getItem('token')
                const headers = {
                    Accept: 'text/event-stream',
                    ...(token ? { Authorization: `Bearer ${token}` } : {}),
                }
                let body

                if (photos.length) {
                    // multipart: the browser sets the boundary, so no Content-Type here.
                    body = new FormData()
                    body.append('message', message)
                    if (this.conversationId) body.append('conversation_id', this.conversationId)
                    if (this.screenContext) body.append('context', JSON.stringify(this.screenContext))
                    for (const image of photos) body.append('images[]', image.file, image.name || 'receipt.jpg')
                } else {
                    headers['Content-Type'] = 'application/json'
                    body = JSON.stringify({
                        message,
                        conversation_id: this.conversationId,
                        ...(this.screenContext ? { context: this.screenContext } : {}),
                    })
                }

                const response = await fetch(`${apiBase()}/agent/chat`, {
                    method: 'POST',
                    headers,
                    body,
                    signal: controller.signal,
                })

                if (!response.ok || !response.body) {
                    let detail = ''
                    try {
                        detail = (await response.json())?.message ?? ''
                    } catch {
                        // non-JSON error body
                    }

                    if (response.status === 401) {
                        const { useAuthStore } = await import('./auth.js')
                        useAuthStore().clearSession()
                        toasts.info('Session expired, please log in again!')
                    }

                    if (response.status === 429) detail = 'Too many messages. Wait a minute and try again.'
                    if (response.status === 413) detail = 'Those photos are too large. Try fewer or smaller ones.'

                    this.items = this.items.filter((item) => item.id !== localUserId)
                    toasts.error(detail || `Assistant request failed (${response.status}).`)
                    return false
                }

                const reader = response.body.getReader()
                const decoder = new TextDecoder()
                let buffer = ''

                while (true) {
                    const { value, done } = await reader.read()
                    if (done) break
                    buffer += decoder.decode(value, { stream: true })
                    buffer = parseEvents(buffer, (event, data) => this.handleEvent(event, data, localUserId))
                }

                buffer += decoder.decode()
                parseEvents(`${buffer}\n\n`, (event, data) => this.handleEvent(event, data, localUserId))
                return true
            } catch (error) {
                if (error?.name !== 'AbortError') toasts.error('Lost connection to the assistant. Try again.')
                return true
            } finally {
                if (this.draft.trim()) {
                    // Stream ended without a final answer event (connection cut): keep what arrived.
                    this.items.push({ id: crypto.randomUUID(), role: 'assistant', kind: 'text', text: this.draft })
                }
                this.draft = ''
                this.status = ''
                this.isStreaming = false
                controller = null
            }
        },

        stop() {
            controller?.abort()
        },

        async resolve(item, action) {
            if (!item?.pending_id || this.busyPendingId) return
            this.busyPendingId = item.pending_id

            try {
                const { data } = await api.post(
                    '/agent/confirm',
                    { pending_id: item.pending_id, action },
                    { timeout: 30000 },
                )
                item.status = action === 'cancel' ? 'cancelled' : data.ok ? 'confirmed' : 'failed'
                this.upsert(data.item)

                if (data.refresh) await bootstrapStores()
            } catch (error) {
                if (error?.response?.status === 409) {
                    item.status = 'outdated'
                    const data = error.response.data ?? {}
                    toasts.info(data.message || 'This preview is outdated.')
                } else {
                    toasts.error(apiErrorMessage(error, 'Failed to apply the change: '))
                }
            } finally {
                this.busyPendingId = null
            }
        },

        setScreenContext(context) {
            this.screenContext = context && typeof context === 'object' ? context : null
        },

        // Report card buttons: 'download' / 'print' return the PDF blob, 'telegram' sends it to the linked chat.
        async fetchReport(request, delivery = 'download') {
            if (!request || this.reportBusy) return null
            this.reportBusy = delivery

            try {
                if (delivery === 'telegram') {
                    toasts.success(await requestReport(request, 'telegram', '/agent/report'))
                    return null
                }

                return await requestReport(request, 'download', '/agent/report')
            } catch (error) {
                toasts.error(await reportErrorMessage(error))
                return null
            } finally {
                this.reportBusy = ''
            }
        },

        async reset() {
            this.stop()

            try {
                const { data } = await api.delete('/agent/conversation', { timeout: 15000 })
                this.conversationId = data.conversation_id ?? null
                this.quota = data.quota ?? this.quota
                this.items = []
                this.draft = ''
                this.status = ''
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Failed to reset the chat: '))
            }
        },

        clearLocal() {
            this.stop()
            this.$reset()
        },
    },
})
