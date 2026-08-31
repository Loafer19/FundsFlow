<template>
    <!--
      DaisyUI: toast cannot sit above <dialog showModal()> via z-index (top layer).
      Official workaround: render toast inside the open dialog, outside modal-box.
    -->
    <Teleport :disabled="!openDialog" :to="openDialog || 'body'">
        <div class="toast toast-top toast-center">
            <div v-for="toast in toasts" :key="toast.id" class="alert flex items-center gap-2" :class="{
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
    </Teleport>
</template>

<script setup>
import { CircleCheck, CircleX, Info } from 'lucide-vue-next'
import { onBeforeUnmount, onMounted, shallowRef, watch } from 'vue'
import toasts from '../services/toasts'

const openDialog = shallowRef(null)
let observer

const syncOpenDialog = () => {
    const open = [...document.querySelectorAll('dialog.modal[open]')]
    openDialog.value = open.at(-1) ?? null
}

const removeToast = (id) => {
    const index = toasts.findIndex((t) => t.id === id)
    if (index !== -1) toasts.splice(index, 1)
}

onMounted(() => {
    syncOpenDialog()
    observer = new MutationObserver(syncOpenDialog)
    observer.observe(document.body, {
        subtree: true,
        attributes: true,
        attributeFilter: ['open'],
        childList: true,
    })
})

onBeforeUnmount(() => observer?.disconnect())

watch(
    () => toasts.length,
    () => {
        syncOpenDialog()

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
