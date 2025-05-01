import { defineStore } from 'pinia'
import api from './api.js'

export const useTransactionsStore = defineStore('transactions', {
    state: () => ({
        transactions: [],
        transactionForEdit: null,
        isLoading: false,
        toast: {
            type: 'success',
            message: '',
        },
    }),

    getters: {
        filteredByDateRange: (state) => (start, end) =>
            state.transactions.filter((t) => {
                const date = new Date(t.at)
                return date >= start && date < end
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
        async load() {
            this.isLoading = true

            try {
                const response = await api.get('/transactions')

                this.transactions = response.data
            } catch (error) {
                this.toast = {
                    type: 'error',
                    message: 'Failed to load transactions: ' + (error.response?.data?.error || error.message),
                }
            } finally {
                this.isLoading = false
            }
        },

        async create(raw) {
            this.isLoading = true

            try {
                const response = await api.post('/transactions', raw)

                this.transactions.push(response.data)

                this.toast = {
                    type: 'success',
                    message: 'Transaction created successfully!',
                }
            } catch (error) {
                this.toast = {
                    type: 'error',
                    message: 'Failed to create transaction: ' + (error.response?.data?.error || error.message),
                }
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

                this.toast = {
                    type: 'success',
                    message: 'Transaction updated successfully!',
                }
            } catch (error) {
                this.toast = {
                    type: 'error',
                    message: 'Failed to update transaction: ' + (error.response?.data?.error || error.message),
                }
            } finally {
                this.isLoading = false
            }
        },

        async delete(id) {
            this.isLoading = id

            try {
                await api.delete('/transactions/' + id)

                this.transactions = this.transactions.filter((t) => t.id !== id)

                this.toast = {
                    type: 'info',
                    message: 'Transaction deleted successfully!',
                }
            } catch (error) {
                this.toast = {
                    type: 'error',
                    message: 'Failed to delete transaction: ' + (error.response?.data?.error || error.message),
                }
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
