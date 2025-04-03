import { defineStore } from 'pinia'
import api from './api.js'

export const useTagsStore = defineStore('tags', {
    state: () => ({
        tags: [],
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
                const response = await api.get('/tags')

                this.tags = response.data
                // this.toast = {
                //     type: 'success',
                //     message: 'Tags loaded successfully!',
                // }
            } catch (error) {
                this.toast = {
                    type: 'error',
                    message: 'Failed to load tags: ' + (error.response?.data?.error || error.message),
                }
            } finally {
                this.isLoading = false
            }
        },

        async create(raw) {
            this.isLoading = true

            try {
                const response = await api.post('/tags', raw)

                this.tags.push(response.data)

                this.toast = {
                    type: 'success',
                    message: 'Tag created successfully!',
                }
            } catch (error) {
                this.toast = {
                    type: 'error',
                    message: 'Failed to create tag: ' + (error.response?.data?.error || error.message),
                }
            } finally {
                this.isLoading = false
            }
        },
    },
})
