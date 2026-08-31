import { defineStore } from 'pinia'
import api from './api.js'
import { loadWithCache, persistCache } from './cache.js'
import { apiErrorMessage } from './formatters.js'
import toasts from './toasts.js'

const CACHE = 'recurring'

export const useRecurringTransactionsStore = defineStore('recurringTransactions', {
    state: () => ({
        rules: [],
        ruleForEdit: null,
        isLoading: false,
    }),

    actions: {
        persist() {
            persistCache(CACHE, this.rules)
        },

        async load() {
            await loadWithCache(this, {
                name: CACHE,
                key: 'rules',
                fetch: async () => (await api.get('/recurring-transactions')).data,
                errorPrefix: 'Failed to load recurring transactions: ',
            })
        },

        async create(payload) {
            this.isLoading = true

            try {
                const response = await api.post('/recurring-transactions', payload)

                this.rules.push(response.data)
                this.persist()

                toasts.success('Recurring transaction created successfully!')

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Failed to create recurring transaction: '))

                return false
            } finally {
                this.isLoading = false
            }
        },

        async update(id, payload) {
            this.isLoading = true

            try {
                const response = await api.patch('/recurring-transactions/' + id, payload)

                const index = this.rules.findIndex((r) => r.id === id)
                this.rules[index] = response.data
                this.persist()

                toasts.success('Recurring transaction updated successfully!')

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Failed to update recurring transaction: '))

                return false
            } finally {
                this.isLoading = false
            }
        },

        async toggleActive(rule) {
            this.isLoading = rule.id

            try {
                const response = await api.patch('/recurring-transactions/' + rule.id, {
                    amount: rule.amount,
                    note: rule.note,
                    frequency: rule.frequency,
                    starts_at: rule.starts_at,
                    ends_at: rule.ends_at,
                    active: !rule.active,
                    tags: rule.tags.map((tag) => tag.id),
                })

                const index = this.rules.findIndex((r) => r.id === rule.id)
                this.rules[index] = response.data
                this.persist()

                toasts.info(response.data.active ? 'Resumed' : 'Paused')

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Failed to update recurring transaction: '))

                return false
            } finally {
                this.isLoading = false
            }
        },

        async delete(id) {
            this.isLoading = id

            try {
                await api.delete('/recurring-transactions/' + id)

                this.rules = this.rules.filter((r) => r.id !== id)
                this.persist()

                toasts.info('Recurring transaction deleted successfully!')

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Failed to delete recurring transaction: '))

                return false
            } finally {
                this.isLoading = false
            }
        },
    },
})
