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

    <input type="text" v-model="form.title" :placeholder="goal ? 'Goal name, e.g. 🛟 Emergency fund' : 'Title (optional)'"
        aria-label="Title" class="input w-full mb-4" maxlength="255" autofocus />

    <AmountField v-model="form.amount" :signed="false" :max="99_999_999.99" :autofocus="false"
        :placeholder="goal ? 'Target amount' : 'Amount'" :label="goal ? 'Target amount' : 'Amount'" />

    <!-- One row of two fields for both types, so switching Limit/Goal swaps fields in place. -->
    <div class="grid grid-cols-2 gap-3" :class="goal ? 'mb-4' : 'mb-1'">
        <label v-if="!goal" class="block min-w-0">
            <span class="block text-sm text-base-content/60 mb-1">Frequency</span>
            <select v-model="form.length" class="select w-full" required aria-label="Budget length">
                <option value="week">Weekly</option>
                <option value="month">Monthly</option>
                <option value="year">Yearly</option>
            </select>
        </label>
        <div class="min-w-0">
            <label :for="startId" class="block text-sm text-base-content/60 mb-1">Start date</label>
            <input :id="startId" type="date" v-model="form.start_date" class="input w-full"
                :min="goal ? undefined : form.start_min || undefined" :max="goal ? startMax : today"
                aria-label="Start date" />
        </div>
        <label v-if="goal" class="block min-w-0">
            <span class="block text-sm text-base-content/60 mb-1">Deadline (optional)</span>
            <input type="date" v-model="form.target_date" class="input w-full" :min="deadlineMin" aria-label="Deadline" />
        </label>
    </div>

    <p v-if="!goal" class="text-xs text-base-content/60 mb-4">
        {{ formatShort(limitBucket.start) }} – {{ formatShort(limitBucket.end) }}, then {{ frequencyWord }}<template
            v-if="form.paused"> · resumes from the start date</template>
        <template v-if="calendarStart">
            ·
            <button type="button" class="link link-primary" @click="form.start_date = calendarStart">
                Start of this {{ form.length }}
            </button>
        </template>
    </p>

    <div class="flex items-center gap-1 text-sm text-base-content/60 mb-1">
        <span>Tags</span>
        <span class="tooltip tooltip-right before:max-w-56 before:text-left" :data-tip="tagsHint" tabindex="0"
            role="img" :aria-label="tagsHint">
            <Info :size="14" />
        </span>
    </div>
    <TagPicker v-model="form.tag_ids" />

    <details v-if="goal" class="mb-2" :open="showMore" @toggle="showMore = $event.target.open">
        <summary class="text-sm text-base-content/60 cursor-pointer mb-2">More options</summary>
        <label class="block">
            <span class="block text-sm text-base-content/60 mb-1">Already saved</span>
            <AmountField v-model="form.start_amount" :signed="false" :max="99_999_999.99" :autofocus="false"
                :required="false" placeholder="0" label="Already saved" />
        </label>
    </details>
</template>

<script setup>
import { Gauge, Info, Target } from 'lucide-vue-next'
import { computed, ref, useId, watch } from 'vue'
import { nowDateStr, parseLocalDate, toLocalDateStr } from '../../services/formatters.js'
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

const startId = useId()

// Goal "More options" (already saved): open when editing a goal that has one.
const showMore = ref(false)

watch(
    () => form.value.id,
    () => {
        showMore.value = Boolean(Number(form.value.start_amount))
    },
    { immediate: true },
)

const formatShort = (dateStr) => parseLocalDate(dateStr).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })

const frequencyWord = computed(() => ({ week: 'weekly', month: 'monthly', year: 'yearly' })[form.value.length] ?? '')

const tagsHint = computed(() =>
    goal.value
        ? 'Transactions with these tags since the start date count: expenses (transfers into savings) add to the goal, income (withdrawals) takes from it.'
        : 'Spending on these tags counts toward the limit.',
)

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

// "Start of this month" shortcut: the calendar start, when allowed and not already set.
const calendarStart = computed(() => {
    const start = bucketBounds(today.value, form.value.length, null).start

    if (start === form.value.start_date || (form.value.start_min && start < form.value.start_min)) return null

    return start
})
</script>
