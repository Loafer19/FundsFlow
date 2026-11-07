import { ref } from 'vue'

export const showModal = (id) => document.getElementById(id)?.showModal()

/** Settings tab to switch to on the next open (e.g. "support" from the Feedback form). */
export const settingsTabRequest = ref(null)

export const openSettingsTab = (tab) => {
    settingsTabRequest.value = tab
    showModal('settings_modal')
}
