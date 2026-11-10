import { defineStore } from 'pinia'
import api from './api.js'
import { persistCache } from './cache.js'
import { apiErrorMessage } from './formatters.js'
import toasts from './toasts.js'

const CACHE = 'budgets'

const isGoal = (item, payload) => (payload?.type ?? item?.type) === 'goal'
const noun = (item, payload) => (isGoal(item, payload) ? 'goal' : 'budget')
const Noun = (item, payload) => (isGoal(item, payload) ? 'Goal' : 'Budget')

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

        async reload() {
            try {
                const response = await api.get('/budgets')
                this.budgets = response.data
                this.persist()
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Could not load budgets: '))
            }
        },

        async create(payload) {
            this.isLoading = true

            try {
                const response = await api.post('/budgets', payload)

                this.budgets.push(response.data)
                this.persist()

                toasts.success(`${Noun(null, payload)} created!`)

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, `Could not create ${noun(null, payload)}: `))

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

                toasts.success(`${Noun(this.budgets.find((b) => b.id === id), payload)} updated!`)

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, `Could not update ${noun(this.budgets.find((b) => b.id === id), payload)}: `))

                return false
            } finally {
                this.isLoading = false
            }
        },

        async pause(id) {
            this.isLoading = id
            const item = this.budgets.find((b) => b.id === id)

            try {
                const response = await api.post('/budgets/' + id + '/pause')

                const index = this.budgets.findIndex((b) => b.id === id)
                this.budgets[index] = response.data
                this.persist()

                toasts.info(`${Noun(item)} paused!`)

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, `Could not pause ${noun(item)}: `))

                return false
            } finally {
                this.isLoading = false
            }
        },

        async resume(id) {
            this.isLoading = id
            const item = this.budgets.find((b) => b.id === id)

            try {
                const response = await api.post('/budgets/' + id + '/resume')

                const index = this.budgets.findIndex((b) => b.id === id)
                this.budgets[index] = response.data
                this.persist()

                toasts.success(`${Noun(item)} resumed!`)

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, `Could not resume ${noun(item)}: `))

                return false
            } finally {
                this.isLoading = false
            }
        },

        async delete(id) {
            this.isLoading = id
            const item = this.budgets.find((b) => b.id === id)

            try {
                await api.delete('/budgets/' + id)

                this.budgets = this.budgets.filter((b) => b.id !== id)
                this.persist()

                toasts.info(`${Noun(item)} deleted!`)

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, `Could not delete ${noun(item)}: `))

                return false
            } finally {
                this.isLoading = false
            }
        },
    },
})
