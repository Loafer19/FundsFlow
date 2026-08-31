<template>
    <dialog id="tags_add_modal" class="modal">
        <div class="modal-box max-w-sm">
            <h2 class="card-title mb-4">
                <Tag :size="24" />
                Add Tag
            </h2>

            <form @submit.prevent="handleSubmit">
                <input type="text" v-model="tag.title" placeholder="Title" class="input w-full mb-4" maxlength="255"
                    required autofocus />

                <select v-model="tag.parent_id" class="select w-full mb-4" aria-label="Parent tag">
                    <option value="">No Parent</option>
                    <option v-for="parent in parentOptions" :key="parent.id" :value="parent.id">
                        {{ parent.title }}
                    </option>
                </select>

                <div class="mb-4">
                    <div class="badge badge-soft badge-info text-xl py-4 px-2">
                        Choose an emoji: {{ tag.emoji || randomEmoji }}
                    </div>
                </div>

                <Picker :data="emojiIndex" :native="true" @select="setEmoji" :show-preview="false"
                    :show-categories="false" :emoji-tooltip="true" class="mb-4" />

                <label class="label">
                    <input v-model="tag.calc_balance" type="checkbox" class="checkbox checkbox-info checkbox-sm" />
                    Show in Balances
                </label>

                <div class="modal-action">
                    <button type="submit" class="btn btn-success" :disabled="tagsStore.isLoading">
                        <span v-if="tagsStore.isLoading" class="loading loading-spinner"></span>
                        Save
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
import data from 'emoji-mart-vue-fast/data/apple.json'
import { Save, Tag } from 'lucide-vue-next'
import { computed, ref } from 'vue'
import 'emoji-mart-vue-fast/css/emoji-mart.css'
import { EmojiIndex, Picker } from 'emoji-mart-vue-fast/src'
import { useTagsStore } from './../services/tags.js'

const emojiIndex = new EmojiIndex(data, {
    exclude: ['recent', 'flags'],
})

const randomEmoji = Object.keys(emojiIndex._nativeEmojis)[
    (Math.random() * Object.keys(emojiIndex._nativeEmojis).length) | 0
]

const tagsStore = useTagsStore()

const tag_default = {
    title: '',
    parent_id: '',
    emoji: randomEmoji,
    calc_balance: false,
}

const tag = ref({ ...tag_default })

const setEmoji = (emoji) => {
    tag.value.emoji = emoji.native
}

const parentOptions = computed(() => {
    return tagsStore.list().map((tag) => ({
        id: tag.id,
        title: `${'\u00A0'.repeat(tag.depth * 2)}${tag.depth > 0 ? '↳ ' : ''}${tag.title}`,
    }))
})

const handleSubmit = async () => {
    const ok = await tagsStore.create(tag.value)

    if (!ok) return

    tag.value = { ...tag_default }

    tags_add_modal.close()
}
</script>

<style scoped></style>
