<template>
    <dialog id="tag_form_modal" ref="dialogRef" class="modal" aria-labelledby="tag_form_modal_title"
        @cancel.prevent="requestClose" @click.capture="onClickCapture">
        <div class="modal-box max-w-sm">
            <ModalHeader title-id="tag_form_modal_title" :icon="Tag">
                {{ form.id ? 'Edit Tag' : 'Add Tag' }}
                <span v-if="dirty && form.id" class="ml-1 inline-flex items-center gap-1.5 text-xs font-normal text-warning"
                    role="status">
                    <span class="size-2 rounded-full bg-warning" aria-hidden="true"></span>
                    Unsaved
                </span>
            </ModalHeader>

            <form ref="formRef" @submit.prevent="handleSubmit">
                <TagFormFields :key="formKey" v-model="form" :exclude-ids="excludedParentIds" />

                <div class="modal-action items-center">
                    <p v-if="!form.title.trim()" class="mr-auto text-xs text-base-content/60" role="status">
                        Enter a title to save.
                    </p>
                    <div class="tooltip tooltip-top" :data-tip="saveTip">
                        <button type="submit" class="btn btn-primary tg-primary" :disabled="!canSave">
                            <span v-if="tagsStore.isLoading" class="loading loading-spinner"></span>
                            <Save v-else :size="20" />
                            Save
                        </button>
                    </div>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button>close</button>
        </form>
    </dialog>
</template>

<script setup>
import { Save, Tag } from '@lucide/vue'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import ModalHeader from '../components/ModalHeader.vue'
import TagFormFields from '../components/tags/TagFormFields.vue'
import { askConfirm, confirmDialog } from '../services/confirmDialog.js'
import { randomEmoji } from '../services/emojiDefaults.js'
import { useTagsStore } from '../services/tags.js'
import { useTelegramPrimary } from '../services/telegramChrome.js'

const tagsStore = useTagsStore()

const dialogRef = ref(null)
const formRef = ref(null)

useTelegramPrimary('tag_form_modal', {
    text: 'Save',
    form: formRef,
    busy: () => Boolean(tagsStore.isLoading),
    enabled: () => canSave.value,
})

const blank = (parentId = null) => ({
    title: '',
    parent_id: parentId ?? '',
    emoji: randomEmoji(),
    calc_balance: false,
})

const form = ref(blank())
const formKey = ref(0)

// Unsaved changes: compared with how the form looked when it was loaded.
const signature = (value) => JSON.stringify([value.title.trim(), value.parent_id, value.emoji, value.calc_balance])
const snapshot = ref(signature(form.value))
const dirty = computed(() => signature(form.value) !== snapshot.value)

const canSave = computed(() => Boolean(form.value.title.trim()) && dirty.value && !tagsStore.isLoading)
const saveKeys = /Mac|iPhone|iPad/.test(navigator.userAgent) ? '⌘S' : 'Ctrl+S'
const saveTip = computed(() => {
    if (!form.value.title.trim()) return 'Enter a title first'
    if (!dirty.value) return 'No changes to save'

    return `Save (${saveKeys})`
})

const load = (tag, parentId) => {
    form.value = tag
        ? {
              id: tag.id,
              title: tag.title,
              parent_id: tag.parent_id ?? '',
              emoji: tag.emoji,
              calc_balance: tag.calc_balance,
          }
        : blank(parentId)
    snapshot.value = signature(form.value)
    formKey.value++
}

watch(
    () => tagsStore.tagForm,
    (request) => {
        if (request) load(request.tag, request.parentId)
    },
)

// A tag cannot sit under itself or one of its own sub-tags.
const excludedParentIds = computed(() => (form.value.id ? [...tagsStore.descendantIds(form.value.id)] : []))

const confirmDiscard = async () => {
    if (!dirty.value) return true

    return askConfirm({
        title: 'Discard changes?',
        message: `Your edits${form.value.id ? ` to ${tagsStore.tags.find((t) => t.id === form.value.id)?.title ?? 'this tag'}` : ''} haven't been saved.`,
        actionLabel: 'Discard',
        danger: true,
    })
}

// Closing (X, backdrop, Esc) asks first when there are unsaved changes.
const requestClose = async () => {
    if (await confirmDiscard()) dialogRef.value?.close()
}

const onClickCapture = (event) => {
    if (!dirty.value) return
    if (!event.target.closest?.('[aria-label="Close"], .modal-backdrop button')) return
    event.preventDefault()
    event.stopPropagation()
    requestClose()
}

// On the window, so it works after focus was lost too.
const onKeydown = (event) => {
    if (event.key.toLowerCase() !== 's' || !(event.ctrlKey || event.metaKey)) return
    if (!dialogRef.value?.open || confirmDialog.open) return
    event.preventDefault()
    if (canSave.value) formRef.value?.requestSubmit()
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))

const handleSubmit = async () => {
    if (!canSave.value) return

    const ok = form.value.id ? await tagsStore.update(form.value) : await tagsStore.create(form.value)

    if (!ok) return

    snapshot.value = signature(form.value)
    dialogRef.value?.close()
}
</script>
