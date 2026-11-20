import { defineStore } from 'pinia'
import api from './api.js'
import { persistCache } from './cache.js'
import { apiErrorMessage } from './formatters.js'
import { showModal } from './modal.js'
import toasts from './toasts.js'

const CACHE = 'recurring'

export const useRecurringTransactionsStore = defineStore('recurringTransactions', {
    state: () => ({
        rules: [],
        ruleForEdit: null,
        isLoading: false,
        filterTypeId: null,
    }),

    getters: {
        visibleRules: (state) => {
            if (state.filterTypeId == null) return state.rules
            if (state.filterTypeId === 'none') return state.rules.filter((rule) => rule.transaction_type_id == null)
            return state.rules.filter((rule) => Number(rule.transaction_type_id) === Number(state.filterTypeId))
        },
    },

    actions: {
        openAll() {
            this.filterTypeId = null
            showModal('recurring_modal')
        },

        openFiltered(typeId) {
            this.filterTypeId = typeId == null ? 'none' : typeId
            showModal('recurring_modal')
        },

        clearFilter() {
            this.filterTypeId = null
        },
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
                    active: !rule.active,
                })

                const index = this.rules.findIndex((r) => r.id === rule.id)
                this.rules[index] = response.data
                this.persist()

                toasts.info(response.data.active ? 'Recurring payment resumed!' : 'Recurring payment paused!')

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
