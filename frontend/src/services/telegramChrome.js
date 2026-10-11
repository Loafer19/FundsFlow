// Telegram Mini App chrome for our <dialog> modals:
//   - BackButton closes the top modal. Without it, Android "back" closes the
//     whole Mini App and the half-filled form is lost.
//   - MainButton submits the top modal's form; it sits above the keyboard.
//   - Closing confirmation while a form modal is open.
// Everything is a no-op in a normal browser.

import { onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { haptic, isTelegramWebApp, miniApp, supports } from './telegramWebApp.js'

/** Ids of open <dialog>s, top-most last. */
const openDialogs = ref([])

/** dialogId → { text, form(), busy(), enabled() } for modals with a primary action. */
const primaries = reactive(new Map())

let installed = false

const topDialog = () => openDialogs.value.at(-1) ?? null

const syncOpenDialog = (dialog) => {
    if (!dialog.id) return

    const rest = openDialogs.value.filter((id) => id !== dialog.id)
    openDialogs.value = dialog.open ? [...rest, dialog.id] : rest
}

const submitTop = () => {
    const config = primaries.get(topDialog())
    const form = config?.form()

    if (!form) return

    if (!form.checkValidity()) {
        haptic.error()
        form.reportValidity()

        return
    }

    form.requestSubmit()
}

/**
 * Wire BackButton / MainButton to the modals. Call once at startup, inside
 * the Mini App only. Driven by each dialog's `open` attribute, so the many
 * showModal() / close() call sites stay untouched.
 */
export const installTelegramChrome = () => {
    const app = miniApp()

    if (installed || !app) return
    installed = true

    for (const dialog of document.querySelectorAll('dialog[open]')) syncOpenDialog(dialog)

    new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            if (mutation.target instanceof HTMLDialogElement) syncOpenDialog(mutation.target)
        }
    }).observe(document.body, { subtree: true, attributes: true, attributeFilter: ['open'] })

    if (supports('6.1')) {
        app.BackButton.onClick(() => document.getElementById(topDialog())?.close())

        watch(
            () => openDialogs.value.length > 0,
            (anyOpen) => (anyOpen ? app.BackButton.show() : app.BackButton.hide()),
            { immediate: true },
        )
    }

    // One handler for the app's lifetime; it always targets the current top
    // modal, so switching modals never stacks onClick handlers.
    app.MainButton.onClick(submitTop)

    watch(
        () => {
            const config = primaries.get(topDialog())

            return config ? { text: config.text, busy: config.busy(), enabled: config.enabled() } : null
        },
        (state) => {
            const button = app.MainButton

            if (!state) {
                button.hideProgress()
                button.hide()

                return
            }

            button.setParams({ text: state.text, is_active: state.enabled && !state.busy, is_visible: true })
            state.busy ? button.showProgress(false) : button.hideProgress()
        },
        { immediate: true, deep: true },
    )

    if (supports('6.2')) {
        watch(
            () => openDialogs.value.some((id) => primaries.has(id)),
            (formOpen) => (formOpen ? app.enableClosingConfirmation() : app.disableClosingConfirmation()),
            { immediate: true },
        )
    }
}

/**
 * Let Telegram's MainButton submit this modal's form while it is the top modal.
 * The in-modal submit button is hidden in the Mini App via `.tg-primary`.
 *
 * @param {string} dialogId
 * @param {{ text: string, form: import('vue').Ref<HTMLFormElement|null>, busy?: () => boolean, enabled?: () => boolean }} options
 */
export const useTelegramPrimary = (dialogId, { text, form, busy = () => false, enabled = () => true }) => {
    if (!isTelegramWebApp()) return

    onMounted(() => primaries.set(dialogId, { text, form: () => form.value, busy, enabled }))
    onBeforeUnmount(() => primaries.delete(dialogId))
}
