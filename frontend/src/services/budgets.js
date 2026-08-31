import { defineStore } from 'pinia'
import api from './api.js'
import { loadWithCache, persistCache } from './cache.js'
import { apiErrorMessage } from './formatters.js'
import toasts from './toasts.js'

const CACHE = 'budgets'

export const useBudgetsStore = defineStore('budgets', {
    state: () => ({
        budgets: [],
        budgetForEdit: null,
        isLoading: false,
    }),

    actions: {
        persist() {
            persistCache(CACHE, this.budgets)
        },

        async load() {
            await loadWithCache(this, {
                name: CACHE,
                key: 'budgets',
                fetch: async () => (await api.get('/budgets')).data,
                errorPrefix: 'Failed to load budgets: ',
            })
        },

        async create(payload) {
            this.isLoading = true

            try {
                const response = await api.post('/budgets', payload)

                this.budgets.push(response.data)
                this.persist()

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
                this.persist()

                toasts.success('Budget updated successfully!')

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Failed to update budget: '))

                return false
            } finally {
                this.isLoading = false
            }
        },

        async pause(id) {
            this.isLoading = id

            try {
                const response = await api.post('/budgets/' + id + '/pause')

                const index = this.budgets.findIndex((b) => b.id === id)
                this.budgets[index] = response.data
                this.persist()

                toasts.info('Budget paused')

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Failed to pause budget: '))

                return false
            } finally {
                this.isLoading = false
            }
        },

        async resume(id) {
            this.isLoading = id

            try {
                const response = await api.post('/budgets/' + id + '/resume')

                const index = this.budgets.findIndex((b) => b.id === id)
                this.budgets[index] = response.data
                this.persist()

                toasts.success('Budget resumed')

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Failed to resume budget: '))

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
                this.persist()

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
