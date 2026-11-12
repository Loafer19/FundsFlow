<template>
    <div class="card card-border border-base-300 bg-base-100">
        <div class="card-body items-center text-center gap-3" :class="size === 'sm' ? 'py-6 gap-2' : 'py-12'">
            <span v-if="icon" class="rounded-lg p-1.5" :class="[iconClass, size === 'sm' ? '' : '']">
                <component :is="icon" :size="size === 'sm' ? 20 : 28" />
            </span>
            <h3 :class="size === 'sm' ? 'text-sm font-semibold' : 'text-lg font-semibold'">{{ title }}</h3>
            <p v-if="description" class="text-base-content/60 max-w-sm" :class="size === 'sm' ? 'text-xs' : ''">
                {{ description }}
            </p>
            <button v-if="actionLabel" type="button" class="btn btn-primary btn-sm mt-2" @click="$emit('action')">
                <component :is="actionIcon || Plus" :size="20" />
                {{ actionLabel }}
            </button>
        </div>
    </div>
</template>

<script setup>
import { Plus } from '@lucide/vue'

defineProps({
    // Lucide component. Rendered in the same tile language as StatCard.
    icon: { type: [Object, Function], default: null },
    iconClass: { type: String, default: 'bg-base-content/10 text-base-content/70' },
    title: { type: String, required: true },
    description: { type: String, default: '' },
    actionLabel: { type: String, default: '' },
    // Lucide component for the action button; defaults to Plus.
    actionIcon: { type: [Object, Function], default: null },
    size: { type: String, default: 'md' },
})

defineEmits(['action'])
</script>
