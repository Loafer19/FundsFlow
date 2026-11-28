<template>
    <div class="relative mb-4">
        <div class="flex items-end gap-3">
            <label class="block min-w-0 flex-1">
                <span class="block text-sm text-base-content/60 mb-1">Title</span>
                <input type="text" v-model="form.title" placeholder="e.g. Groceries" aria-label="Title"
                    class="input w-full" maxlength="255" required autofocus />
            </label>
            <button type="button" class="btn btn-ghost btn-square text-2xl"
                :class="pickerOpen ? 'border-primary' : 'border-base-300'"
                :aria-label="pickerOpen ? 'Close emoji picker' : 'Choose emoji'" :aria-expanded="pickerOpen"
                @click="pickerOpen = !pickerOpen">
                {{ form.emoji || '🏷️' }}
            </button>
        </div>

        <!-- Floats over the fields below, so opening it does not push the form around. -->
        <div v-if="pickerOpen" class="absolute inset-x-0 top-full z-20 mt-1 overflow-hidden rounded-box border border-base-300 shadow-lg bg-base-100">
            <LazyEmojiPicker class="!mb-0" @select="setEmoji" />
        </div>
    </div>

    <div class="mb-4">
        <div class="text-sm text-base-content/60 mb-1">Parent</div>
        <details ref="parentDetails" class="rounded-box border border-base-300">
            <summary class="flex cursor-pointer items-center gap-2 px-3 py-2 text-sm">
                <template v-if="parentTag">
                    <span>{{ parentTag.emoji }}</span>
                    <span class="min-w-0 flex-1 truncate">{{ parentTag.title }}</span>
                </template>
                <span v-else class="flex-1 text-base-content/70">No parent</span>
            </summary>
            <div class="px-3 pt-1">
                <button v-if="parentTag" type="button" class="btn btn-ghost btn-xs mb-2" @click="pickParent([])">
                    <X :size="14" />
                    Remove parent
                </button>
                <TagPicker v-model="parentIds" single :exclude-ids="excludeIds" @update:model-value="pickParent" />
            </div>
        </details>
    </div>

    <label class="flex items-center justify-between gap-3 cursor-pointer mb-1 rounded-field px-1 -mx-1 hover:bg-base-200">
        <span class="flex items-center gap-1 text-sm">
            Show in Balances
            <span class="tooltip tooltip-top before:max-w-56 before:text-left" :data-tip="balancesHint" tabindex="0"
                role="img" :aria-label="balancesHint">
                <Info :size="14" class="text-base-content/60" />
            </span>
        </span>
        <input v-model="form.calc_balance" type="checkbox" class="toggle toggle-primary toggle-sm" />
    </label>
    <p class="text-xs text-base-content/60 mb-4">Adds a balance row for this tag (with its sub-tags) in Analytics.</p>
</template>

<script setup>
import { Info, X } from '@lucide/vue'
import { computed, ref } from 'vue'
import { useTagsStore } from '../../services/tags.js'
import LazyEmojiPicker from '../LazyEmojiPicker.js'
import TagPicker from '../TagPicker.vue'

defineProps({
    // A tag cannot become its own parent (nor sit under one of its children).
    excludeIds: { type: Array, default: () => [] },
})

const form = defineModel({ type: Object, required: true })
const tagsStore = useTagsStore()

const balancesHint = 'Shows the net balance of transactions with this tag in the Balances card of Analytics'

// The picker is mounted only while open (it is a separate chunk).
const pickerOpen = ref(false)
const setEmoji = (emoji) => {
    form.value.emoji = emoji.native
    pickerOpen.value = false
}

const parentDetails = ref(null)
const parentIds = computed({
    get: () => (form.value.parent_id ? [form.value.parent_id] : []),
    set: (ids) => {
        form.value.parent_id = ids[0] ?? ''
    },
})
const parentTag = computed(() => tagsStore.tags.find((item) => item.id === form.value.parent_id) ?? null)

const pickParent = (ids) => {
    form.value.parent_id = ids[0] ?? ''
    if (parentDetails.value) parentDetails.value.open = false
}
</script>
