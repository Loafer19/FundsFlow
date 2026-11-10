import { defineStore } from 'pinia'
import api from './api.js'
import { persistCache } from './cache.js'
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

        async create(payload) {
            this.isLoading = true

            try {
                const response = await api.post('/recurring-transactions', payload)

                this.rules.push(response.data)
                this.persist()

                toasts.success('Recurring payment created!')

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Could not create recurring payment: '))

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

                toasts.success('Recurring payment updated!')

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Could not update recurring payment: '))

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

                toasts.info(
                    response.data.active
                        ? 'Recurring payment resumed!'
                        : 'Recurring payment paused!',
                )

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Could not update recurring payment: '))

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

                toasts.info('Recurring payment deleted!')

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Could not delete recurring payment: '))

                return false
            } finally {
                this.isLoading = false
            }
        },
    },
})
