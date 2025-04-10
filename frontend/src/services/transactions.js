import { defineStore } from 'pinia'
import api from './api.js'

export const useTransactionsStore = defineStore('transactions', {
    state: () => ({
        transactions: [],
        isLoading: false,
        toast: {
            type: 'success',
            message: '',
        },
    }),

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
