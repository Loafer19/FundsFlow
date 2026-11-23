<template>
    <dialog ref="dialogRef" class="modal" aria-labelledby="confirm_dialog_title" @close="onClose">
        <div class="modal-box max-w-sm">
            <ModalHeader title-id="confirm_dialog_title" :icon="confirmDialog.danger ? TriangleAlert : CircleAlert">
                {{ confirmDialog.title }}
            </ModalHeader>

            <p class="text-sm text-base-content/70 mb-6">{{ confirmDialog.message }}</p>

            <div class="flex justify-end gap-2">
                <button type="button" class="btn btn-ghost" @click="cancel">Cancel</button>
                <button type="button" class="btn" :class="confirmDialog.danger ? 'btn-error' : 'btn-primary'"
                    @click="confirm">
                    {{ confirmDialog.actionLabel }}
                </button>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button type="submit">close</button>
        </form>
    </dialog>
</template>

<script setup>
import { CircleAlert, TriangleAlert } from '@lucide/vue'
import { nextTick, onMounted, ref, watch } from 'vue'
import { confirmDialog, settleConfirm } from '../services/confirmDialog.js'
import ModalHeader from './ModalHeader.vue'

const dialogRef = ref(null)

const syncOpen = async (open) => {
    const dialog = dialogRef.value
    if (!dialog) return

    if (open) {
        await nextTick()
        if (!dialog.open) dialog.showModal()
    } else if (dialog.open) {
        dialog.close()
    }
}

const confirm = () => {
    settleConfirm(true)
    dialogRef.value?.close()
}

const cancel = () => {
    dialogRef.value?.close()
}

const onClose = () => {
    if (confirmDialog.open) settleConfirm(false)
}

watch(() => confirmDialog.open, syncOpen)
onMounted(() => syncOpen(confirmDialog.open))
</script>
