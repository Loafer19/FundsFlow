<template>
    <dialog id="attachment_preview_modal" class="modal" aria-labelledby="attachment_preview_modal_title">
        <div class="modal-box max-w-3xl w-full overflow-x-hidden">
            <div class="flex items-start justify-between gap-2 mb-3">
                <div class="min-w-0">
                    <h2 id="attachment_preview_modal_title" class="card-title text-base truncate">
                        {{ current?.name || 'Attachment' }}
                    </h2>
                    <p v-if="attachments.length > 1" class="text-xs text-base-content/50">
                        {{ index + 1 }} / {{ attachments.length }}
                    </p>
                </div>
                <div class="flex items-center gap-1 shrink-0">
                    <button type="button" class="btn btn-ghost btn-sm btn-square" aria-label="Send to Telegram"
                        :disabled="loading || sending || !current" @click="sendToTelegram">
                        <span v-if="sending" class="loading loading-spinner loading-xs"></span>
                        <Send v-else :size="18" />
                    </button>
                    <button v-if="!inTelegram" type="button" class="btn btn-ghost btn-sm btn-square" aria-label="Download"
                        :disabled="loading || !blobUrl" @click="download">
                        <Download :size="18" />
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm btn-square" aria-label="Close" @click="close">
                        <X :size="18" />
                    </button>
                </div>
            </div>

            <div class="min-h-48 w-full max-w-full flex items-center justify-center rounded-lg bg-base-200/50 overflow-hidden">
                <span v-if="loading" class="loading loading-spinner loading-md"></span>
                <p v-else-if="error" class="text-sm text-error px-4 text-center">{{ error }}</p>
                <img v-else-if="blobUrl && isImage" :src="blobUrl" :alt="current?.name || 'Attachment'"
                    class="max-h-[60vh] w-auto max-w-full object-contain" />
                <iframe v-else-if="blobUrl && isPdf" :src="blobUrl" title="PDF preview"
                    class="block w-full max-w-full h-[60vh] rounded-lg bg-base-100 border-0"></iframe>
            </div>


            <div v-if="attachments.length > 1" class="modal-action justify-between mt-4">
                <button type="button" class="btn btn-ghost btn-sm" :disabled="loading || index <= 0"
                    @click="go(-1)">
                    Previous
                </button>
                <button type="button" class="btn btn-ghost btn-sm"
                    :disabled="loading || index >= attachments.length - 1" @click="go(1)">
                    Next
                </button>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button @click="close">close</button>
        </form>
    </dialog>
</template>

<script setup>
import { Download, Send, X } from 'lucide-vue-next'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { showModal } from '../services/modal.js'
import { sendAttachmentToTelegram } from '../services/telegramSend.js'
import { isTelegramWebApp } from '../services/telegramWebApp.js'
import { useTransactionsStore } from '../services/transactions.js'

const transactionsStore = useTransactionsStore()

const loading = ref(false)
const sending = ref(false)
const inTelegram = isTelegramWebApp()
const error = ref('')
const blobUrl = ref(null)
let loadId = 0

const preview = computed(() => transactionsStore.attachmentPreview)
const attachments = computed(() => preview.value?.attachments ?? [])
const index = computed(() => preview.value?.index ?? 0)
const current = computed(() => attachments.value[index.value] ?? null)
const isPdf = computed(() => current.value?.mime === 'application/pdf')
const isImage = computed(() => !!current.value?.mime?.startsWith('image/'))

const revoke = () => {
    if (blobUrl.value) {
        URL.revokeObjectURL(blobUrl.value)
        blobUrl.value = null
    }
}

const close = () => {
    loadId += 1
    revoke()
    error.value = ''
    transactionsStore.closeAttachmentPreview()
    document.getElementById('attachment_preview_modal')?.close()
}

const download = () => {
    if (!blobUrl.value || !current.value) return

    const link = document.createElement('a')
    link.href = blobUrl.value
    link.download = current.value.name || 'attachment'
    link.rel = 'noopener'
    document.body.appendChild(link)
    link.click()
    link.remove()
}

const sendToTelegram = async () => {
    if (!preview.value || !current.value || sending.value) return

    sending.value = true
    try {
        await sendAttachmentToTelegram(preview.value.transactionId, current.value.id)
    } finally {
        sending.value = false
    }
}

const load = async () => {
    const id = ++loadId
    revoke()
    error.value = ''

    if (!preview.value || !current.value) return

    loading.value = true

    try {
        const url = await transactionsStore.fetchAttachmentBlob(preview.value.transactionId, current.value)
        if (id !== loadId) {
            URL.revokeObjectURL(url)
            return
        }
        blobUrl.value = url
    } catch {
        if (id !== loadId) return
        error.value = 'Failed to open attachment!'
    } finally {
        if (id === loadId) loading.value = false
    }
}

const go = (delta) => {
    if (!preview.value) return

    const next = index.value + delta
    if (next < 0 || next >= attachments.value.length) return

    transactionsStore.attachmentPreview = {
        ...preview.value,
        index: next,
    }
}

const onDialogClose = () => {
    revoke()
    error.value = ''
    if (transactionsStore.attachmentPreview) {
        transactionsStore.closeAttachmentPreview()
    }
}

watch(
    preview,
    async (value) => {
        if (!value) {
            revoke()
            return
        }

        await nextTick()
        if (!document.getElementById('attachment_preview_modal')?.open) {
            showModal('attachment_preview_modal')
        }
        await load()
    },
    { deep: true },
)

onMounted(() => {
    document.getElementById('attachment_preview_modal')?.addEventListener('close', onDialogClose)
})

onBeforeUnmount(() => {
    document.getElementById('attachment_preview_modal')?.removeEventListener('close', onDialogClose)
    revoke()
})
</script>
