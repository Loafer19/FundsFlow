import { defineStore } from 'pinia'
import api from './api.js'
import { apiErrorMessage } from './formatters.js'
import toasts from './toasts.js'

export const useTagsStore = defineStore('tags', {
    state: () => ({
        tags: [],
        tagForEdit: null,
        isLoading: false,
    }),

    getters: {
        list: (state) => () => state.buildTagsList(state.tags),
        forBalances: (state) => () => state.list().filter((tag) => tag.calc_balance),
    },

    actions: {
        async load() {
            this.isLoading = true

            try {
                const response = await api.get('/tags')

                this.tags = response.data
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Failed to load tags: '))
            } finally {
                this.isLoading = false
            }
        },

        normalizePayload(raw) {
            return {
                ...raw,
                parent_id: raw.parent_id === '' || raw.parent_id == null ? null : raw.parent_id,
            }
        },

        async create(raw) {
            this.isLoading = true

            try {
                const response = await api.post('/tags', this.normalizePayload(raw))

                this.tags.push(response.data)

                toasts.success('Tag created successfully!')

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Failed to create tag: '))

                return false
            } finally {
                this.isLoading = false
            }
        },

        async update(raw) {
            this.isLoading = true

            try {
                const response = await api.patch('/tags/' + raw.id, this.normalizePayload(raw))

                const index = this.tags.findIndex((t) => t.id === raw.id)
                this.tags[index] = response.data

                toasts.success('Tag updated successfully!')

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Failed to update tag: '))

                return false
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

                toasts.info('Tag deleted successfully!')

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Failed to delete tag: '))

                return false
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
