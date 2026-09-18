import { reactive } from 'vue'

/** ICU lists Europe/Kiev; PHP/IANA use Europe/Kyiv. */
const normalizeTimezone = (timezone) => (timezone === 'Europe/Kiev' ? 'Europe/Kyiv' : timezone)

const defaults = {
    dateFormat: 'short Month with Day',
    moneyFormat: 'uk-UA',
    decimals: true,
    timezone: normalizeTimezone(Intl.DateTimeFormat().resolvedOptions().timeZone || 'Europe/Kyiv'),
    theme: 'bumblebee',
}

function loadSaved() {
    const settings = { ...defaults }

    const dateFormat = localStorage.getItem('format:date')
    if (dateFormat) settings.dateFormat = dateFormat

    const moneyFormat = localStorage.getItem('format:money')
    if (moneyFormat) settings.moneyFormat = moneyFormat

    const decimals = localStorage.getItem('format:money:decimals')
    if (decimals !== null) settings.decimals = JSON.parse(decimals)

    const timezone = localStorage.getItem('format:timezone')
    if (timezone) settings.timezone = normalizeTimezone(timezone)

    const theme = localStorage.getItem('theme')
    if (theme) settings.theme = theme

    return settings
}

function save(key, value) {
    localStorage.setItem(key, typeof value === 'boolean' ? JSON.stringify(value) : value)
}

const settings = reactive(loadSaved())

export function updateDateFormat(format) {
    settings.dateFormat = format
    save('format:date', format)
}

export function updateMoneyFormat(format) {
    settings.moneyFormat = format
    save('format:money', format)
}

export function updateDecimals(decimals) {
    settings.decimals = decimals
    save('format:money:decimals', decimals)
}

export function updateTimezone(timezone) {
    const normalized = normalizeTimezone(timezone)
    settings.timezone = normalized
    save('format:timezone', normalized)
}

export function updateTheme(theme) {
    settings.theme = theme
    save('theme', theme)
    document.documentElement.setAttribute('data-theme', theme)
}

export function applyUserPreferences(preferences) {
    if (!preferences || typeof preferences !== 'object') return

    if (preferences.dateFormat) updateDateFormat(preferences.dateFormat)
    if (preferences.moneyFormat) updateMoneyFormat(preferences.moneyFormat)
    if (typeof preferences.decimals === 'boolean') updateDecimals(preferences.decimals)
    if (preferences.timezone) updateTimezone(preferences.timezone)
}

export default settings
