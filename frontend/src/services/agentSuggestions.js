import { onBeforeUnmount, ref, watch } from 'vue'

// Starter prompts for the assistant's empty state, per tab (screen context comes from App.vue).
// Only things the web assistant's tools can do: summaries, budgets, recurring rules, comparisons,
// report / PDF cards, charts, tag create / rename, transaction search / add / edit.

const GENERIC = [
    'How much did I spend this month?',
    'Income vs expenses for the last 6 months',
    'Chart of spending by tag this month',
    'What were my biggest expenses this month?',
    'Am I on track with my budgets?',
    "What's my average daily spend this month?",
    'Compare this month with last month',
    'What recurring charges are coming up?',
    'Show my recurring payments',
    'Add -350 groceries today',
    'Add -120 taxi yesterday',
    'Find all Bolt rides last month',
    'PDF report for last month',
    'Show my last 10 transactions',
    'Will I stay within budget by month end?',
]

const BY_TAB = {
    analytics: [
        'Compare this period with the previous one',
        'What changed most in my spending?',
        'PDF report for this period',
        'Biggest expenses in this period',
        'Which tag grew the most vs last period?',
        'Average daily spend in this period',
        'Chart of spending by tag for this period',
        'Income vs expenses for this period',
        'Where did most of my money go?',
        'Compare this quarter with last',
        'Summarize my finances this month',
        'How much did I save this month?',
    ],
    calendar: [
        'Upcoming charges this week',
        'Upcoming charges for the next 30 days',
        'Which day did I spend the most here?',
        'Add -350 groceries today',
        'Add -80 coffee yesterday',
        'Show my recurring payments',
        'Add monthly rent -12000 on the 1st',
        'Pause my Netflix subscription',
        'How much do subscriptions cost per month?',
        'Find all Bolt rides last month',
        'Show my last 10 transactions',
    ],
    budgets: [
        'How are my budgets doing?',
        'Am I on track with my budgets?',
        'How much can I spend per day?',
        'Will I stay within budget by month end?',
        'Which budget is at risk?',
        'Create a 5000 monthly budget for groceries',
        'Raise my groceries budget to 6000',
        'Pause my budget',
        'Resume a paused budget',
        'Show my budgets',
        'Where can I cut spending this month?',
        'Chart of spending by tag this month',
    ],
    tags: [
        'Compare spending by tag with last month',
        'Which tag grew the most?',
        'Chart of spending by tag',
        'Top 5 tags by spending this month',
        'Which tags did I spend less on?',
        'How much did I spend on groceries?',
        'What share of my spending is food?',
        'Show my tags',
        'Create tags Pets and Gifts',
        'Rename tag Food to Groceries',
        'PDF report for groceries this month',
    ],
    money_flow: [
        'Income vs expenses vs the previous period',
        'Where did my money go here?',
        'PDF report for this period',
        'How much did I save this period?',
        'What was my biggest income this period?',
        'Chart of income vs expenses for 6 months',
        'Is my net positive this month?',
        'Compare this month with last month',
        'Biggest expenses in this period',
        'Average daily spend in this period',
        'What recurring charges are coming up?',
    ],
    balance_trend: [
        'How did my balance change in this period?',
        'Upcoming charges for the next 30 days',
        'Compare this quarter with last',
        'Balance trend chart for this year',
        'How much did I save this year?',
        'Will I stay within budget by month end?',
        'Which recurring charges cost the most?',
        'Income vs expenses for the last 6 months',
        'Summarize my finances this month',
        'What recurring charges are coming up?',
    ],
    table: [
        'Summarize the transactions in this period',
        'Biggest expenses in this period',
        'PDF report for this period',
        'Find all Bolt rides last month',
        'Find transactions with "coffee" this month',
        'Show my last 10 transactions',
        'How many transactions did I make this month?',
        'Average daily spend in this period',
        'Add -350 groceries yesterday',
        'Add +25000 salary today',
        'Change my last transaction to -400',
        'Delete my last transaction',
    ],
}

export const suggestionPool = (tab) => BY_TAB[tab] ?? GENERIC

const pick = (list) => list[Math.floor(Math.random() * list.length)]

// Fisher–Yates on a copy, first n.
const sample = (list, n) => {
    const copy = [...list]
    for (let i = copy.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1))
        ;[copy[i], copy[j]] = [copy[j], copy[i]]
    }
    return copy.slice(0, n)
}

const reducedMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false

/**
 * Three random chips from the pool (no repeats), reshuffled whenever they become visible again or the
 * pool changes. While visible, one chip is swapped every `interval` ms unless `paused` is true, the
 * browser tab is hidden, or the user prefers reduced motion.
 * Each chip is { key, text }; the key changes on every swap so a <Transition> can animate it.
 */
export function useSuggestionChips({ pool, active, paused, count = 3, interval = 7000 }) {
    const chips = ref([])
    let seq = 0
    let lastSlot = -1
    let timer = null

    const shuffle = () => {
        chips.value = sample(pool.value, count).map((text) => ({ key: seq++, text }))
        lastSlot = -1
    }

    const rotateOne = () => {
        const shown = new Set(chips.value.map((chip) => chip.text))
        const fresh = pool.value.filter((text) => !shown.has(text))
        if (!fresh.length || !chips.value.length) return
        const slots = chips.value.map((_, index) => index).filter((index) => index !== lastSlot)
        const slot = pick(slots.length ? slots : [0])
        lastSlot = slot
        chips.value.splice(slot, 1, { key: seq++, text: pick(fresh) })
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

    watch(pool, shuffle)

    onBeforeUnmount(stop)

    return { chips, shuffle }
}
