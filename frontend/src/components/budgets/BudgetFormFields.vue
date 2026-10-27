<template>
    <div v-if="!typeLocked" class="join w-full mb-4" role="group" aria-label="Budget type">
        <button type="button" class="btn join-item flex-1 gap-1" :class="{ 'btn-active btn-primary': !goal }"
            :aria-pressed="!goal" @click="form.type = 'limit'">
            <Gauge :size="18" />
            Limit
        </button>
        <button type="button" class="btn join-item flex-1 gap-1" :class="{ 'btn-active btn-primary': goal }"
            :aria-pressed="goal" @click="form.type = 'goal'">
            <Target :size="18" />
            Goal
        </button>
    </div>

    <p v-if="!typeLocked" class="text-sm text-base-content/60 -mt-2 mb-4">
        <template v-if="goal">Save toward a target: money moved to the chosen tags counts as saved.</template>
        <template v-else>Cap spending on a few tags per week, month or year.</template>
    </p>

    <input type="text" v-model="form.title" :placeholder="goal ? 'Goal name, e.g. 🛟 Emergency fund' : 'Title (optional)'"
        aria-label="Title" class="input w-full mb-4" maxlength="255" autofocus />

    <AmountField v-model="form.amount" :signed="false" :max="99_999_999.99" :autofocus="false"
        :placeholder="goal ? 'Target amount' : 'Amount'" :label="goal ? 'Target amount' : 'Amount'" />

    <template v-if="!goal">
        <select v-model="form.length" class="select w-full mb-4" required aria-label="Budget length">
            <option value="week">Weekly</option>
            <option value="month">Monthly</option>
            <option value="year">Yearly</option>
        </select>

        <div class="flex items-baseline justify-between gap-2 mb-1">
            <label for="budget-limit-start" class="text-sm text-base-content/60">Start date</label>
            <button v-if="calendarStart" type="button" class="link link-primary text-xs"
                @click="form.start_date = calendarStart">
                Start of this {{ form.length }}
            </button>
        </div>
        <input id="budget-limit-start" type="date" v-model="form.start_date" class="input w-full mb-1" :min="form.start_min || undefined"
            :max="today" aria-label="Start date" />
        <p class="text-xs text-base-content/60 mb-4">
            <template v-if="limitBucket">
                Current {{ form.length }}: {{ formatDay(limitBucket.start) }} – {{ formatDay(limitBucket.end) }}, then
                every {{ form.length }} from the {{ repeatLabel }}.
            </template>
            <template v-if="form.paused"> Saving resumes this limit from the start date.</template>
            <template v-else-if="startUnchanged"> Changing the amount, length or tags starts new settings from today
                on the same schedule; past {{ form.length }}s stay in the history.</template>
        </p>
    </template>

    <template v-if="goal">
        <div class="text-sm text-base-content/60 mb-1">Savings tags</div>
    </template>
    <TagPicker v-model="form.tag_ids" />

    <template v-if="goal">
        <div class="grid grid-cols-2 gap-3">
            <label class="block">
                <span class="block text-sm text-base-content/60 mb-1">Start date</span>
                <input type="date" v-model="form.start_date" class="input w-full mb-4" :max="startMax" aria-label="Start date" />
            </label>
            <label class="block">
                <span class="block text-sm text-base-content/60 mb-1">Deadline (optional)</span>
                <input type="date" v-model="form.target_date" class="input w-full mb-4" :min="deadlineMin"
                    aria-label="Deadline" />
            </label>
        </div>

        <div class="text-sm text-base-content/60 mb-1">Already saved before the start (optional)</div>
        <AmountField v-model="form.start_amount" :signed="false" :max="99_999_999.99" :autofocus="false"
            :required="false" placeholder="0" label="Already saved" />

        <p class="text-xs text-base-content/60 mb-2">
            Counts transactions with these tags from {{ startLabel }}: expenses (transfers into savings) add to the
            goal, income (withdrawals) takes from it.
        </p>
    </template>
</template>

<script setup>
import { Gauge, Target } from 'lucide-vue-next'
import { computed } from 'vue'
import { formatDate, nowDateStr, parseLocalDate, toLocalDateStr } from '../../services/formatters.js'
import { bucketBounds } from '../../services/limits.js'
import AmountField from '../AmountField.vue'
import TagPicker from '../TagPicker.vue'

const props = defineProps({
    // Edit modal: a limit cannot turn into a goal or back (the API keeps the type).
    typeLocked: { type: Boolean, default: false },
})

const form = defineModel({ type: Object, required: true })

const goal = computed(() => form.value.type === 'goal')

const tomorrow = computed(() => {
    const d = parseLocalDate(nowDateStr())
    d.setDate(d.getDate() + 1)

    return toLocalDateStr(d)
})

const today = computed(() => nowDateStr())

// Start: today or earlier, and not after the deadline.
const startMax = computed(() => [today.value, form.value.target_date].filter(Boolean).sort()[0])

// Deadline: a new goal needs a future one; never before the start.
const deadlineMin = computed(
    () => [props.typeLocked ? '' : tomorrow.value, form.value.start_date].filter(Boolean).sort().at(-1) || undefined,
)

const startLabel = computed(() =>
    !form.value.start_date || form.value.start_date === today.value ? 'today' : formatDate(form.value.start_date),
)

const formatDay = (dateStr) => formatDate(parseLocalDate(dateStr))

const validStart = computed(() => /^\d{4}-\d{2}-\d{2}$/.test(form.value.start_date ?? ''))

// Limits, edit modal: an untouched start keeps the budget's grid (budget.anchor_date,
// null = calendar weeks/months/years); a new start date becomes the new grid.
const startUnchanged = computed(
    () => Boolean(form.value.start_original) && form.value.start_date === form.value.start_original,
)

const limitAnchor = computed(() =>
    startUnchanged.value ? form.value.anchor_original || null : form.value.start_date || today.value,
)

const limitBucket = computed(() => {
    const start = validStart.value ? form.value.start_date : today.value
    const bucket = bucketBounds(today.value, form.value.length, limitAnchor.value)

    return { start: [bucket.start, start].sort()[1], end: bucket.end }
})

const repeatLabel = computed(() => {
    const anchor = parseLocalDate(limitAnchor.value || '2001-01-01')

    if (form.value.length === 'week') {
        return limitAnchor.value ? anchor.toLocaleDateString('en-US', { weekday: 'long' }) : 'Monday'
    }
    if (form.value.length === 'month') {
        if (!limitAnchor.value) return '1st'
        const day = anchor.getDate()
        const suffix =
            day % 10 === 1 && day !== 11
                ? 'st'
                : day % 10 === 2 && day !== 12
                  ? 'nd'
                  : day % 10 === 3 && day !== 13
                    ? 'rd'
                    : 'th'

        return `${day}${suffix}`
    }

    return limitAnchor.value ? anchor.toLocaleDateString('en-US', { month: 'long', day: 'numeric' }) : 'January 1'
})

// "Start of this month" shortcut: the calendar start, when allowed and not already set.
const calendarStart = computed(() => {
    const start = bucketBounds(today.value, form.value.length, null).start

    if (start === form.value.start_date || (form.value.start_min && start < form.value.start_min)) return null

    return start
})
</script>
