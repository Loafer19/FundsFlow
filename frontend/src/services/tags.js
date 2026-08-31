import { defineStore } from 'pinia'
import api from './api.js'
import { loadWithCache, persistCache } from './cache.js'
import { apiErrorMessage } from './formatters.js'
import toasts from './toasts.js'

const CACHE = 'tags'

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
        persist() {
            persistCache(CACHE, this.tags)
        },

        async load() {
            await loadWithCache(this, {
                name: CACHE,
                key: 'tags',
                fetch: async () => (await api.get('/tags')).data,
                errorPrefix: 'Failed to load tags: ',
            })
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
                this.persist()

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
                this.persist()

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

                const toRemove = new Set([id])

                for (let added = true; added; ) {
                    added = false

                    this.tags.forEach((t) => {
                        if (toRemove.has(t.parent_id) && !toRemove.has(t.id)) {
                            toRemove.add(t.id)
                            added = true
                        }
                    })
                }

                this.tags = this.tags.filter((t) => !toRemove.has(t.id))
                this.persist()

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
