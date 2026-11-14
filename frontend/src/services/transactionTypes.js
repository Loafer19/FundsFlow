import { defineStore } from 'pinia'
import api from './api.js'
import { persistCache } from './cache.js'
import { apiErrorMessage } from './formatters.js'
import toasts from './toasts.js'

const CACHE = 'transaction_types'

// Transaction types with custom fields (Fuel: liters, price per liter, a computed total).
// Archived types (archived_at set) still label old transactions but are not offered for new ones.
export const useTransactionTypesStore = defineStore('transactionTypes', {
    state: () => ({
        types: [],
        isLoading: false,
    }),

    getters: {
        active: (state) => state.types.filter((type) => !type.archived_at),
        byId: (state) => (id) => (id == null ? null : (state.types.find((type) => type.id === Number(id)) ?? null)),
    },

    actions: {
        persist() {
            persistCache(CACHE, this.types)
        },

        replace(type) {
            const index = this.types.findIndex((t) => t.id === type.id)
            if (index === -1) this.types.push(type)
            else this.types[index] = { ...this.types[index], ...type }
            this.persist()
        },

        async fetch() {
            try {
                const { data } = await api.get('/transaction-types')
                this.types = Array.isArray(data) ? data : (data?.data ?? [])
                this.persist()
            } catch {
                // Bootstrap data stays; counts are only a little stale.
            }
        },

        async create(raw) {
            this.isLoading = true

            try {
                const { data } = await api.post('/transaction-types', raw)
                this.replace(data)
                toasts.success('Type created!')

                return data
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Could not create type: '))

                return null
            } finally {
                this.isLoading = false
            }
        },

        async update(id, raw) {
            this.isLoading = true

            try {
                const { data } = await api.patch(`/transaction-types/${id}`, raw)
                this.replace(data)
                toasts.success('Type updated!')

                return data
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Could not update type: '))

                return null
            } finally {
                this.isLoading = false
            }
        },

        async archive(id) {
            this.isLoading = id

            try {
                await api.delete(`/transaction-types/${id}`)
                const type = this.byId(id)
                if (type) this.replace({ ...type, archived_at: new Date().toISOString() })
                toasts.success('Type archived!')

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Could not archive type: '))

                return false
            } finally {
                this.isLoading = false
            }
        },

        async restore(id) {
            this.isLoading = id

            try {
                const { data } = await api.post(`/transaction-types/${id}/restore`)
                this.replace(data?.id ? data : { ...this.byId(id), archived_at: null })
                toasts.success('Type restored!')

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Could not restore type: '))

                return false
            } finally {
                this.isLoading = false
            }
        },
    },
})
