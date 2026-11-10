import { ref } from 'vue'

export const settingsOpen = ref(false)
/** Settings tab to switch to on the next open (e.g. "support" from the Feedback form). */
export const settingsTabRequest = ref(null)

export const showModal = (id) => {
    if (id === 'settings_modal') {
        settingsOpen.value = true
        return
    }
    document.getElementById(id)?.showModal()
}

export const openSettingsTab = (tab) => {
    settingsTabRequest.value = tab
    settingsOpen.value = true
}

export const closeSettings = () => {
    settingsOpen.value = false
}
