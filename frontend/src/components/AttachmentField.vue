<template>
    <div class="mb-4">
        <div class="flex items-center justify-between gap-2 mb-2">
            <span class="text-sm font-medium">Attachments</span>
            <span class="text-xs text-base-content/50">{{ totalCount }}/{{ MAX_PER_TRANSACTION }} · images & PDF</span>
        </div>

        <ul v-if="attachments.length || pending.length" class="flex flex-col gap-2 mb-2">
            <li v-for="file in attachments" :key="'a-' + file.id"
                class="flex items-center gap-2 rounded-lg border border-base-300 bg-base-200/40 px-2 py-1.5">
                <button type="button" class="btn btn-ghost btn-xs btn-square shrink-0" :disabled="busy"
                    :aria-label="'Open ' + file.name" @click="openRemote(file)">
                    <FileText v-if="isPdf(file.mime)" :size="16" />
                    <ImageIcon v-else :size="16" />
                </button>
                <div class="min-w-0 flex-1">
                    <div class="truncate text-sm">{{ file.name }}</div>
                    <div class="text-[10px] text-base-content/50">{{ formatSize(file.size) }}</div>
                </div>
                <button type="button" class="btn btn-ghost btn-xs btn-square text-error shrink-0" :disabled="busy"
                    :aria-label="'Remove ' + file.name" @click="removeRemote(file)">
                    <X :size="14" />
                </button>
            </li>

            <li v-for="(file, index) in pending" :key="'p-' + index + file.name"
                class="flex items-center gap-2 rounded-lg border border-dashed border-base-300 px-2 py-1.5">
                <span class="btn btn-ghost btn-xs btn-square shrink-0 pointer-events-none" aria-hidden="true">
                    <FileText v-if="isPdf(file.type)" :size="16" />
                    <ImageIcon v-else :size="16" />
                </span>
                <div class="min-w-0 flex-1">
                    <div class="truncate text-sm">{{ file.name }}</div>
                    <div class="text-[10px] text-base-content/50">{{ formatSize(file.size) }} · pending</div>
                </div>
                <button type="button" class="btn btn-ghost btn-xs btn-square text-error shrink-0" :disabled="busy"
                    :aria-label="'Remove ' + file.name" @click="removePending(index)">
                    <X :size="14" />
                </button>
            </li>
        </ul>

        <label v-if="totalCount < MAX_PER_TRANSACTION" class="btn btn-outline btn-sm w-full"
            :class="{ 'btn-disabled': busy }">
            <Paperclip :size="16" />
            Add files
            <input type="file" class="hidden" accept="image/jpeg,image/png,image/webp,application/pdf" multiple
                :disabled="busy || totalCount >= MAX_PER_TRANSACTION" @change="onPick" />
        </label>
    </div>
</template>

<script setup>
import { FileText, Image as ImageIcon, Paperclip, X } from 'lucide-vue-next'
import { computed } from 'vue'
import { ALLOWED_MIMES, MAX_BYTES, MAX_PER_TRANSACTION } from '../services/attachmentLimits.js'
import toasts from '../services/toasts.js'
import { useTransactionsStore } from '../services/transactions.js'

const props = defineProps({
    attachments: { type: Array, default: () => [] },
    pending: { type: Array, default: () => [] },
    transactionId: { type: [Number, String], default: null },
    busy: { type: Boolean, default: false },
})

const emit = defineEmits(['update:pending', 'changed'])

const transactionsStore = useTransactionsStore()

const totalCount = computed(() => props.attachments.length + props.pending.length)

const isPdf = (mime) => mime === 'application/pdf'

const formatSize = (bytes) => {
    if (!bytes && bytes !== 0) return ''
    if (bytes < 1024) return `${bytes} B`
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

const onPick = async (event) => {
    const picked = Array.from(event.target.files || [])
    event.target.value = ''

    if (!picked.length) return

    const room = MAX_PER_TRANSACTION - totalCount.value
    const accepted = []

    for (const file of picked.slice(0, room)) {
        if (!ALLOWED_MIMES.has(file.type)) {
            toasts.error(`${file.name}: only JPEG, PNG, WebP, and PDF are allowed!`)
            continue
        }
        if (file.size > MAX_BYTES) {
            toasts.error(`${file.name}: must be 8 MB or smaller!`)
            continue
        }
        accepted.push(file)
    }

    if (!accepted.length) return

    if (props.transactionId) {
        for (const file of accepted) {
            const ok = await transactionsStore.uploadAttachment(props.transactionId, file)
            if (!ok) break
        }
        emit('changed')
        return
    }

    emit('update:pending', [...props.pending, ...accepted])
}

const removePending = (index) => {
    emit(
        'update:pending',
        props.pending.filter((_, i) => i !== index),
    )
}

const removeRemote = async (file) => {
    const ok = await transactionsStore.deleteAttachment(props.transactionId, file.id)
    if (ok) emit('changed')
}

const openRemote = (file) => {
    transactionsStore.openAttachmentPreview({ id: props.transactionId, attachments: props.attachments }, file.id)
}
</script>
