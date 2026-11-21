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
        version: '2.5.0',
        items: [
            'Recurring rules can have a type and its fields, the same as a regular transaction. Period and Next charge follow the schedule and stay read-only.',
            'A Recurring card on Analytics shows your monthly and yearly totals for active expense rules, the next charge, and a bar per type. View all opens Recurring; click a type to filter the list.',
            'The Recurring list shows type segments. /recurring in Telegram names the type and service. The assistant can set a type when it creates or updates a rule.',
            'Receipt photos, PDFs and free-text messages automatically pick your matching transaction type and fill its fields (like liters, price per liter and station for Fuel, or the store for Groceries) on the confirmation card.',
            'The assistant and MCP can answer type-field questions: how much you spent on A-95 this year, liters in October, or the average price per liter.',
            'Limits can track a transaction type or the sum of a numeric field (like Tips) as well as tags.',
            'Type analytics shows Best rated and Best value rankings grouped by place for types with a Rating field (including the default Restaurant type).',
        ],
    },
    {
        version: '2.4.0',
        items: [
            'Transaction types with your own fields: start with Fuel, Groceries, Subscription and Restaurant, or make your own. Manage them from Types in the header.',
            'The List shows a type as a colored stripe and a Type column of field segments. Hover a segment to see what it is.',
            'Click a type to open its analytics: totals, trends, and a breakdown of spend by a field.',
            'Bank imports can suggest a type for known shops, or you can set one per row or for the whole file.',
            'In the assistant, send receipt photos and statement files in the same message.',
            'In Telegram, write Fuel: 40 l at 58.9 and the assistant fills the type and its fields.',
            'Ask the assistant to create, change or archive types, then confirm one card.',
            'If Telegram blocks live widgets, the bot switches to text cards and a button editor, and retries later.',
            'Receipt suggestions use only tags you already have.',
        ],
    },
    {
        version: '2.3.0',
        items: [
            'A cleaner layout: tabs grouped into Overview, Plan and Charts, with a bottom dock on phones.',
            'Settings in a side panel with fewer sections, and a new Import page with your past imports.',
            'The Trend chart shows where your balance is heading this period.',
            'The Telegram bot got live widgets: this month, budgets, tags, recent and recurring payments.',
            'Just write or say a transaction in Telegram, any language: the assistant saves it.',
            'Fix a new entry right on its card: ✏️ Edit opens an editor, ↩️ Undo works for 5 minutes.',
            'Budget alerts, reached goals and the weekly digest come with progress bars.',
            'Get a reminder the evening before a recurring payment, and pin a live month summary in the chat.',
            'After an update the app reloads by itself instead of showing a loading error.',
        ],
    },
    {
        version: '2.2.0',
        items: [
            'Connect monobank with a personal token: cards sync automatically, including the last 31 days.',
            'New purchases can ping you in Telegram so you can change the tag or delete them.',
            'Purchases you delete stay deleted: syncing again won’t bring them back.',
            'Import a bank statement from the List tab and check everything on a preview page before it is saved.',
            'Map the file’s own categories to your tags, tag a shop in bulk, skip transfers or old rows, then import — and undo the whole batch.',
            'The assistant and Telegram bot send a link to that page instead of importing right away.',
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
