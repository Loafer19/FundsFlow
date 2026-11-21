import { defineStore } from 'pinia'
import api from './api.js'
import { apiErrorMessage } from './formatters.js'
import toasts from './toasts.js'

// Analytics → My cards: the user's own stat / chart / pie cards. The numbers are computed on the
// server for the Analytics date range (the same code the assistant and MCP use), so every load
// sends the range and the cards come back with their `data`.
export const useDashboardCardsStore = defineStore('dashboardCards', {
    state: () => ({
        cards: [],
        loaded: false,
        isLoading: false,
        isSaving: false,
        range: null,
        // Card being edited in the editor modal (null = adding a new one); `draft` prefills a new card.
        editing: null,
        draft: null,
        editorOpen: false,
    }),

    actions: {
        params(range = this.range) {
            return range ? { from: range.from, to: range.to } : {}
        },

        async load(range) {
            if (range) this.range = range
            const requested = JSON.stringify(this.range)
            this.isLoading = true

            try {
                const { data } = await api.get('/dashboard-cards', { params: this.params(), timeout: 20000 })
                // A newer range was requested while this one loaded: keep the newer answer.
                if (requested !== JSON.stringify(this.range)) return
                this.cards = Array.isArray(data) ? data : []
                this.loaded = true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Could not load cards: '))
            } finally {
                this.isLoading = false
            }
        },

        replace(card) {
            const index = this.cards.findIndex((c) => c.id === card.id)
            if (index === -1) this.cards.push(card)
            else this.cards[index] = card
        },

        async create(payload) {
            this.isSaving = true

            try {
                const { data } = await api.post('/dashboard-cards', payload, { params: this.params() })
                this.replace(data)
                toasts.success('Card added!')

                return data
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Could not add card: '))

                return null
            } finally {
                this.isSaving = false
            }
        },

        async update(id, payload, message = 'Card updated!') {
            this.isSaving = true

            try {
                const { data } = await api.patch(`/dashboard-cards/${id}`, payload, { params: this.params() })
                this.replace(data)
                toasts.success(message)

                return data
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Could not update card: '))

                return null
            } finally {
                this.isSaving = false
            }
        },

        async setSize(card, size) {
            if (card.size === size) return
            const before = card.size
            card.size = size

            try {
                await api.patch(`/dashboard-cards/${card.id}`, { size })
                toasts.success('Card resized!')
            } catch (error) {
                card.size = before
                toasts.error(apiErrorMessage(error, 'Could not resize card: '))
            }
        },

        async remove(card) {
            const index = this.cards.findIndex((c) => c.id === card.id)
            if (index === -1) return
            this.cards.splice(index, 1)

            try {
                await api.delete(`/dashboard-cards/${card.id}`)
                toasts.success('Card deleted!')
            } catch (error) {
                this.cards.splice(index, 0, card)
                toasts.error(apiErrorMessage(error, 'Could not delete card: '))
            }
        },

        // Saves the order the grid already shows (the drag moved the cards locally).
        async reorder(ids) {
            const before = this.cards.slice()
            const byId = new Map(this.cards.map((c) => [c.id, c]))
            this.cards = ids.map((id) => byId.get(id)).filter(Boolean)
            for (const [position, card] of this.cards.entries()) card.position = position

            try {
                await api.put('/dashboard-cards/order', { ids })
                toasts.success('Cards reordered!')
            } catch (error) {
                this.cards = before
                toasts.error(apiErrorMessage(error, 'Could not reorder cards: '))
            }
        },

        // Live preview for the editor: nothing is saved. Errors come back as text for the preview pane.
        async preview(payload, signal) {
            const { data } = await api.post('/dashboard-cards/preview', payload, { params: this.params(), signal })

            return data
        },

        openEditor(card = null, draft = null) {
            this.editing = card
            this.draft = draft
            this.editorOpen = true
        },

        closeEditor() {
            this.editorOpen = false
            this.editing = null
            this.draft = null
        },
    },
})
