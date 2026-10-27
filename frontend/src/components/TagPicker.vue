<template>
    <div v-if="tagsStore.tags.length" class="mb-4">
        <label v-if="searchable" class="input input-sm w-full border-base-300 mb-2">
            <Search :size="14" class="text-base-content/60" />
            <input v-model="query" type="search" placeholder="Search tags" aria-label="Search tags" />
        </label>

        <div class="tags-tree flex flex-col justify-start gap-1">
            <div v-for="tag in visibleTags" :key="tag.id" class="flex items-center gap-0.5"
                :style="{ paddingLeft: `${tag.depth * 20}px` }">
                <button v-if="tag.hasChildren" type="button" class="btn btn-ghost btn-xs btn-square shrink-0"
                    :aria-expanded="isExpanded(tag)" :aria-label="`${isExpanded(tag) ? 'Collapse' : 'Expand'} ${tag.title}`"
                    @click="toggleExpanded(tag)">
                    <ChevronRight :size="14" class="transition-transform" :class="{ 'rotate-90': isExpanded(tag) }" />
                </button>
                <span v-else-if="hasTree" class="w-6 shrink-0" aria-hidden="true"></span>

                <button type="button" @click="toggle(tag.id)" class="badge badge-info gap-0 px-1 text-lg cursor-pointer"
                    :class="{ 'badge-soft': !modelValue.includes(tag.id) }"
                    :aria-label="[tag.emoji, tag.title].filter(Boolean).join(' ')"
                    :aria-pressed="modelValue.includes(tag.id)">
                    <span>{{ tag.emoji }}</span>
                    <span>{{ tag.title }}</span>
                </button>

                <span v-if="tag.hasChildren && !isExpanded(tag)" class="text-xs text-base-content/40 ml-1">
                    {{ tag.childCount }}
                </span>
            </div>

            <div v-if="!visibleTags.length" class="text-sm text-base-content/60">No tags match</div>
        </div>
    </div>
    <div v-else class="text-sm text-base-content/60 mb-4">No tags yet — create one first</div>
</template>

<script setup>
import { ChevronRight, Search } from 'lucide-vue-next'
import { computed, ref } from 'vue'
import { useTagsStore } from '../services/tags.js'
import { haptic } from '../services/telegramWebApp.js'

const tagsStore = useTagsStore()

const modelValue = defineModel({ type: Array, default: () => [] })

// Child tags sit collapsed under their parent. A parent opens on its chevron, and on its own when one of
// its descendants is selected or while searching; a chevron click overrides that automatic state.
const SEARCH_FROM = 9
const query = ref('')
const manual = ref({})

const searchable = computed(() => tagsStore.tags.length >= SEARCH_FROM)
const searching = computed(() => query.value.trim() !== '')

const tree = computed(() => {
    const list = tagsStore.list()
    const children = {}
    for (const tag of list) {
        if (tag.parent_id != null) (children[tag.parent_id] ??= []).push(tag.id)
    }

    const descendants = (id) => (children[id] ?? []).flatMap((childId) => [childId, ...descendants(childId)])

    return list.map((tag) => ({
        ...tag,
        hasChildren: Boolean(children[tag.id]),
        childCount: children[tag.id]?.length ?? 0,
        descendantIds: descendants(tag.id),
    }))
})

const hasTree = computed(() => tree.value.some((tag) => tag.hasChildren))

const isExpanded = (tag) => {
    if (searching.value) return true
    if (tag.id in manual.value) return manual.value[tag.id]
    return tag.descendantIds.some((id) => modelValue.value.includes(id))
}

const toggleExpanded = (tag) => {
    haptic.select()
    manual.value = { ...manual.value, [tag.id]: !isExpanded(tag) }
}

const visibleTags = computed(() => {
    const byId = Object.fromEntries(tree.value.map((tag) => [tag.id, tag]))

    if (searching.value) {
        const q = query.value.trim().toLowerCase()
        const matches = (tag) => tag.title.toLowerCase().includes(q)
        // A match keeps its ancestors on screen for context.
        return tree.value.filter((tag) => matches(tag) || tag.descendantIds.some((id) => matches(byId[id])))
    }

    const open = (tag) => {
        const parent = byId[tag.parent_id]
        return !parent || (isExpanded(parent) && open(parent))
    }

    return tree.value.filter(open)
})

const toggle = (id) => {
    haptic.select()

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
