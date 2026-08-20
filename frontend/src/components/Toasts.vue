<template>
    <div class="toast toast-top toast-center">
        <div v-for="(toast) in toasts" :key="toast.id" class="alert flex items-center gap-2" :class="{
            'alert-success': toast.type === 'success',
            'alert-error': toast.type === 'error',
            'alert-info': toast.type === 'info' || !toast.type,
        }">
            <CircleCheck v-if="toast.type === 'success'" :size="24" />
            <CircleX v-else-if="toast.type === 'error'" :size="24" />
            <Info v-else :size="24" />

            <span>{{ toast.message }}</span>
        </div>
    </div>
</template>

<script setup>
import { CircleCheck, CircleX, Info } from 'lucide-vue-next'
import { watch } from 'vue'
import toasts from '../services/toasts'

const removeToast = (id) => {
    const index = toasts.findIndex((t) => t.id === id)
    if (index !== -1) toasts.splice(index, 1)
}

watch(
    () => toasts.length,
    () => {
        toasts.forEach((toast) => {
            if (!toast.timeoutId) {
                toast.timeoutId = setTimeout(() => removeToast(toast.id), toast.type === 'error' ? 4000 : 2500)
            }
        })
    },
)
</script>

<style scoped>
.toast {
    z-index: 1000;
}
</style>
