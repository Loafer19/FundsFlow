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

        <label class="label cursor-pointer justify-start gap-2 mb-4">
            <input type="checkbox" v-model="form.align_to_calendar" class="checkbox checkbox-sm" />
            <span class="label-text">Start from the beginning of this {{ form.length }}</span>
        </label>
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
</script>
