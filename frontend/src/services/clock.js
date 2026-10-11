import { ref, watch } from 'vue'
import { nowDateStr } from './formatters.js'
import settings from './settings.js'

/**
 * Today's date (YYYY-MM-DD) in the user's timezone, kept current across
 * midnight, tab switches and timezone changes — so "Today" never means
 * yesterday in a modal that stayed mounted overnight.
 */
export const today = ref(nowDateStr())

const refresh = () => {
    const value = nowDateStr()

    if (value !== today.value) today.value = value
}

setInterval(refresh, 60_000)
document.addEventListener('visibilitychange', refresh)
watch(() => settings.timezone, refresh)
