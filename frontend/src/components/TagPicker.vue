<template>
    <div class="tags-tree flex flex-col justify-start gap-1 mb-4" v-if="tagsStore.tags.length">
        <button v-for="tag in tagsStore.list()" :key="tag.id" type="button" @click="toggle(tag.id)"
            class="badge badge-info gap-0 px-1 text-lg cursor-pointer" :class="{
                'badge-soft': !modelValue.includes(tag.id),
            }" :style="{ marginLeft: `${tag.depth * 20}px` }">
            <span>{{ tag.emoji }}</span>
            <span>{{ tag.title }}</span>
        </button>
    </div>
</template>

<script setup>
import { useTagsStore } from '../services/tags.js'

const tagsStore = useTagsStore()

const modelValue = defineModel({ type: Array, default: () => [] })

const toggle = (id) => {
    const index = modelValue.value.indexOf(id)

    if (index === -1) {
        modelValue.value.push(id)
    } else {
        modelValue.value.splice(index, 1)
    }
}
</script>

<style scoped>
.tags-tree {
    max-height: 45vh;
    overflow-y: auto;
}

.badge {
    transition: all 0.2s ease;
}

.badge:hover {
    opacity: 0.8;
}
</style>
