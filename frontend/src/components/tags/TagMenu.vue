<template>
    <PopoverMenu v-model="open" label="Tag actions" panel-class="w-52 !p-1" root-class="inline-flex shrink-0">
        <template #trigger="{ toggle }">
            <button type="button" class="btn btn-ghost btn-square btn-sm" :aria-label="`Actions for ${name}`"
                aria-haspopup="menu" :aria-expanded="open" :disabled="Boolean(tagsStore.isLoading)" @click="toggle">
                <span v-if="tagsStore.isLoading === tag.id" class="loading loading-spinner loading-xs"></span>
                <Ellipsis v-else :size="20" />
            </button>
        </template>

        <ul class="menu w-full p-0" role="menu">
            <li role="none">
                <button type="button" role="menuitem" @click="run(() => $emit('edit'))">
                    <Pencil :size="16" />
                    Edit
                </button>
            </li>
            <li role="none">
                <button type="button" role="menuitem" @click="run(() => $emit('add-child'))">
                    <ListPlus :size="16" />
                    Add child tag
                </button>
            </li>
            <li role="none">
                <button type="button" role="menuitem" class="text-error" @click="run(() => $emit('archive'))">
                    <Archive :size="16" />
                    Archive
                </button>
            </li>
        </ul>
    </PopoverMenu>
</template>

<script setup>
import { Archive, Ellipsis, ListPlus, Pencil } from '@lucide/vue'
import { computed, ref } from 'vue'
import { useTagsStore } from '../../services/tags.js'
import PopoverMenu from '../PopoverMenu.vue'

const props = defineProps({
    tag: { type: Object, required: true },
})

defineEmits(['edit', 'add-child', 'archive'])

const tagsStore = useTagsStore()
const open = ref(false)
const name = computed(() => [props.tag.emoji, props.tag.title].filter(Boolean).join(' '))

const run = (action) => {
    open.value = false
    action()
}
</script>
