<template>
    <footer class="mt-auto pt-10 text-center">
        <div class="flex flex-col items-center gap-1.5 border-t border-base-300 pt-5">
            <div class="flex items-center gap-2">
                <img src="/logo.png" alt="" width="28" height="28" class="size-7 rounded-lg" />
                <span class="text-base font-semibold">FundsFlow</span>
            </div>
            <nav class="flex flex-wrap items-center justify-center gap-x-2 text-xs text-base-content/50"
                aria-label="Links">
                <button type="button" class="link link-hover tabular-nums inline-flex items-center gap-1"
                    :aria-label="`Version ${version}, what's new${hasUnseenWhatsNew ? ' (new)' : ''}`"
                    @click="showModal('whats_new_modal')">
                    v{{ version }}
                    <span v-if="hasUnseenWhatsNew" class="status status-info" aria-hidden="true"></span>
                </button>
                <span aria-hidden="true">·</span>
                <a :href="BOT_URL" target="_blank" rel="noopener noreferrer" class="link link-hover">Telegram bot</a>
                <span aria-hidden="true">·</span>
                <button v-if="authStore.isAuthenticated" type="button" class="link link-hover"
                    @click="showModal('support_modal')">Feedback</button>
                <a v-else :href="FEEDBACK_URL" target="_blank" rel="noopener noreferrer" class="link link-hover">Feedback</a>
            </nav>
        </div>
    </footer>
</template>

<script setup>
import { version } from '../../package.json'
import { useAuthStore } from '../services/auth.js'
import { showModal } from '../services/modal.js'
import { hasUnseenWhatsNew } from '../services/whatsNew.js'

const authStore = useAuthStore()

const BOT_URL = 'https://t.me/funds_flow_bot'
// Signed out there is no account to attach a request to: write to the developer in Telegram.
const FEEDBACK_URL = 'https://t.me/Loafer19'
</script>
