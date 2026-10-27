import { onBeforeUnmount, ref, watch } from 'vue'

// Starter prompts for the assistant's empty state. Every prompt maps to something the web assistant's
// tools really do (period summary / comparison, charts, budget status, recurring rules, report / PDF
// cards, tags, quick add, transaction search / edit, statement and receipt import).
//
// Grouped by category: a visible set never shows two chips of one category, so it stays varied.
// Optional flags:
//   needs   'budgets' | 'goals' | 'recurring' | 'transactions' | 'tag'  shown only when that data exists
//   unless  'budgets' | 'goals' | 'recurring'                            shown only when it does NOT exist (setup ideas)
//   attach  chip opens the file picker with `prompt` prefilled instead of sending `text`
// `{tag}` is replaced with the user's most used expense tag (needs: 'tag').

const CATEGORIES = {
    overview: [
        { text: 'How did this month go overall?' },
        { text: 'Where did most of my money go?', needs: 'transactions' },
        { text: "What's my average daily spend?", needs: 'transactions' },
        { text: 'What were my 5 biggest expenses?', needs: 'transactions' },
        { text: 'Did I save anything this month?' },
    ],
    compare: [
        { text: 'Compare this month with last month' },
        { text: 'Which tag grew most vs last month?', needs: 'transactions' },
        { text: 'Am I spending more than last quarter?' },
        { text: 'How does this year compare to last?' },
    ],
    charts: [
        { text: 'Chart my spending by tag' },
        { text: 'Chart income vs expenses this year' },
        { text: 'Show my balance trend this year' },
        { text: 'Chart spending by tag vs last month' },
    ],
    budgets: [
        { text: 'Am I on track with my budgets?', needs: 'budgets' },
        { text: 'How much can I spend per day?', needs: 'budgets' },
        { text: 'Which budget is closest to its limit?', needs: 'budgets' },
        { text: 'Will any budget go over this month?', needs: 'budgets' },
        { text: 'Suggest budgets from my spending', unless: 'budgets' },
        { text: 'Set up a monthly groceries budget', unless: 'budgets' },
        { text: 'How are my savings goals going?', needs: 'goals' },
        { text: 'How much should I save per month to hit my goal?', needs: 'goals' },
        { text: 'Set up a savings goal for a trip', unless: 'goals' },
    ],
    recurring: [
        { text: "What's due in the next 7 days?", needs: 'recurring' },
        { text: 'How much do subscriptions cost monthly?', needs: 'recurring' },
        { text: 'Which recurring payment costs most?', needs: 'recurring' },
        { text: 'Add monthly rent of 12000 on the 1st', unless: 'recurring' },
        { text: 'Add Spotify as a monthly subscription' },
    ],
    reports: [
        { text: 'PDF report for last month' },
        { text: 'Make a report for this quarter' },
        { text: 'Report on {tag} this year', needs: 'tag' },
    ],
    tags: [{ text: 'How much went on {tag} this month?', needs: 'tag' }, { text: 'Create tags Pets and Gifts' }],
    quickAdd: [
        { text: 'Add -350 groceries today' },
        { text: 'Spent 120 on taxi yesterday' },
        { text: 'Got 25000 salary today' },
        { text: 'Add lunch 200 and coffee 60 today' },
    ],
    search: [
        { text: 'Show my last 10 transactions', needs: 'transactions' },
        { text: 'Find my taxi rides last month', needs: 'transactions' },
        { text: 'Find payments with "coffee" this month', needs: 'transactions' },
        { text: 'Change my last transaction to -400', needs: 'transactions' },
    ],
    import: [
        { text: 'Import a bank statement', attach: true, prompt: 'Import the transactions from this file' },
        { text: 'Scan a receipt', attach: true, prompt: 'Add this receipt' },
    ],
}

export const SUGGESTIONS = Object.entries(CATEGORIES).flatMap(([category, items]) =>
    items.map((item, index) => ({ ...item, category, id: `${category}-${index}` })),
)

// Categories that fit the tab on screen get more weight (screen context comes from App.vue).
const TAB_FOCUS = {
    analytics: ['overview', 'compare', 'charts', 'reports'],
    calendar: ['recurring', 'quickAdd', 'search'],
    budgets: ['budgets', 'overview'],
    tags: ['tags', 'compare', 'charts'],
    money_flow: ['overview', 'compare', 'charts'],
    balance_trend: ['charts', 'recurring', 'compare'],
    table: ['search', 'quickAdd', 'import'],
}
const FOCUS_WEIGHT = 3
const MAX_TAG_LENGTH = 14

/**
 * The user's most used expense tag (short titles only, so the chip fits).
 * Cheap: reads the already-loaded transactions store, no requests.
 */
export function topExpenseTag(transactions) {
    const counts = new Map()
    for (const transaction of transactions) {
        if (!(transaction.amount < 0)) continue
        for (const tag of transaction.tags ?? []) {
            const title = tag?.title?.trim()
            if (!title || title.length > MAX_TAG_LENGTH) continue
            counts.set(title, (counts.get(title) ?? 0) + 1)
        }
    }
    let best = null
    for (const [title, count] of counts) if (!best || count > best.count) best = { title, count }

    return best?.title ?? null
}

/**
 * Prompts that make sense for this user right now, each with a pick weight.
 * ctx: { tab, hasBudgets, hasGoals, hasRecurring, hasTransactions, topTag }
 */
export function suggestionPool(ctx = {}) {
    const has = {
        budgets: Boolean(ctx.hasBudgets),
        goals: Boolean(ctx.hasGoals),
        recurring: Boolean(ctx.hasRecurring),
        transactions: Boolean(ctx.hasTransactions),
        tag: Boolean(ctx.topTag),
    }
    const focus = new Set(TAB_FOCUS[ctx.tab] ?? [])

    return SUGGESTIONS.filter((item) => (!item.needs || has[item.needs]) && !(item.unless && has[item.unless])).map(
        (item) => ({
            ...item,
            text: item.text.replace('{tag}', ctx.topTag ?? ''),
            weight: focus.has(item.category) ? FOCUS_WEIGHT : 1,
        }),
    )
}

const weightedPick = (list) => {
    let roll = Math.random() * list.reduce((sum, item) => sum + item.weight, 0)
    for (const item of list) {
        roll -= item.weight
        if (roll < 0) return item
    }

    return list[list.length - 1]
}

const pickIndex = (n) => Math.floor(Math.random() * n)

const reducedMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false

/**
 * Picks one prompt: never one already on screen, prefers a category not on screen and one not in
 * the recent history; each rule is relaxed only when nothing is left.
 */
export function pickNext(pool, shown, recent) {
    const shownIds = new Set(shown.map((item) => item.id))
    const shownCategories = new Set(shown.map((item) => item.category))
    const recentIds = new Set(recent)
    const fresh = pool.filter((item) => !shownIds.has(item.id))
    const tiers = [
        fresh.filter((item) => !shownCategories.has(item.category) && !recentIds.has(item.id)),
        fresh.filter((item) => !shownCategories.has(item.category)),
        fresh.filter((item) => !recentIds.has(item.id)),
        fresh,
    ]
    const tier = tiers.find((list) => list.length)

    return tier ? weightedPick(tier) : null
}

/**
 * `count` chips from the pool: one per category, nothing repeated on screen, recently shown prompts
 * avoided. Reshuffled whenever they become visible again or the pool changes. While visible, one chip
 * is swapped every `interval` ms unless `paused` is true, the browser tab is hidden, or the user
 * prefers reduced motion.
 * Each chip is { key, id, text, attach?, prompt? }; the key changes on every swap so a <Transition>
 * can animate it.
 */
export function useSuggestionChips({ pool, active, paused, count = 3, interval = 7000 }) {
    const chips = ref([])
    let seq = 0
    let lastSlot = -1
    let timer = null
    let recent = []

    const remember = (item) => {
        // About half the pool, so a prompt can't come straight back but small pools still rotate.
        const size = Math.max(count, Math.floor(pool.value.length / 2))
        recent = [...recent.filter((id) => id !== item.id), item.id].slice(-size)
    }

    const toChip = (item) => {
        remember(item)

        return {
            key: seq++,
            id: item.id,
            category: item.category,
            text: item.text,
            attach: item.attach,
            prompt: item.prompt,
        }
    }

    const shuffle = () => {
        const picked = []
        while (picked.length < count) {
            const item = pickNext(pool.value, picked, recent)
            if (!item) break
            picked.push(item)
        }
        // Remember after picking so the whole set counts as "recent" for the next shuffle.
        chips.value = picked.map(toChip)
        lastSlot = -1
    }

    const rotateOne = () => {
        if (!chips.value.length) return
        const slots = chips.value.map((_, index) => index).filter((index) => index !== lastSlot)
        const slot = slots[pickIndex(slots.length)] ?? 0
        // The chip being replaced counts as shown: its category and text must change.
        const item = pickNext(pool.value, chips.value, recent)
        if (!item) return
        lastSlot = slot
        chips.value.splice(slot, 1, toChip(item))
    }

    const tick = () => {
        if (paused.value || document.hidden || reducedMotion()) return
        rotateOne()
    }

    const stop = () => {
        clearInterval(timer)
        timer = null
    }

    watch(
        active,
        (visible) => {
            stop()
            if (!visible) return
            shuffle()
            timer = setInterval(tick, interval)
        },
        { immediate: true },
    )

    // Only reshuffle when the set of prompts really changes (not on every store update).
    watch(
        () => pool.value.map((item) => `${item.id}:${item.text}:${item.weight}`).join('|'),
        () => {
            if (active.value) shuffle()
        },
    )

    onBeforeUnmount(stop)

    return { chips, shuffle }
}
