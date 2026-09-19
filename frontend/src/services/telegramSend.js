import api from './api.js'
import { useAuthStore } from './auth.js'
import { apiErrorMessage } from './formatters.js'
import toasts from './toasts.js'

export const hasTelegramLinked = () => {
    const auth = useAuthStore()
    return Boolean(auth.user?.identities?.some((identity) => identity.provider === 'telegram'))
}

/** @returns {boolean} true if linked */
export const requireTelegramLinked = () => {
    if (hasTelegramLinked()) return true

    toasts.info('Link Telegram in Settings → Accounts first!')
    return false
}

export const sendAttachmentToTelegram = async (transactionId, attachmentId, caption = null) => {
    if (!requireTelegramLinked()) {
        return { ok: false, needsLink: true }
    }

    try {
        const body = caption ? { caption } : {}
        const response = await api.post(`/transactions/${transactionId}/attachments/${attachmentId}/telegram`, body, {
            timeout: 120_000,
        })

        toasts.success(response.data?.message || 'Sent to Telegram successfully!')
        return { ok: true }
    } catch (error) {
        toasts.error(apiErrorMessage(error, 'Failed to send to Telegram: '))
        return { ok: false, error }
    }
}

/**
 * @param {Blob|File} file
 * @param {string|null} caption
 */
export const sendFileToTelegram = async (file, caption = null) => {
    if (!requireTelegramLinked()) {
        return { ok: false, needsLink: true }
    }

    try {
        const form = new FormData()
        form.append('file', file, file.name || 'file')
        if (caption) form.append('caption', caption)

        const response = await api.post('/telegram/send-file', form, {
            timeout: 120_000,
            headers: { 'Content-Type': 'multipart/form-data' },
        })

        toasts.success(response.data?.message || 'Sent to Telegram successfully!')
        return { ok: true }
    } catch (error) {
        toasts.error(apiErrorMessage(error, 'Failed to send to Telegram: '))
        return { ok: false, error }
    }
}
