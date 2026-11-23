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
            <button v-if="buttonLabel" type="button" class="btn btn-primary btn-sm mt-2" @click="onAction">
                <component :is="buttonIcon" :size="20" />
                {{ buttonLabel }}
            </button>
        </div>
    </div>
</template>

<script setup>
import { LogIn, Plus } from '@lucide/vue'
import { computed } from 'vue'
import { useAuthStore } from '../services/auth.js'
import { showModal } from '../services/modal.js'

const props = defineProps({
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

const emit = defineEmits(['action'])
const authStore = useAuthStore()

const loggedOutAdd = computed(() => props.actionLabel === 'Add Transaction' && !authStore.isAuthenticated)
const buttonLabel = computed(() => {
    if (!props.actionLabel) return ''
    return loggedOutAdd.value ? 'Log in' : props.actionLabel
})
const buttonIcon = computed(() => (loggedOutAdd.value ? LogIn : props.actionIcon || Plus))

const onAction = () => {
    if (loggedOutAdd.value) {
        showModal('auth_modal')
        return
    }
    emit('action')
}
</script>
