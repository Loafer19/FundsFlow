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

        async create(raw) {
            this.isLoading = true

            try {
                const response = await api.post('/transactions', raw)

                this.transactions.push(response.data)
                this.persist()

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

        async update(raw) {
            this.isLoading = raw.id

            try {
                const response = await api.patch('/transactions/' + raw.id, raw)

                const index = this.transactions.findIndex((t) => t.id === raw.id)
                this.transactions[index] = response.data
                this.persist()

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
