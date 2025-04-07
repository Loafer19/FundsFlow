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

    getters: {
        list: (state) => () => state.buildTagsList(state.tags),
    },

    actions: {
        async load() {
            this.isLoading = true

            try {
                const response = await api.get('/tags')

                this.tags = response.data
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

        async delete(id) {
            this.isLoading = id

            try {
                await api.delete('/tags/' + id)

                this.tags = this.tags.filter((t) => t.id !== id)

                this.tags.forEach((t) => {
                    if (t.parent_id === id) {
                        t.parent_id = null
                    }
                })

                this.toast = {
                    type: 'info',
                    message: 'Tag deleted successfully!',
                }
            } catch (error) {
                this.toast = {
                    type: 'error',
                    message: 'Failed to delete tag: ' + (error.response?.data?.error || error.message),
                }
            } finally {
                this.isLoading = false
            }
        },

        buildTagsList(tags, parent_id = null, depth = 0) {
            const children = tags
                .filter((tag) => tag.parent_id === parent_id)
                .sort((a, b) => a.title.localeCompare(b.title))

            const result = []

            children.forEach((child) => {
                result.push({ ...child, depth })
                result.push(...this.buildTagsList(tags, child.id, depth + 1))
            })

            return result
        },
    },
})
