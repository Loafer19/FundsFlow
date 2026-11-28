<template>
    <PopoverMenu v-model="open" label="Budget actions" panel-class="w-52 !p-1" root-class="inline-flex shrink-0 report-hide">
        <template #trigger="{ toggle }">
            <button type="button" class="btn btn-ghost btn-square btn-sm tooltip tooltip-left" data-tip="Actions"
                :aria-label="`Actions for ${name}`" aria-haspopup="menu" :aria-expanded="open"
                :disabled="Boolean(budgetsStore.isLoading)" @click="toggle">
                <span v-if="busy" class="loading loading-spinner loading-xs"></span>
                <Ellipsis v-else :size="20" />
            </button>
        </template>

        <ul class="menu w-full p-0" role="menu">
            <li v-if="canView" role="none">
                <button type="button" role="menuitem" @click="run(() => $emit('view-tags'))">
                    <ListFilter :size="16" />
                    View transactions
                </button>
            </li>
            <li role="none">
                <button type="button" role="menuitem" @click="run(() => $emit('edit'))">
                    <Pencil :size="16" />
                    Edit
                </button>
            </li>
            <li role="none">
                <button v-if="active" type="button" role="menuitem" @click="run(() => budgetsStore.pause(budget.id))">
                    <Pause :size="16" />
                    Pause
                </button>
                <button v-else type="button" role="menuitem" @click="run(() => budgetsStore.resume(budget.id))">
                    <Play :size="16" />
                    Resume
                </button>
            </li>
            <li role="none">
                <button type="button" role="menuitem" class="text-error" @click="run(remove)">
                    <Trash2 :size="16" />
                    Delete
                </button>
            </li>
        </ul>
    </PopoverMenu>
</template>

<script setup>
import { Ellipsis, ListFilter, Pause, Pencil, Play, Trash2 } from '@lucide/vue'
import { computed, ref } from 'vue'
import { useBudgetsStore } from '../../services/budgets.js'
import { askConfirm } from '../../services/confirmDialog.js'
import PopoverMenu from '../PopoverMenu.vue'

const props = defineProps({
    budget: { type: Object, required: true },
    // Display name used in the confirmation and the button label.
    name: { type: String, required: true },
    // 'goal' | 'limit': wording only.
    kind: { type: String, default: 'limit' },
    // The budget has an open period (not paused).
    active: { type: Boolean, default: true },
    // Offer "View transactions" (the budget counts tagged transactions).
    canView: { type: Boolean, default: false },
})

defineEmits(['edit', 'view-tags'])

const budgetsStore = useBudgetsStore()
const open = ref(false)
const busy = computed(() => budgetsStore.isLoading === props.budget.id)

const run = (action) => {
    open.value = false
    action()
}

const remove = async () => {
    const noun = props.kind === 'goal' ? 'goal' : 'budget'
    const ok = await askConfirm({
        title: `Delete ${noun}?`,
        message: `“${props.name}” will be deleted. This can't be undone.`,
        actionLabel: 'Delete',
        danger: true,
    })

    if (ok) budgetsStore.delete(props.budget.id)
}
</script>
