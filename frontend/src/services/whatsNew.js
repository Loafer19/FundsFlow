import { computed, ref } from 'vue'
import { version as APP_VERSION } from '../../package.json'
import { updatePreferences } from './account.js'
import { useAuthStore } from './auth.js'

/**
 * In-app "What's new": short highlights per version (newest first), plain user language.
 * The docs page (WHATS_NEW_URL) has the full list. A version only shows once the app is on it,
 * so the next release's entry can be written ahead of the version bump.
 */
export const RELEASES = [
    {
        version: '2.2.0',
        items: [
            'Connect monobank with a personal token: cards sync automatically, including the last 31 days.',
            'New purchases can ping you in Telegram so you can change the tag or delete them.',
            'A small Mono badge marks imported transactions in the List.',
        ],
    },
    {
        version: '2.1.0',
        items: [
            'Report a problem or send feedback right from the app: Feedback in the footer.',
            'Follow your requests and our replies in Settings → Support, or in Telegram.',
            'In the bot, send /support with your message, or just tell the assistant.',
            'Limits and savings goals on a parent tag now count its child tags too.',
        ],
    },
    {
        version: '2.0.1',
        items: [
            'See totals and averages for the period right under the List.',
            'A short loading indicator after sign-in instead of empty screens.',
            'A tidier footer with the bot, feedback and this list.',
        ],
    },
    {
        version: '2.0.0',
        items: [
            'Ask the AI assistant about your money on the web (✨ or press /) and in Telegram.',
            'Import bank statements, spreadsheets and screenshots by sending them to the assistant.',
            'Savings goals on the Budgets tab, and clearer spending limits.',
        ],
    },
]

export const WHATS_NEW_URL = 'https://fundsflow.fun/docs/whats-new/'

const STORAGE_KEY = 'whats-new:seen'

/** -1 / 0 / 1 for "x.y.z" strings. */
export const compareVersions = (a, b) => {
    const pa = String(a).split('.').map(Number)
    const pb = String(b).split('.').map(Number)
    for (let i = 0; i < 3; i++) {
        const diff = (pa[i] || 0) - (pb[i] || 0)
        if (diff) return diff > 0 ? 1 : -1
    }
    return 0
}

const released = RELEASES.filter((release) => compareVersions(release.version, APP_VERSION) <= 0)

const localSeen = ref(localStorage.getItem(STORAGE_KEY))

/** Last version whose highlights were seen: the account's (synced across devices) or this browser's. */
const seenVersion = computed(() => {
    const fromAccount = useAuthStore().user?.preferences?.whatsNewSeen
    const candidates = [fromAccount, localSeen.value].filter(Boolean)
    return candidates.sort(compareVersions).pop() ?? null
})

export const hasUnseenWhatsNew = computed(
    () => released.length > 0 && (!seenVersion.value || compareVersions(seenVersion.value, released[0].version) < 0),
)

/** Releases newer than the last seen one (at most two); just the current one when none was seen or all are. */
export const whatsNewReleases = computed(() => {
    const seen = seenVersion.value
    const unseen = seen ? released.filter((release) => compareVersions(release.version, seen) > 0) : []
    return unseen.length ? unseen.slice(0, 2) : released.slice(0, 1)
})

export const markWhatsNewSeen = () => {
    if (!hasUnseenWhatsNew.value) return

    localStorage.setItem(STORAGE_KEY, APP_VERSION)
    localSeen.value = APP_VERSION

    const auth = useAuthStore()
    if (!auth.isAuthenticated || !auth.user) return

    auth.user.preferences = { ...(auth.user.preferences || {}), whatsNewSeen: APP_VERSION }
    // Best effort: the local copy already hides the dot on this device.
    updatePreferences({ whatsNewSeen: APP_VERSION }).catch(() => {})
}
