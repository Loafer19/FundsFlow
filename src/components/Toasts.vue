<template>
    <div class="toast toast-top toast-end">
        <div v-for="(toast, index) in toasts" :key="toast.id" class="alert flex items-center gap-2" :class="{
            'alert-success': !toast.type,
            'alert-error': toast.type === 'error',
        }">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 shrink-0 stroke-current" fill="none"
                viewBox="0 0 24 24">
                <path v-if="!toast.type" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>

            <span>{{ toast.message }}</span>
        </div>
    </div>
</template>

<script setup>
import { inject, watch } from 'vue'

const toasts = inject('toasts')

const removeToast = (id) => {
    const index = toasts.findIndex((t) => t.id === id)
    if (index !== -1) toasts.splice(index, 1)
}

watch(
    () => toasts.length,
    () => {
        toasts.forEach((toast) => {
            if (!toast.timeoutId) {
                toast.timeoutId = setTimeout(() => removeToast(toast.id), 2500)
            }
        })
    },
)
</script>

<style scoped></style>
