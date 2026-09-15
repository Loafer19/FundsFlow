import { defineStore } from 'pinia'
import api from './api.js'
import { loadWithCache, persistCache } from './cache.js'
import { apiErrorMessage, toLocalDateStr } from './formatters.js'
import { isOnboardingDone, markOnboardingDone } from './onboarding.js'
import toasts from './toasts.js'

const CACHE = 'transactions'

export const useTransactionsStore = defineStore('transactions', {
    state: () => ({
        transactions: [],
        transactionForEdit: null,
        transactionDraftAt: null,
        tagFilterDraft: null,
        isLoading: false,
    }),

    getters: {
        filteredByDateRange: (state) => (start, end) =>
            state.transactions.filter((t) => {
                const date = toLocalDateStr(t.at)
                return date >= toLocalDateStr(start) && date <= toLocalDateStr(end)
            }),
        groupedByTags: (state) => () =>
            state.transactions.reduce((map, t) => {
                let i = 0
                do {
                    const id = t.tags[i]?.id

                    const group = map.get(id) ?? map.set(id, []).get(id)

                    group.push(t)
                } while (++i < t.tags.length)
                return map
            }, new Map()),
    },

    actions: {
        persist() {
            persistCache(CACHE, this.transactions)
        },

        async load() {
            await loadWithCache(this, {
                name: CACHE,
                key: 'transactions',
                fetch: async () => (await api.get('/transactions')).data,
                errorPrefix: 'Failed to load transactions: ',
            })
        },

        mergeTransaction(data) {
            const index = this.transactions.findIndex((t) => t.id === data.id)

            if (index === -1) {
                this.transactions.push(data)
            } else {
                this.transactions[index] = data
            }

            this.persist()
        },

        async create(raw, files = []) {
            this.isLoading = true

            try {
                const response = await api.post('/transactions', raw)
                let transaction = { ...response.data, attachments: response.data.attachments ?? [] }

                this.mergeTransaction(transaction)

                for (const file of files) {
                    const uploaded = await this.uploadAttachment(transaction.id, file, { silent: true })

                    if (!uploaded) {
                        toasts.error('Transaction saved, but some attachments failed to upload')
                        break
                    }

                    transaction = this.transactions.find((t) => t.id === transaction.id) ?? transaction
                }

                if (!isOnboardingDone()) {
                    markOnboardingDone()
                }

                toasts.success('Transaction created successfully!')

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Failed to create transaction: '))

                return false
            } finally {
                this.isLoading = false
            }
        },

        async uploadAttachment(transactionId, file, { silent = false } = {}) {
            const form = new FormData()
            form.append('file', file)

            try {
                const response = await api.post(`/transactions/${transactionId}/attachments`, form, {
                    timeout: 60000,
                })

                const index = this.transactions.findIndex((t) => t.id === transactionId)

                if (index !== -1) {
                    const current = this.transactions[index]
                    const attachments = [...(current.attachments ?? []), response.data]
                    this.transactions[index] = { ...current, attachments }
                    this.persist()
                }

                if (!silent) {
                    toasts.success('Attachment uploaded')
                }

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Failed to upload attachment: '))

                return false
            }
        },

        async deleteAttachment(transactionId, attachmentId) {
            try {
                await api.delete(`/transactions/${transactionId}/attachments/${attachmentId}`)

                const index = this.transactions.findIndex((t) => t.id === transactionId)

                if (index !== -1) {
                    const current = this.transactions[index]
                    const attachments = (current.attachments ?? []).filter((a) => a.id !== attachmentId)
                    this.transactions[index] = { ...current, attachments }
                    this.persist()
                }

                toasts.info('Attachment removed')

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Failed to delete attachment: '))

                return false
            }
        },

        async openAttachment(transactionId, attachment) {
            try {
                const response = await api.get(`/transactions/${transactionId}/attachments/${attachment.id}`, {
                    responseType: 'blob',
                    timeout: 60000,
                })

                const blobUrl = URL.createObjectURL(response.data)
                window.open(blobUrl, '_blank', 'noopener,noreferrer')
                setTimeout(() => URL.revokeObjectURL(blobUrl), 60_000)
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Failed to open attachment: '))
            }
        },

        async update(raw) {
            this.isLoading = raw.id

            try {
                const response = await api.patch('/transactions/' + raw.id, raw)
                const existing = this.transactions.find((t) => t.id === raw.id)
                const attachments = response.data.attachments ?? existing?.attachments ?? []

                this.mergeTransaction({ ...response.data, attachments })

                toasts.success('Transaction updated successfully!')

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Failed to update transaction: '))

                return false
            } finally {
                this.isLoading = false
            }
        },

        async delete(id) {
            this.isLoading = id

            try {
                await api.delete('/transactions/' + id)

                this.transactions = this.transactions.filter((t) => t.id !== id)
                this.persist()

                toasts.info('Transaction deleted successfully!')

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Failed to delete transaction: '))

                return false
            } finally {
                this.isLoading = false
            }
        },

        sort(a, b, key = 'at', dir = 'desc') {
            if (key === 'at') {
                return dir === 'asc' ? new Date(a.at) - new Date(b.at) : new Date(b.at) - new Date(a.at)
            }

            if (key === 'amount') {
                return dir === 'asc' ? a.amount - b.amount : b.amount - a.amount
            }

            return 0
        },
    },
})
