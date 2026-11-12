<template>
    <dialog id="whats_new_modal" class="modal" aria-labelledby="whats_new_modal_title" @close="markWhatsNewSeen">
        <div class="modal-box max-w-sm">
            <ModalHeader title-id="whats_new_modal_title" :icon="PartyPopper">
                What's new
            </ModalHeader>

            <section v-for="release in shown" :key="release.version" class="mb-4 last:mb-0">
                <div class="text-xs font-medium text-base-content/50 mb-2 tabular-nums">Version {{ release.version }}</div>
                <ul class="flex flex-col gap-2">
                    <li v-for="item in release.items" :key="item" class="flex gap-2 text-sm">
                        <Check :size="16" class="shrink-0 mt-0.5 text-success" />
                        <span>{{ item }}</span>
                    </li>
                </ul>
            </section>

            <div class="modal-action items-center">
                <a :href="WHATS_NEW_URL" target="_blank" rel="noopener" class="link link-hover text-sm mr-auto">
                    See all
                </a>
                <form method="dialog">
                    <button class="btn btn-primary">
                        <Check :size="20" />
                        Got it
                    </button>
                </form>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button>close</button>
        </form>
    </dialog>
</template>

<script setup>
import { Check, PartyPopper } from '@lucide/vue'
import { ref, watch } from 'vue'
import ModalHeader from '../components/ModalHeader.vue'
import { useDialogOpen } from '../services/dialogOpen.js'
import { markWhatsNewSeen, WHATS_NEW_URL, whatsNewReleases } from '../services/whatsNew.js'

// Frozen while open: marking as seen on close must not swap the list during the fade-out.
const { isOpen } = useDialogOpen('whats_new_modal')
const shown = ref(whatsNewReleases.value)
watch(isOpen, (open) => {
    if (open) shown.value = whatsNewReleases.value
})
</script>
