<template>
    <Picker :data="emojiIndex" :native="true" :show-preview="false" :show-categories="false" :emoji-tooltip="true"
        @select="(emoji) => emit('select', emoji)" />
</template>

<script>
// Loaded on demand (see LazyEmojiPicker): the emoji dataset is the largest dependency of the app.
import data from 'emoji-mart-vue-fast/data/apple.json'
import 'emoji-mart-vue-fast/css/emoji-mart.css'
import { EmojiIndex } from 'emoji-mart-vue-fast/src'

// One index shared by every picker instance (Add Tag + Edit Tag).
const emojiIndex = new EmojiIndex(data, {
    exclude: ['recent', 'flags'],
})
</script>

<script setup>
import { Picker } from 'emoji-mart-vue-fast/src'

const emit = defineEmits(['select'])
</script>
