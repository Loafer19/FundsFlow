import { defineStore } from 'pinia'
import api from './api.js'
import { apiErrorMessage } from './formatters.js'
import toasts from './toasts.js'

export const useBudgetsStore = defineStore('budgets', {
    state: () => ({
        budgets: [],
        budgetForEdit: null,
        isLoading: false,
    }),

    actions: {
        async load() {
            this.isLoading = true

            try {
                const response = await api.get('/budgets')

                this.budgets = response.data
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Failed to load budgets: '))
            } finally {
                this.isLoading = false
            }
        },

        async create(payload) {
            this.isLoading = true

            try {
                const response = await api.post('/budgets', payload)

                this.budgets.push(response.data)

                toasts.success('Budget created successfully!')

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Failed to create budget: '))

                return false
            } finally {
                this.isLoading = false
            }
        },

        async update(id, payload) {
            this.isLoading = true

            try {
                const response = await api.patch('/budgets/' + id, payload)

                const index = this.budgets.findIndex((b) => b.id === id)
                this.budgets[index] = response.data

                toasts.success('Budget updated successfully!')

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Failed to update budget: '))

                return false
            } finally {
                this.isLoading = false
            }
        },

        async delete(id) {
            this.isLoading = id

            try {
                await api.delete('/budgets/' + id)

                this.budgets = this.budgets.filter((b) => b.id !== id)

                toasts.info('Budget deleted successfully!')

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Failed to delete budget: '))

                return false
            } finally {
                this.isLoading = false
            }
        },
    },
})
