<template>
    <dialog id="tags_edit_modal" class="modal" aria-labelledby="tags_edit_modal_title">
        <div class="modal-box max-w-sm">
            <h2 id="tags_edit_modal_title" class="card-title mb-4">
                <Tag :size="24" />
                Edit Tag
            </h2>

            <form ref="formRef" @submit.prevent="handleSubmit">
                <div class="flex items-end gap-3 mb-4">
                    <label class="block min-w-0 flex-1">
                        <span class="block text-sm text-base-content/60 mb-1">Title</span>
                        <input type="text" v-model="tag.title" placeholder="e.g. Groceries" aria-label="Title"
                            class="input w-full" maxlength="255" required autofocus />
                    </label>
                    <button type="button" class="btn btn-ghost btn-square text-2xl"
                        :class="pickerOpen ? 'border-primary' : 'border-base-300'"
                        :aria-label="pickerOpen ? 'Close emoji picker' : 'Choose emoji'" :aria-expanded="pickerOpen"
                        @click="pickerOpen = !pickerOpen">
                        {{ tag.emoji || randomEmoji }}
                    </button>
                </div>

                <LazyEmojiPicker v-if="pickerOpen" class="mb-4" @select="setEmoji" />

                <label class="block mb-4">
                    <span class="block text-sm text-base-content/60 mb-1">Parent</span>
                    <select v-model="tag.parent_id" class="select w-full" aria-label="Parent tag">
                        <option value="">No Parent</option>
                        <option v-for="parent in parentOptions" :key="parent.id" :value="parent.id">
                            {{ parent.title }}
                        </option>
                    </select>
                </label>

                <label class="flex items-center justify-between gap-3 cursor-pointer mb-4">
                    <span class="text-sm">Show in Balances</span>
                    <input v-model="tag.calc_balance" type="checkbox" class="toggle toggle-primary toggle-sm" />
                </label>

                <div class="modal-action">
                    <button type="submit" class="btn btn-success tg-primary" :disabled="tagsStore.isLoading">
                        <span v-if="tagsStore.isLoading" class="loading loading-spinner"></span>
                        Update
                        <Save :size="24" />
                    </button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button>close</button>
        </form>
    </dialog>
</template>

<script setup>
import { Save, Tag } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'
import LazyEmojiPicker from '../components/LazyEmojiPicker.js'
import { useDialogOpen } from '../services/dialogOpen.js'
import { randomEmoji as pickRandomEmoji } from '../services/emojiDefaults.js'
import { useTagsStore } from './../services/tags.js'
import { useTelegramPrimary } from '../services/telegramChrome.js'

const randomEmoji = pickRandomEmoji()

const { isOpen } = useDialogOpen('tags_edit_modal')

// The emoji picker opens from the button next to Title; it is mounted only while open.
const pickerOpen = ref(false)

watch(isOpen, (open) => {
    if (!open) pickerOpen.value = false
})

const tagsStore = useTagsStore()

const formRef = ref(null)

useTelegramPrimary('tags_edit_modal', {
    text: 'Update',
    form: formRef,
    busy: () => Boolean(tagsStore.isLoading),
})

const tag = ref({})

const setEmoji = (emoji) => {
    tag.value.emoji = emoji.native
    pickerOpen.value = false
}

watch(
    () => tagsStore.tagForEdit,
    (for_edit) => {
        if (!for_edit) return

        tag.value = {
            id: for_edit.id,
            title: for_edit.title,
            parent_id: for_edit.parent_id ?? '',
            emoji: for_edit.emoji,
            calc_balance: for_edit.calc_balance,
        }
    },
)

const parentOptions = computed(() => {
    const currentId = tag.value.id
    if (!currentId) return []

    const excluded = new Set([currentId])
    const collectDescendants = (parentId) => {
        tagsStore.tags.forEach((t) => {
            if (t.parent_id === parentId && !excluded.has(t.id)) {
                excluded.add(t.id)
                collectDescendants(t.id)
            }
        })
    }
    collectDescendants(currentId)

    return tagsStore
        .list()
        .filter((t) => !excluded.has(t.id))
        .map((t) => ({
            id: t.id,
            title: `${'\u00A0'.repeat(t.depth * 2)}${t.depth > 0 ? '↳ ' : ''}${t.title}`,
        }))
})

const handleSubmit = async () => {
    const ok = await tagsStore.update(tag.value)

    if (!ok) return

    tags_edit_modal.close()
}
</script>

<style scoped></style>
