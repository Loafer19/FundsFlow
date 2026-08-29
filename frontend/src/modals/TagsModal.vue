<template>
    <dialog id="tags_modal" class="modal">
        <div class="modal-box max-w-lg">
            <div class="flex justify-between items-center mb-4">
                <h2 class="card-title">
                    <Tag :size="24" />
                    Manage Tags
                </h2>
                <button @click="showModal('tags_add_modal')" class="btn btn-outline btn-info btn-sm">
                    Add Tag
                    <Plus :size="20" />
                </button>
            </div>

            <EmptyState v-if="!tagsStore.list().length" icon="🏷️" title="No tags yet"
                description="Create a few to organize transactions" action-label="Add Tag"
                @action="showModal('tags_add_modal')" />

            <div v-else class="tags-list">
                <table class="table">
                    <tbody>
                        <tr v-for="tag in tagsStore.list()" :key="tag.id">
                            <td :style="{ paddingLeft: `${tag.depth * 20}px` }">
                                <div class="flex items-center gap-2">
                                    <div class="badge badge-soft badge-info text-xl py-4 px-2">
                                        {{ tag.emoji }}
                                    </div>
                                    <span>{{ tag.title }}</span>
                                </div>
                            </td>
                            <td class="flex justify-end gap-1">
                                <button v-if="tagsStore.isLoading == tag.id"
                                    class="btn btn-outline btn-error btn-square btn-sm" disabled>
                                    <span class="loading loading-spinner text-error"></span>
                                </button>
                                <button v-else type="button" class="btn btn-outline btn-secondary btn-square btn-sm"
                                    aria-label="Edit"
                                    @click="tagsStore.tagForEdit = tag; showModal('tags_edit_modal')"
                                    :disabled="tagsStore.isLoading">
                                    <Pencil :size="20" />
                                </button>

                                <DeleteHold :id="tag.id" :disabled="tagsStore.isLoading"
                                    :isLoading="tagsStore.isLoading === tag.id"
                                    @delete="(id) => tagsStore.delete(id)" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button>close</button>
        </form>
    </dialog>
</template>
<script setup>
import { Pencil, Plus, Tag } from 'lucide-vue-next'
import DeleteHold from './../components/buttons/DeleteHold.vue'
import EmptyState from './../components/EmptyState.vue'
import { showModal } from './../services/modal.js'
import { useTagsStore } from './../services/tags.js'

const tagsStore = useTagsStore()
</script>

<style scoped>
.tags-list {
    max-height: 65vh;
}
</style>
