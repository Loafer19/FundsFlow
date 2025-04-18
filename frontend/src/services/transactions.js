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
    },
})
