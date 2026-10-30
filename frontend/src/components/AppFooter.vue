<template>
    <footer class="mt-auto pt-10 text-center" :class="{ 'max-sm:pb-16': withFab }">
        <div class="flex flex-col items-center gap-1.5 border-t border-base-300 pt-5">
            <div class="flex items-center gap-2">
                <img src="/logo.png" alt="" width="28" height="28" class="size-7 rounded-lg" />
                <span class="text-base font-semibold">FundsFlow</span>
            </div>
            <nav class="flex flex-wrap items-center justify-center gap-x-2 text-xs text-base-content/50"
                aria-label="Links">
                <a :href="WHATS_NEW_URL" target="_blank" rel="noopener" class="link link-hover tabular-nums"
                    :aria-label="`Version ${version}, what's new (opens in a new tab)`">v{{ version }}</a>
                <span aria-hidden="true">·</span>
                <a :href="BOT_URL" target="_blank" rel="noopener noreferrer" class="link link-hover">Telegram bot</a>
                <span aria-hidden="true">·</span>
                <a :href="FEEDBACK_URL" target="_blank" rel="noopener noreferrer" class="link link-hover">Feedback</a>
            </nav>
        </div>
    </footer>
</template>

<script setup>
import { version } from '../../package.json'

defineProps({
    // The fixed round "Add Transaction" button sits bottom-right: keep footer content clear of it.
    withFab: {
        type: Boolean,
        default: false,
    },
})

const WHATS_NEW_URL = 'https://fundsflow.fun/docs/whats-new/'
const BOT_URL = 'https://t.me/funds_flow_bot'
const FEEDBACK_URL = 'https://t.me/Loafer19'
</script>
