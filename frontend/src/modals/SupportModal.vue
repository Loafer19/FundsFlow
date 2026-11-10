<template>
    <dialog id="support_modal" class="modal" aria-labelledby="support_modal_title" @close="onClose">
        <div class="modal-box max-w-md">
            <ModalHeader title-id="support_modal_title" :icon="LifeBuoy" dense>
                Feedback
            </ModalHeader>

            <template v-if="sent">
                <p class="text-sm text-base-content/60 mb-4">
                    Thanks! Request #{{ sent.id }} is sent. We'll reply {{ hasTelegram ? 'in Telegram and ' : '' }}in
                    Settings → Support.
                </p>
                <div class="modal-action">
                    <button type="button" class="btn btn-ghost" @click="openRequests">My requests</button>
                    <button type="button" class="btn btn-primary" @click="close">Done</button>
                </div>
            </template>

            <form v-else @submit.prevent="submit">
                <p class="text-sm text-base-content/60 mb-4">
                    Something not working, a question or an idea? Quick answers are in the
                    <a :href="FAQ_URL" target="_blank" rel="noopener" class="link">FAQ</a>.
                </p>

                <input v-model="subject" type="text" class="input w-full mb-3" placeholder="Subject"
                    aria-label="Subject" maxlength="120" required />

                <textarea v-model="body" class="textarea w-full h-36 mb-1" maxlength="5000" required
                    aria-label="Message"
                    placeholder="What happened, and what did you expect? Steps to repeat it help a lot."></textarea>

                <p class="text-xs text-base-content/50">App version (v{{ version }}) and browser are added automatically.</p>

                <div class="modal-action">
                    <button type="submit" class="btn btn-primary" :disabled="sending || !canSend">
                        <span v-if="sending" class="loading loading-spinner loading-sm"></span>
                        <Send v-else :size="20" />
                        Send
                    </button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button>close</button>
        </form>
    </dialog>
</template>

<script setup>
import { LifeBuoy, Send } from 'lucide-vue-next'
import { computed, ref } from 'vue'
import { version } from '../../package.json'
import { useAuthStore } from '../services/auth.js'
import { apiErrorMessage } from '../services/formatters.js'
import { openSettingsTab } from '../services/modal.js'
import { createSupportTicket, FAQ_URL } from '../services/support.js'
import toasts from '../services/toasts.js'
import ModalHeader from '../components/ModalHeader.vue'

const authStore = useAuthStore()

const subject = ref('')
const body = ref('')
const sending = ref(false)
const sent = ref(null)

const canSend = computed(() => subject.value.trim() !== '' && body.value.trim() !== '')
const hasTelegram = computed(() =>
    (authStore.user?.identities || []).some((identity) => identity.provider === 'telegram'),
)

const submit = async () => {
    if (!canSend.value || sending.value) return
    sending.value = true

    try {
        const { data } = await createSupportTicket({ subject: subject.value.trim(), body: body.value.trim() })
        sent.value = data
        window.dispatchEvent(new CustomEvent('support:created'))
        subject.value = ''
        body.value = ''
    } catch (error) {
        toasts.error(apiErrorMessage(error, 'Could not send: '))
    } finally {
        sending.value = false
    }
}

const close = () => document.getElementById('support_modal')?.close()

const openRequests = () => {
    close()
    openSettingsTab('support')
}

// A sent form starts fresh next time; an unsent draft is kept.
const onClose = () => {
    sent.value = null
}
</script>
