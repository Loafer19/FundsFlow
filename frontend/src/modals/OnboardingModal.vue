<template>
    <dialog id="onboarding_modal" class="modal" aria-labelledby="onboarding_modal_title" @close="onDialogClose">
        <div class="modal-box max-w-md">
            <template v-if="step === 'welcome'">
                <div class="flex items-center gap-3 mb-3">
                    <img src="/logo.png" alt="FundsFlow" class="w-14 h-14 rounded-2xl" />
                    <h2 id="onboarding_modal_title" class="card-title mb-0">
                        Welcome to FundsFlow
                    </h2>
                </div>

                <p class="text-sm text-base-content/60 mb-4">
                    Starter tags are ready. Add a first transaction to get going.
                </p>

                <div v-if="previewTags.length" class="flex flex-wrap gap-2 mb-4">
                    <div v-for="tag in previewTags" :key="tag.id" class="badge badge-soft badge-info gap-1 px-2">
                        <span>{{ tag.emoji }}</span>
                        <span>{{ tag.title }}</span>
                    </div>
                </div>

                <div class="flex flex-col gap-2">
                    <button type="button" class="btn btn-primary" @click="goToTelegram('add')">
                        <Plus :size="20" />
                        Add first transaction
                    </button>

                    <button type="button" class="btn btn-outline btn-info" @click="goToTelegram('tags')">
                        <Tag :size="20" />
                        Manage tags
                    </button>

                    <button type="button" class="btn btn-ghost btn-sm" @click="goToTelegram(null)">
                        Skip
                    </button>
                </div>
            </template>

            <template v-else>
                <h2 id="onboarding_modal_title" class="card-title mb-2">
                    <Send :size="24" />
                    Add from Telegram
                </h2>

                <p class="text-sm text-base-content/60 mb-4">
                    Log expenses from chat. Optional, link later in Settings.
                </p>

                <div class="flex flex-col gap-2">
                    <button type="button" class="btn btn-primary" :disabled="linkingTelegram" @click="linkTelegram">
                        <span v-if="linkingTelegram" class="loading loading-spinner loading-sm"></span>
                        <Send v-else :size="20" />
                        Open Telegram to link
                    </button>

                    <button type="button" class="btn btn-ghost btn-sm" @click="finish">
                        Skip
                    </button>
                </div>
            </template>
        </div>

        <form method="dialog" class="modal-backdrop">
            <button>close</button>
        </form>
    </dialog>
</template>

<script setup>
import { Plus, Send, Tag } from 'lucide-vue-next'
import { computed, ref } from 'vue'
import { apiErrorMessage } from '../services/formatters'
import { openTelegramLinkBot } from '../services/identities'
import { showModal } from '../services/modal'
import { isOnboardingDone, markOnboardingDone } from '../services/onboarding'
import { useTagsStore } from '../services/tags'
import toasts from '../services/toasts'

const tagsStore = useTagsStore()

const step = ref('welcome')
const pendingAction = ref(null)
const linkingTelegram = ref(false)

const previewTags = computed(() =>
    tagsStore
        .list()
        .filter((tag) => tag.depth === 0)
        .slice(0, 6),
)

const goToTelegram = (action) => {
    pendingAction.value = action
    step.value = 'telegram'
}

const runPendingAction = () => {
    if (pendingAction.value === 'add') {
        showModal('transactions_add_modal')
    } else if (pendingAction.value === 'tags') {
        showModal('tags_modal')
    }

    pendingAction.value = null
}

const finish = () => {
    markOnboardingDone()
    onboarding_modal.close()
}

const linkTelegram = async () => {
    linkingTelegram.value = true

    try {
        await openTelegramLinkBot()
        finish()
    } catch (error) {
        toasts.error(apiErrorMessage(error, 'Failed to generate link: '))
    } finally {
        linkingTelegram.value = false
    }
}

const onDialogClose = () => {
    if (!isOnboardingDone()) {
        markOnboardingDone()
    }

    runPendingAction()
    step.value = 'welcome'
}
</script>
