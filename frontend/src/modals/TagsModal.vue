<template>
    <dialog id="tags_modal" class="modal" aria-labelledby="tags_modal_title">
        <div class="modal-box max-w-lg">
            <ModalHeader title-id="tags_modal_title" :icon="Tag">
                Manage Tags
                <span v-if="active.length" class="font-normal text-base-content/50">· {{ active.length }}</span>
                <template #actions>
                    <button type="button" @click="tagsStore.openForm()" class="btn btn-primary btn-sm">
                        <Plus :size="20" />
                        Add Tag
                    </button>
                </template>
            </ModalHeader>

            <EmptyState v-if="!active.length && !archived.length" :icon="Tag" title="No tags yet"
                description="Create a few to organize transactions" action-label="Add Tag"
                @action="tagsStore.openForm()">
                <div v-if="starterSets.length" class="flex flex-col gap-1">
                    <span class="text-sm text-base-content/80">Or start from a set</span>
                    <div class="flex flex-wrap justify-center gap-1.5">
                        <button v-for="set in starterSets" :key="set.title" type="button"
                            class="btn btn-sm btn-outline border-base-300" :disabled="Boolean(tagsStore.isLoading)"
                            @click="tagsStore.createSet(set)">
                            <span>{{ set.emoji }}</span>
                            {{ set.title }}
                            <span class="text-base-content/50 font-normal">{{ set.children.length + 1 }}</span>
                        </button>
                    </div>
                </div>
            </EmptyState>

            <div v-else>
                <label v-if="searchable" class="input input-sm w-full border-base-300 mb-2">
                    <Search :size="14" class="text-base-content/60" />
                    <input v-model="query" type="search" placeholder="Search tags" aria-label="Search tags" />
                </label>

                <div class="tags-list">
                    <ul v-if="visible.length" class="flex flex-col" role="tree" aria-label="Tags">
                        <li v-for="tag in visible" :key="tag.id" role="treeitem" :aria-level="tag.depth + 1"
                            :aria-expanded="tag.hasChildren ? isOpen(tag) : undefined"
                            class="flex items-center gap-1 border-b border-base-200 py-1 last:border-b-0"
                            :style="{ paddingLeft: `${tag.depth * 20}px` }">
                            <button v-if="tag.hasChildren" type="button" class="btn btn-ghost btn-xs btn-square shrink-0"
                                :aria-label="`${isOpen(tag) ? 'Collapse' : 'Expand'} ${tag.title}`" @click="toggle(tag)">
                                <ChevronRight :size="14" class="transition-transform duration-200"
                                    :class="{ 'rotate-90': isOpen(tag) }" />
                            </button>
                            <span v-else-if="hasTree" class="w-6 shrink-0" aria-hidden="true"></span>

                            <button type="button"
                                class="flex min-w-0 flex-1 items-center gap-2 rounded-field px-1 py-1 text-left transition-colors duration-200 hover:bg-base-200"
                                :aria-label="`Edit ${tag.title}`" @click="tagsStore.openForm(tag)">
                                <span class="badge badge-soft badge-info text-xl py-4 px-2 shrink-0">{{ tag.emoji }}</span>
                                <span class="min-w-0 truncate">
                                    <template v-for="(part, index) in parts(tag.title)" :key="index">
                                        <mark v-if="part.hit" class="rounded-sm bg-warning/40 text-inherit">{{ part.text }}</mark>
                                        <template v-else>{{ part.text }}</template>
                                    </template>
                                </span>
                                <span v-if="tag.hasChildren && !isOpen(tag)" class="text-xs text-base-content/40 shrink-0">
                                    {{ tag.childCount }}
                                </span>
                            </button>

                            <span v-if="tag.transactions_count !== undefined"
                                class="tooltip tooltip-left shrink-0 text-xs tabular-nums"
                                :class="tag.transactions_count ? 'text-base-content/60' : 'text-base-content/30'"
                                :data-tip="`${tag.transactions_count} transaction${tag.transactions_count === 1 ? '' : 's'}`">
                                {{ tag.transactions_count }}
                            </span>

                            <TagMenu :tag="tag" @edit="tagsStore.openForm(tag)" @add-child="tagsStore.openForm(null, tag.id)"
                                @archive="archive(tag)" />
                        </li>
                    </ul>
                    <p v-else-if="active.length" class="py-6 text-center text-sm text-base-content/60">
                        No tags match “{{ query.trim() }}”
                    </p>

                    <details v-if="archivedShown.length" class="mt-2 px-1" :open="!active.length || searching">
                        <summary class="text-xs text-base-content/60 cursor-pointer py-1">
                            Archived ({{ archivedShown.length }})
                        </summary>
                        <ul class="flex flex-col gap-1 mt-1">
                            <li v-for="tag in archivedShown" :key="tag.id" class="flex items-center gap-2 text-sm py-1">
                                <span class="badge badge-soft badge-neutral text-lg py-3 px-2">{{ tag.emoji }}</span>
                                <span class="flex-1 truncate text-base-content/70">{{ tag.title }}</span>
                                <button type="button" class="btn btn-ghost btn-xs" :disabled="Boolean(tagsStore.isLoading)"
                                    @click="tagsStore.restore(tag.id)">Restore</button>
                            </li>
                        </ul>
                    </details>
                </div>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button>close</button>
        </form>
    </dialog>
</template>

<script setup>
import { ChevronRight, Plus, Search, Tag } from '@lucide/vue'
import { computed, ref, watch } from 'vue'
import EmptyState from '../components/EmptyState.vue'
import ModalHeader from '../components/ModalHeader.vue'
import TagMenu from '../components/tags/TagMenu.vue'
import { askConfirm } from '../services/confirmDialog.js'
import { useDialogOpen } from '../services/dialogOpen.js'
import { TAG_STARTER_SETS } from '../services/tagStarterSets.js'
import { useTagsStore } from '../services/tags.js'

const tagsStore = useTagsStore()
const { isOpen: dialogOpen } = useDialogOpen('tags_modal')

const active = computed(() => tagsStore.list())
const archived = computed(() => tagsStore.archived)

// Counts are read once per open, so they are fresh without polling.
watch(dialogOpen, (open) => {
    if (open) tagsStore.refreshCounts()
})

// Starter sets: not offered when a tag with that title already exists.
const starterSets = computed(() =>
    TAG_STARTER_SETS.filter(
        (set) => !tagsStore.tags.some((tag) => tag.title.toLowerCase() === set.title.toLowerCase()),
    ),
)

// --- Search and the collapsible tree ---------------------------------------------------------
const SEARCH_FROM = 8
const query = ref('')
const searching = computed(() => query.value.trim() !== '')
const searchable = computed(() => active.value.length + archived.value.length >= SEARCH_FROM)
const needle = computed(() => query.value.trim().toLowerCase())

// Open parents stay open for the session (also across a reload).
const STORAGE_KEY = 'tags:expanded'
const readOpen = () => {
    try {
        return new Set(JSON.parse(sessionStorage.getItem(STORAGE_KEY) ?? '[]'))
    } catch {
        return new Set()
    }
}
const openIds = ref(readOpen())

const toggle = (tag) => {
    const next = new Set(openIds.value)
    if (next.has(tag.id)) next.delete(tag.id)
    else next.add(tag.id)
    openIds.value = next

    try {
        sessionStorage.setItem(STORAGE_KEY, JSON.stringify([...next]))
    } catch {
        // Private mode: the tree just forgets its state on reload.
    }
}

const isOpen = (tag) => searching.value || openIds.value.has(tag.id)

const tree = computed(() => {
    const list = active.value
    const parentOf = new Map(list.map((tag) => [tag.id, tag.parent_id]))
    const children = new Map()
    for (const tag of list)
        if (tag.parent_id != null) children.set(tag.parent_id, [...(children.get(tag.parent_id) ?? []), tag.id])

    const countBelow = (id) => (children.get(id) ?? []).reduce((sum, child) => sum + 1 + countBelow(child), 0)

    return {
        parentOf,
        rows: list.map((tag) => ({ ...tag, hasChildren: children.has(tag.id), childCount: countBelow(tag.id) })),
    }
})

const hasTree = computed(() => tree.value.rows.some((tag) => tag.hasChildren))

const visible = computed(() => {
    const { parentOf, rows } = tree.value

    if (searching.value) {
        // A match keeps its ancestors on screen for context.
        const keep = new Set()
        for (const tag of rows) {
            if (!tag.title.toLowerCase().includes(needle.value)) continue
            for (let id = tag.id; id != null; id = parentOf.get(id)) keep.add(id)
        }

        return rows.filter((tag) => keep.has(tag.id))
    }

    const shown = (tag) => {
        const parent = tag.parent_id
        return parent == null || (openIds.value.has(parent) && shown({ parent_id: parentOf.get(parent) }))
    }

    return rows.filter(shown)
})

const archivedShown = computed(() =>
    searching.value ? archived.value.filter((tag) => tag.title.toLowerCase().includes(needle.value)) : archived.value,
)

// Title split around the search text, for the highlight.
const parts = (title) => {
    if (!searching.value) return [{ text: title, hit: false }]

    const at = title.toLowerCase().indexOf(needle.value)
    if (at === -1) return [{ text: title, hit: false }]

    return [
        { text: title.slice(0, at), hit: false },
        { text: title.slice(at, at + needle.value.length), hit: true },
        { text: title.slice(at + needle.value.length), hit: false },
    ].filter((part) => part.text)
}

// --- Archive ---------------------------------------------------------------------------------
const archive = async (tag) => {
    const ids = tagsStore.descendantIds(tag.id)
    const below = tagsStore.active.filter((item) => item.id !== tag.id && ids.has(item.id)).length

    const ok = await askConfirm({
        title: 'Archive tag?',
        message:
            `“${tag.title}”${below ? ` and ${below} sub-tag${below === 1 ? '' : 's'}` : ''} will be archived. ` +
            `Transactions keep their tags.${below ? " Restoring a parent doesn't restore its sub-tags." : ''}`,
        actionLabel: 'Archive',
        danger: true,
    })

    if (ok) tagsStore.archive(tag.id)
}
</script>

<style scoped>
.tags-list {
    max-height: 60vh;
    overflow-y: auto;
}
</style>
