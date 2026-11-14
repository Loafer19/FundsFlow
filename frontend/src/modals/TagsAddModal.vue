<template>
    <dialog id="tags_add_modal" class="modal" aria-labelledby="tags_add_modal_title">
        <div class="modal-box max-w-sm">
            <ModalHeader title-id="tags_add_modal_title" :icon="Tag">
                Add Tag
            </ModalHeader>

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

                <div class="mb-4">
                    <div class="text-sm text-base-content/60 mb-1">Parent</div>
                    <PopoverMenu label="Parent tag" root-class="block">
                        <template #trigger="{ toggle, open }">
                            <button type="button" class="badge badge-soft px-2 gap-1 text-base h-8 font-normal"
                                :class="parentTag ? 'badge-info' : 'badge-neutral'"
                                :aria-expanded="open" aria-haspopup="dialog" aria-label="Parent tag" @click="toggle">
                                <template v-if="parentTag">
                                    <span>{{ parentTag.emoji }}</span>
                                    <span>{{ parentTag.title }}</span>
                                </template>
                                <span v-else>No parent</span>
                            </button>
                        </template>
                        <template #default="{ close }">
                            <button type="button" class="badge gap-0 px-1 text-lg cursor-pointer mb-2 w-fit hover:opacity-80"
                                :class="tag.parent_id ? 'badge-soft badge-neutral' : 'badge-neutral'"
                                @click="tag.parent_id = ''; close()">
                                No parent
                            </button>
                            <TagPicker v-model="parentIds" single @update:model-value="close" />
                        </template>
                    </PopoverMenu>
                </div>

                <label class="flex items-center justify-between gap-3 cursor-pointer mb-4 rounded-field px-1 -mx-1 hover:bg-base-200">
                    <span class="text-sm">Show in Balances</span>
                    <input v-model="tag.calc_balance" type="checkbox" class="toggle toggle-primary toggle-sm" />
                </label>

                <div class="modal-action">
                    <button type="submit" class="btn btn-primary tg-primary" :disabled="tagsStore.isLoading">
                        <span v-if="tagsStore.isLoading" class="loading loading-spinner"></span>
                        <Save v-else :size="20" />
                        Save
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
import { Save, Tag } from '@lucide/vue'
import { computed, ref, watch } from 'vue'
import LazyEmojiPicker from '../components/LazyEmojiPicker.js'
import ModalHeader from '../components/ModalHeader.vue'
import PopoverMenu from '../components/PopoverMenu.vue'
import TagPicker from '../components/TagPicker.vue'
import { useDialogOpen } from '../services/dialogOpen.js'
import { randomEmoji as pickRandomEmoji } from '../services/emojiDefaults.js'
import { useTagsStore } from './../services/tags.js'
import { useTelegramPrimary } from '../services/telegramChrome.js'

const randomEmoji = pickRandomEmoji()

const { isOpen } = useDialogOpen('tags_add_modal')

// The emoji picker opens from the button next to Title; it is mounted only while open.
const pickerOpen = ref(false)

watch(isOpen, (open) => {
    if (!open) pickerOpen.value = false
})

const tagsStore = useTagsStore()

const formRef = ref(null)

useTelegramPrimary('tags_add_modal', {
    text: 'Save',
    form: formRef,
    busy: () => Boolean(tagsStore.isLoading),
})

const tag_default = {
    title: '',
    parent_id: '',
    emoji: randomEmoji,
    calc_balance: false,
}

const tag = ref({ ...tag_default })

const setEmoji = (emoji) => {
    tag.value.emoji = emoji.native
    pickerOpen.value = false
}

const parentIds = computed({
    get: () => (tag.value.parent_id ? [tag.value.parent_id] : []),
    set: (ids) => {
        tag.value.parent_id = ids[0] ?? ''
    },
})
const parentTag = computed(() => tagsStore.tags.find((item) => item.id === tag.value.parent_id) ?? null)

const handleSubmit = async () => {
    const ok = await tagsStore.create(tag.value)

    if (!ok) return

    tag.value = { ...tag_default }

    tags_add_modal.close()
}
</script>

<style scoped></style>
