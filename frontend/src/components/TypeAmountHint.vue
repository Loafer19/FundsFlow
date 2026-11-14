<template>
    <p v-if="hint" class="text-sm text-base-content/60 -mt-1 flex items-center gap-1">
        <span>{{ hint.text }}</span>
        <button v-if="!hint.linked" type="button" class="btn btn-ghost btn-xs btn-square" title="Use the computed total"
            aria-label="Use the computed total" @click="$emit('reset')">
            <RotateCcw :size="14" />
        </button>
    </p>
</template>

<script setup>
import { RotateCcw } from '@lucide/vue'

defineProps({
    hint: { type: Object, default: null },
})

defineEmits(['reset'])
</script>
