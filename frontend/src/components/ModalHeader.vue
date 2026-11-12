<template>
    <div class="flex items-start justify-between gap-3" :class="dense ? 'mb-1' : 'mb-4'">
        <div class="flex items-center gap-3 min-w-0 flex-1">
            <slot name="leading" />
            <h2 :id="titleId" class="card-title min-w-0" :class="titleClass">
                <component :is="icon" v-if="icon" :size="24" class="shrink-0" />
                <slot>{{ title }}</slot>
            </h2>
        </div>
        <div class="flex items-center gap-1 shrink-0">
            <slot name="actions" />
            <button type="button" class="btn btn-ghost btn-sm btn-square" aria-label="Close" @click="close">
                <X :size="18" />
            </button>
        </div>
    </div>
</template>

<script setup>
import { X } from '@lucide/vue'

const props = defineProps({
    titleId: { type: String, required: true },
    title: { type: String, default: '' },
    // Lucide component, rendered at 24px to the left of the title.
    icon: { type: [Object, Function], default: null },
    titleClass: { type: String, default: '' },
    // Tighter gap under the title (e.g. Support, which has a subtitle next).
    dense: { type: Boolean, default: false },
})

const close = (event) => {
    event.currentTarget.closest('dialog')?.close()
}
</script>
