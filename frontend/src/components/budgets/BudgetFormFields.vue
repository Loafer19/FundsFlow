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

    <div v-if="!goal" class="mb-3">
        <div class="text-sm text-base-content/60 mb-1">Frequency</div>
        <SegmentedControl v-model="form.length" block size="sm" label="Budget length" :options="lengthOptions" />
    </div>

    <!-- One row of two fields for both types, so switching Limit/Goal swaps fields in place. -->
    <div class="grid grid-cols-2 gap-3" :class="goal ? 'mb-4' : 'mb-1'">
        <div class="min-w-0">
            <label :for="startId" class="block text-sm text-base-content/60 mb-1">Start date</label>
            <DateField v-model="form.start_date" :input-id="startId" aria-label="Start date"
                :min="goal ? '' : (form.start_min || '')" :max="goal ? startMax : today"
                @update:model-value="form.start_auto = false" />
        </div>
        <div v-if="goal" class="min-w-0">
            <div class="text-sm text-base-content/60 mb-1">Deadline (optional)</div>
            <DateField v-model="form.target_date" clearable aria-label="Deadline" placeholder="No deadline"
                :min="deadlineMin || ''" />
        </div>
    </div>

    <p v-if="!goal" class="text-xs text-base-content/60 mb-4">
        Now {{ formatShort(limitBucket.start) }} – {{ formatShort(limitBucket.end) }} · {{ resetText }}<template
            v-if="form.paused"> · resumes from the start date</template>
        <template v-if="calendarStart">
            ·
            <button type="button" class="link link-primary" @click="form.start_date = calendarStart; form.start_auto = false">
                Start of this {{ form.length }}
            </button>
        </template>
    </p>

    <template v-if="jarLinked">
        <p class="text-sm text-base-content/70 mb-4">
            <span class="badge badge-sm badge-outline mr-1 align-middle">{{ jarLinked.linked ? 'Mono jar' : 'Unlinked jar' }}</span>
            Progress follows the jar balance in monobank. You can still set a target and a deadline.
        </p>
    </template>
    <template v-else>
        <div v-if="!goal" class="mb-3">
            <div class="flex items-center gap-1 text-sm text-base-content/60 mb-1">
                <span>Limit by</span>
                <span class="tooltip tooltip-right before:max-w-56 before:text-left" :data-tip="scopeHint" tabindex="0"
                    role="img" :aria-label="scopeHint">
                    <Info :size="14" />
                </span>
            </div>
            <SegmentedControl v-model="form.limit_by" block size="sm" label="Limit by" :options="limitByOptions" />
        </div>

        <template v-if="!goal && form.limit_by !== 'tags'">
            <div v-if="!availableTypes.length" class="text-sm text-base-content/60 mb-4">
                No transaction types yet. Create one in Settings → Transaction types first.
            </div>
            <div v-else class="flex flex-col gap-3 mb-4">
                <div>
                    <label :for="typeId" class="block text-sm text-base-content/60 mb-1">Transaction type</label>
                    <select :id="typeId" v-model="form.scope_type_id" class="select w-full" aria-label="Transaction type">
                        <option value="" disabled>Pick a type…</option>
                        <option v-for="t in availableTypes" :key="t.id" :value="t.id">
                            {{ t.emoji ? `${t.emoji} ` : '' }}{{ t.name }}{{ t.archived_at ? ' (archived)' : '' }}
                        </option>
                    </select>
                </div>
                <div v-if="form.limit_by === 'field_sum'">
                    <label :for="fieldId" class="block text-sm text-base-content/60 mb-1">Number field</label>
                    <select v-if="numericFields.length" :id="fieldId" v-model="form.scope_field" class="select w-full"
                        aria-label="Number field">
                        <option value="" disabled>Pick a field…</option>
                        <option v-for="f in numericFields" :key="f.key" :value="f.key">
                            {{ f.label }}{{ f.unit ? ` (${f.unit})` : '' }}
                        </option>
                    </select>
                    <p v-else class="text-xs text-base-content/60">
                        This type has no number fields yet.
                    </p>
                </div>
            </div>
        </template>
        <template v-else>
            <div class="flex items-center gap-1 text-sm text-base-content/60 mb-1">
                <span>Tags</span>
                <span class="tooltip tooltip-right before:max-w-56 before:text-left" :data-tip="tagsHint" tabindex="0"
                    role="img" :aria-label="tagsHint">
                    <Info :size="14" />
                </span>
            </div>
            <TagPicker v-model="form.tag_ids" />
        </template>
    </template>

    <details v-if="goal && !jarLinked" class="mb-2" :open="showMore" @toggle="showMore = $event.target.open">
        <summary class="text-sm text-base-content/60 cursor-pointer mb-2 rounded-field px-1 -mx-1 py-0.5 hover:bg-base-200">More options</summary>
        <label class="block">
            <span class="block text-sm text-base-content/60 mb-1">Already saved</span>
            <AmountField v-model="form.start_amount" :signed="false" :max="99_999_999.99" :autofocus="false"
                :required="false" placeholder="0" label="Already saved" />
        </label>
    </details>
</template>

<script setup>
import { Gauge, Info, Target } from '@lucide/vue'
import { computed, ref, useId, watch } from 'vue'
import { nowDateStr, parseLocalDate } from '../../services/formatters.js'
import { bucketBounds } from '../../services/limits.js'
import { useTransactionTypesStore } from '../../services/transactionTypes.js'
import AmountField from '../AmountField.vue'
import DateField from '../DateField.vue'
import SegmentedControl from '../SegmentedControl.vue'
import TagPicker from '../TagPicker.vue'

defineProps({
    // Edit modal: a limit cannot turn into a goal or back (the API keeps the type).
    typeLocked: { type: Boolean, default: false },
})

const form = defineModel({ type: Object, required: true })
const typesStore = useTransactionTypesStore()

const lengthOptions = [
    { value: 'week', label: 'Weekly' },
    { value: 'month', label: 'Monthly' },
    { value: 'year', label: 'Yearly' },
]

const limitByOptions = [
    { value: 'tags', label: 'Tags' },
    { value: 'type', label: 'Type' },
    { value: 'field_sum', label: 'Field' },
]

const goal = computed(() => form.value.type === 'goal')
const jarLinked = computed(() => form.value.monobank_jar ?? null)
const today = computed(() => nowDateStr())

const NUMERIC_FIELD_TYPES = new Set(['number', 'computed'])

const visibleNumericFields = (type) => (type?.fields ?? []).filter((f) => !f.hidden && NUMERIC_FIELD_TYPES.has(f.type))

const availableTypes = computed(() => {
    const active = typesStore.active
    const currentId = Number(form.value.scope_type_id)
    if (!currentId) return active
    if (active.some((t) => t.id === currentId)) return active
    const existing = typesStore.byId(currentId)
    return existing ? [...active, existing] : active
})

const selectedType = computed(() => typesStore.byId(form.value.scope_type_id))

const numericFields = computed(() => {
    const fields = visibleNumericFields(selectedType.value)
    const currentKey = form.value.scope_field
    if (!currentKey || fields.some((f) => f.key === currentKey)) return fields
    const hidden = (selectedType.value?.fields ?? []).find(
        (f) => f.key === currentKey && NUMERIC_FIELD_TYPES.has(f.type),
    )
    return hidden ? [...fields, hidden] : fields
})

watch(
    () => [form.value.limit_by, form.value.scope_type_id],
    ([limitBy]) => {
        if (goal.value || limitBy === 'tags') return

        if (!form.value.scope_type_id && availableTypes.value.length) {
            const preferred =
                limitBy === 'field_sum'
                    ? (availableTypes.value.find((t) => visibleNumericFields(t).length > 0) ?? availableTypes.value[0])
                    : availableTypes.value[0]
            form.value.scope_type_id = preferred.id
            return
        }

        if (limitBy === 'field_sum') {
            const fields = numericFields.value
            if (!fields.some((f) => f.key === form.value.scope_field)) {
                form.value.scope_field = fields[0]?.key ?? ''
            }
        }
    },
    { immediate: true },
)

// Start: today or earlier, and not after the deadline.
const startMax = computed(() => [today.value, form.value.target_date].filter(Boolean).sort()[0])

// Deadline: today or later (the API rejects past ones), never before the start. Editing a goal
// whose deadline already passed keeps it valid while it stays untouched.
const deadlineKept = computed(
    () => Boolean(form.value.target_original) && form.value.target_date === form.value.target_original,
)
const deadlineMin = computed(
    () => [deadlineKept.value ? '' : today.value, form.value.start_date].filter(Boolean).sort().at(-1) || undefined,
)

const startId = useId()
const typeId = useId()
const fieldId = useId()

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

const ordinal = (n) => {
    const tail = n % 100 >= 11 && n % 100 <= 13 ? 'th' : { 1: 'st', 2: 'nd', 3: 'rd' }[n % 10] || 'th'
    return `${n}${tail}`
}

// When the next period starts: "resets every Monday", "resets on the 14th", "resets every Oct 14".
const resetText = computed(() => {
    const next = parseLocalDate(limitBucket.value.end)
    next.setDate(next.getDate() + 1)
    if (form.value.length === 'week') return `resets every ${next.toLocaleDateString('en-US', { weekday: 'long' })}`
    if (form.value.length === 'year')
        return `resets every ${next.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}`
    return `resets on the ${ordinal(next.getDate())}`
})

const tagsHint = computed(() =>
    goal.value
        ? 'Transactions with these tags since the start date count: expenses (transfers into savings) add to the goal, income (withdrawals) takes from it'
        : 'Spending on these tags counts toward the limit',
)

const scopeHint = computed(() => {
    if (form.value.limit_by === 'type') return 'Count all expenses with the picked transaction type'
    if (form.value.limit_by === 'field_sum') return 'Sum a numeric field (like Tips or Liters) on expenses of that type'
    return 'Count expenses carrying any of the picked tags (or their child tags)'
})

const validStart = computed(() => /^\d{4}-\d{2}-\d{2}$/.test(form.value.start_date ?? ''))

// Limits, edit modal: an untouched start keeps the budget's grid (budget.anchor_date,
// null = calendar weeks/months/years); a new start date becomes the new grid.
const startUnchanged = computed(
    () => Boolean(form.value.start_original) && form.value.start_date === form.value.start_original,
)

const limitAnchor = computed(() =>
    startUnchanged.value ? form.value.anchor_original || null : form.value.start_date || today.value,
)

// Edit modal, untouched start: the active period counts from its own first day even after an
// edit (no earlier than the start of the limit's run, chain_start); a new start counts from itself.
const limitBucket = computed(() => {
    const start =
        startUnchanged.value && form.value.chain_start
            ? form.value.chain_start
            : validStart.value
              ? form.value.start_date
              : today.value
    const bucket = bucketBounds(today.value, form.value.length, limitAnchor.value)

    return { start: [bucket.start, start].sort()[1], end: bucket.end }
})

// New budgets: until the user picks a start date, limits start at the beginning of the current
// calendar week/month/year (spending already made this period counts) and goals today.
watch(
    () => [form.value.type, form.value.length, form.value.start_auto],
    () => {
        if (!form.value.start_auto) return

        form.value.start_date = goal.value ? today.value : bucketBounds(today.value, form.value.length, null).start
    },
    { immediate: true },
)

// "Start of this month" shortcut: the calendar start, when allowed and not already set.
const calendarStart = computed(() => {
    const start = bucketBounds(today.value, form.value.length, null).start

    if (start === form.value.start_date || (form.value.start_min && start < form.value.start_min)) return null

    return start
})
</script>
