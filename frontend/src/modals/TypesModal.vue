<template>
    <dialog id="types_modal" ref="dialogRef" class="modal" aria-labelledby="types_modal_title">
        <div class="modal-box max-w-6xl">
            <ModalHeader title-id="types_modal_title" :icon="Shapes">
                Manage Types
                <template #actions>
                    <button type="button" class="btn btn-primary btn-sm" @click="startNew">
                        <Plus :size="20" />
                        New type
                    </button>
                </template>
            </ModalHeader>

            <div class="grid gap-4 lg:grid-cols-[15rem_minmax(0,1fr)_17rem] items-start">
                <!-- Types -->
                <aside class="rounded-box border border-base-300 p-2" aria-label="Types">
                    <div class="flex items-center justify-between px-2 py-1 text-sm font-semibold">
                        <span>Types</span>
                        <span class="text-base-content/50 font-normal">{{ typesStore.active.length }}</span>
                    </div>
                    <p v-if="!typesStore.types.length" class="px-2 py-3 text-sm text-base-content/60">
                        No types yet. A type adds fields to a transaction: liters and price for Fuel, a rating for a
                        restaurant.
                    </p>
                    <ul class="flex flex-col gap-0.5">
                        <li v-for="type in typesStore.active" :key="type.id">
                            <button type="button" class="w-full flex items-center gap-2 rounded-field px-2 py-2 text-left hover:bg-base-200"
                                :class="{ 'bg-primary/15': draft.id === type.id }" :aria-current="draft.id === type.id"
                                @click="select(type)">
                                <span class="text-xl w-7 text-center shrink-0">{{ type.emoji || '🏷️' }}</span>
                                <span class="min-w-0 flex-1">
                                    <span class="block font-medium truncate">{{ type.name }}</span>
                                    <span class="block text-xs text-base-content/60">{{ countsLine(type) }}</span>
                                </span>
                                <Check v-if="draft.id === type.id" :size="16" class="shrink-0" />
                            </button>
                        </li>
                    </ul>
                    <details v-if="archived.length" class="mt-2 px-2">
                        <summary class="text-xs text-base-content/60 cursor-pointer py-1">Archived ({{ archived.length }})</summary>
                        <ul class="flex flex-col gap-1 mt-1">
                            <li v-for="type in archived" :key="type.id" class="flex items-center gap-2 text-sm">
                                <span class="w-6 text-center">{{ type.emoji }}</span>
                                <span class="flex-1 truncate text-base-content/70">{{ type.name }}</span>
                                <button type="button" class="btn btn-ghost btn-xs" :disabled="Boolean(typesStore.isLoading)"
                                    @click="typesStore.restore(type.id)">Restore</button>
                            </li>
                        </ul>
                    </details>
                </aside>

                <!-- Editor -->
                <form class="min-w-0" @submit.prevent="save">
                    <h3 class="font-semibold text-lg mb-3">{{ draft.id ? `Edit ${savedName}` : 'New type' }}</h3>

                    <div class="flex items-end gap-3 mb-3">
                        <div>
                            <span class="block text-sm text-base-content/80 mb-1">Emoji</span>
                            <button type="button" class="btn btn-square text-2xl border-base-300"
                                :aria-label="pickerOpen ? 'Close emoji picker' : 'Choose emoji'" :aria-expanded="pickerOpen"
                                @click="pickerOpen = !pickerOpen">{{ draft.emoji || '🏷️' }}</button>
                        </div>
                        <label class="block min-w-0 flex-1">
                            <span class="block text-sm text-base-content/80 mb-1">Name</span>
                            <input v-model="draft.name" type="text" class="input w-full" maxlength="64" required
                                placeholder="e.g. Fuel" />
                        </label>
                    </div>
                    <LazyEmojiPicker v-if="pickerOpen" class="mb-3" @select="(e) => { draft.emoji = e.native; pickerOpen = false }" />

                    <div class="mb-4">
                        <span class="block text-sm text-base-content/80 mb-1">Color</span>
                        <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="Color">
                            <button v-for="color in COLORS" :key="color" type="button" role="radio"
                                :aria-checked="draft.color === color" :aria-label="color"
                                class="size-7 rounded-full border-2 transition-transform duration-200"
                                :class="draft.color === color ? 'border-base-content scale-110' : 'border-transparent'"
                                :style="{ backgroundColor: color }" @click="draft.color = color"></button>
                        </div>
                    </div>

                    <div class="flex items-baseline justify-between mb-2">
                        <span class="font-semibold">Fields</span>
                        <span class="text-xs text-base-content/60">Renaming keeps the values</span>
                    </div>

                    <p v-if="!draft.fields.length" class="text-sm text-base-content/60 mb-3">
                        Add the details you want to log with this type.
                    </p>

                    <TransitionGroup tag="div" name="field-move" class="flex flex-col gap-3 mb-3">
                        <div v-for="(field, index) in keyed" :key="field.uid"
                            class="rounded-box border border-base-300 bg-base-100 shadow-sm p-3">
                            <div class="flex items-center gap-2">
                                <div class="flex flex-col">
                                    <button type="button" class="btn btn-ghost btn-xs btn-square h-5" :disabled="index === 0"
                                        :aria-label="`Move ${field.label || 'field'} up`" @click="move(index, -1)">
                                        <ChevronUp :size="14" />
                                    </button>
                                    <button type="button" class="btn btn-ghost btn-xs btn-square h-5"
                                        :disabled="index === keyed.length - 1" :aria-label="`Move ${field.label || 'field'} down`"
                                        @click="move(index, 1)">
                                        <ChevronDown :size="14" />
                                    </button>
                                </div>
                                <input v-model="draft.fields[index].label" type="text" class="input input-sm min-w-0 flex-1"
                                    maxlength="64" required placeholder="Label" :aria-label="`Field ${index + 1} label`" />
                                <select v-model="draft.fields[index].type" class="select select-sm w-32"
                                    :aria-label="`${field.label || 'Field'} kind`" @change="onKindChange(draft.fields[index])">
                                    <option v-for="kind in FIELD_KINDS" :key="kind.type" :value="kind.type">{{ kind.label }}</option>
                                </select>
                                <label v-if="field.type !== 'computed'" class="label text-sm gap-1 cursor-pointer max-sm:hidden">
                                    <input v-model="draft.fields[index].required" type="checkbox" class="checkbox checkbox-sm" />
                                    Required
                                </label>
                                <button type="button" class="btn btn-ghost btn-sm btn-square text-error"
                                    :aria-label="`Remove ${field.label || 'field'}`" @click="remove(index)">
                                    <Trash2 :size="16" />
                                </button>
                            </div>

                            <div class="pl-8 mt-2 flex flex-col gap-2 text-sm">
                                <div v-if="field.type === 'number'" class="flex flex-wrap items-center gap-2">
                                    <label class="flex items-center gap-2">Unit
                                        <input v-model="draft.fields[index].unit" type="text" class="input input-sm w-20"
                                            maxlength="16" placeholder="l, km, ₴" /></label>
                                    <label class="flex items-center gap-2">Min
                                        <input v-model="draft.fields[index].min" type="number" step="any" class="input input-sm w-24" /></label>
                                    <label class="flex items-center gap-2">Max
                                        <input v-model="draft.fields[index].max" type="number" step="any" class="input input-sm w-24" /></label>
                                </div>

                                <label v-else-if="field.type === 'rating'" class="flex items-center gap-2">Scale
                                    <select v-model.number="draft.fields[index].max" class="select select-sm w-24">
                                        <option v-for="scale in RATING_SCALES" :key="scale" :value="scale">1–{{ scale }}</option>
                                    </select>
                                </label>

                                <div v-else-if="field.type === 'select' || field.type === 'multiselect'" class="flex flex-wrap items-center gap-1.5">
                                    <span v-for="(option, o) in field.options" :key="option" class="badge badge-primary gap-1">
                                        {{ option }}
                                        <button type="button" :aria-label="`Remove ${option}`" class="opacity-70 hover:opacity-100"
                                            @click="draft.fields[index].options.splice(o, 1)">×</button>
                                    </span>
                                    <input type="text" class="input input-sm w-36" maxlength="48" placeholder="Add option +"
                                        :aria-label="`New ${field.label || 'field'} option`"
                                        @keydown.enter.prevent="addOption(draft.fields[index], $event)"
                                        @blur="addOption(draft.fields[index], $event)" />
                                </div>

                                <template v-else-if="field.type === 'computed'">
                                    <label class="flex items-center gap-2">Formula
                                        <input v-model="draft.fields[index].formula" type="text" required
                                            class="input input-sm flex-1 font-mono" maxlength="200"
                                            placeholder="liters * price_per_liter" :aria-invalid="Boolean(formulaErrors[index])" />
                                    </label>
                                    <div v-if="numericKeys.length" class="flex flex-wrap items-center gap-1 text-xs text-base-content/60">
                                        Insert:
                                        <button v-for="key in numericKeys" :key="key" type="button" class="badge badge-ghost font-mono"
                                            @click="insertKey(draft.fields[index], key)">{{ key }}</button>
                                    </div>
                                    <p v-if="formulaErrors[index]" class="text-error text-xs">{{ formulaErrors[index] }}</p>
                                    <div class="flex flex-wrap items-center gap-4">
                                        <label class="flex items-center gap-2">Unit
                                            <input v-model="draft.fields[index].unit" type="text" class="input input-sm w-20" maxlength="16" placeholder="₴" /></label>
                                        <label class="flex items-center gap-2 cursor-pointer">
                                            Fill amount
                                            <input type="checkbox" class="toggle toggle-primary toggle-sm"
                                                :checked="field.fill_amount" @change="setFill(index, $event.target.checked)" />
                                        </label>
                                    </div>
                                    <p class="text-xs text-base-content/60">
                                        <template v-if="field.fill_amount">When every input is set, the result is written to the
                                            transaction amount. You can still change the amount by hand.</template>
                                        <template v-else>Numbers, field keys, + − × ÷ and parentheses.</template>
                                    </p>
                                </template>

                                <span class="text-xs text-base-content/40 font-mono">key: {{ field.key }}</span>
                            </div>
                        </div>
                    </TransitionGroup>

                    <div v-if="draft.hidden.length" class="text-xs text-base-content/60 mb-3 flex flex-wrap items-center gap-1">
                        Removed fields keep their old values:
                        <button v-for="(field, h) in draft.hidden" :key="field.key" type="button" class="badge badge-ghost gap-1"
                            :title="`Restore ${field.label}`" @click="restoreField(h)">
                            {{ field.label }} <Undo2 :size="12" />
                        </button>
                    </div>

                    <div class="flex flex-wrap gap-1.5 mb-4">
                        <button v-for="kind in FIELD_KINDS" :key="kind.type" type="button" class="btn btn-sm btn-outline border-base-300"
                            :disabled="fieldCount >= MAX_FIELDS" @click="addField(kind.type)">
                            + {{ kind.label }}
                        </button>
                    </div>

                    <div class="mb-4">
                        <span class="block text-sm font-semibold">Default tags</span>
                        <span class="block text-xs text-base-content/60 mb-2">Applied automatically when this type is used.</span>
                        <div class="max-h-56 overflow-y-auto">
                            <TagPicker v-model="draft.default_tag_ids" />
                        </div>
                    </div>

                    <div class="flex items-center justify-between gap-2">
                        <button v-if="draft.id" type="button" class="btn btn-ghost text-error" :disabled="Boolean(typesStore.isLoading)"
                            @click="archive">
                            <Archive :size="18" />
                            Archive
                        </button>
                        <span v-else></span>
                        <button type="submit" class="btn btn-primary" :disabled="Boolean(typesStore.isLoading) || hasErrors">
                            <span v-if="typesStore.isLoading === true" class="loading loading-spinner"></span>
                            <Save v-else :size="20" />
                            Save
                        </button>
                    </div>
                </form>

                <!-- Preview -->
                <aside class="rounded-box border border-base-300 p-3 flex flex-col gap-3" aria-label="Preview">
                    <span class="text-sm font-semibold">Preview</span>
                    <span class="badge font-medium w-fit" :style="{ backgroundColor: draft.color, borderColor: draft.color, color: '#fff' }">
                        {{ draft.emoji }} {{ draft.name || 'New type' }}
                    </span>
                    <TypeFieldsPreview :type="previewType" v-model:data="previewData" bare />
                    <div class="rounded-box bg-base-200 p-3 text-sm">
                        <span class="flex items-center gap-1 text-xs font-semibold text-base-content/70 mb-1">
                            <Send :size="12" /> Log it in Telegram
                        </span>
                        <span class="italic">“{{ telegramExample }}”</span>
                    </div>
                </aside>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button>close</button>
        </form>
    </dialog>
</template>

<script setup>
import { Archive, Check, ChevronDown, ChevronUp, Plus, Save, Send, Shapes, Trash2, Undo2 } from '@lucide/vue'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import LazyEmojiPicker from '../components/LazyEmojiPicker.js'
import ModalHeader from '../components/ModalHeader.vue'
import TagPicker from '../components/TagPicker.vue'
import TypeFieldsPreview from '../components/TypeFieldsCard.vue'
import { useTransactionTypesStore } from '../services/transactionTypes.js'
import {
    evaluateFormula,
    FIELD_KINDS,
    formulaError,
    MAX_FIELDS,
    NUMERIC_KINDS,
    numericValues,
    RATING_SCALES,
    slugKey,
} from '../services/typeFields.js'

const COLORS = [
    '#f59e0b',
    '#ef4444',
    '#ec4899',
    '#a855f7',
    '#6366f1',
    '#3b82f6',
    '#06b6d4',
    '#14b8a6',
    '#22c55e',
    '#6b7280',
]

const typesStore = useTransactionTypesStore()

const dialogRef = ref(null)
const pickerOpen = ref(false)
const savedName = ref('')
const previewData = ref({})
let uid = 0

const blank = () => ({ id: null, name: '', emoji: '🏷️', color: COLORS[0], fields: [], hidden: [], default_tag_ids: [] })
const draft = ref(blank())

const archived = computed(() => typesStore.types.filter((type) => type.archived_at))

const countsLine = (type) => {
    const fields = (type.fields ?? []).filter((field) => !field.hidden).length
    const count = type.transactions_count ?? 0
    return `${fields} field${fields === 1 ? '' : 's'} · ${count} transaction${count === 1 ? '' : 's'}`
}

const toDraftField = (field, uids = {}) => ({
    uid: uids[field.key] ?? ++uid,
    saved: true,
    key: field.key,
    label: field.label,
    type: field.type,
    required: Boolean(field.required),
    unit: field.unit ?? '',
    min: field.min ?? '',
    max: field.max ?? (field.type === 'rating' ? 5 : ''),
    options: [...(field.options ?? [])],
    formula: field.formula ?? '',
    fill_amount: Boolean(field.fill_amount),
})

// Saved fields keep their key; a new field's key follows its label (unique within the type).
const keyed = computed(() => {
    const taken = [
        ...draft.value.hidden.map((f) => f.key),
        ...draft.value.fields.filter((f) => f.saved).map((f) => f.key),
    ]
    return draft.value.fields.map((field) => {
        if (field.saved) return field
        const key = slugKey(field.label || field.type, taken)
        taken.push(key)
        return { ...field, key }
    })
})

const numericKeys = computed(() => keyed.value.filter((f) => NUMERIC_KINDS.includes(f.type)).map((f) => f.key))
const formulaErrors = computed(() =>
    keyed.value.map((field) =>
        field.type === 'computed' && field.formula.trim() ? formulaError(field.formula, keyed.value) : null,
    ),
)
const hasErrors = computed(() => formulaErrors.value.some(Boolean))
const fieldCount = computed(() => draft.value.fields.length + draft.value.hidden.length)

const payloadField = (field) => {
    const out = { key: field.key, label: field.label.trim(), type: field.type, required: field.required }
    if (field.type === 'number' || field.type === 'computed') out.unit = field.unit?.trim() || null
    if (field.type === 'number') {
        out.min = field.min === '' || field.min == null ? null : Number(field.min)
        out.max = field.max === '' || field.max == null ? null : Number(field.max)
    }
    if (field.type === 'rating') out.max = Number(field.max) || 5
    if (field.type === 'select' || field.type === 'multiselect') out.options = field.options
    if (field.type === 'computed') {
        out.formula = field.formula.trim()
        out.fill_amount = field.fill_amount
    }
    return out
}

const previewType = computed(() => ({
    name: draft.value.name || 'New type',
    fields: keyed.value.filter((field) => field.label.trim()).map(payloadField),
}))

// Sample values so the preview and the Telegram line look like a real entry.
const SAMPLE_NUMBERS = ['40', '58,90', '12', '3', '7']
const samples = () => {
    const data = {}
    let n = 0
    for (const field of previewType.value.fields) {
        if (field.type === 'number') data[field.key] = SAMPLE_NUMBERS[n++ % SAMPLE_NUMBERS.length]
        else if (field.type === 'select' && field.options?.length) data[field.key] = field.options[0]
        else if (field.type === 'multiselect' && field.options?.length) data[field.key] = [field.options[0]]
        else if (field.type === 'boolean') data[field.key] = true
        else if (field.type === 'rating') data[field.key] = Math.min(4, field.max ?? 5)
        else if (field.type === 'duration') data[field.key] = 45
    }
    return data
}

const telegramExample = computed(() => {
    const data = { ...samples(), ...previewData.value }
    const parts = []
    const numbers = []
    for (const field of previewType.value.fields) {
        const value = data[field.key]
        if (value === undefined || value === null || value === '' || field.type === 'computed') continue
        if (field.type === 'number')
            numbers.push(field.unit && !/^[₴$€£]$/.test(field.unit) ? `${value} ${field.unit}` : `${value}`)
        else if (field.type === 'select') parts.push(value)
        else if (field.type === 'multiselect') parts.push([].concat(value).join(', '))
        else if (field.type === 'boolean' && value) parts.push(field.label.toLowerCase())
        else if (field.type === 'rating') parts.push(`${field.label.toLowerCase()} ${value}/${field.max ?? 5}`)
        else if (field.type === 'duration') parts.push(`${value} min`)
    }
    const head = numbers.length ? numbers.slice(0, 2).join(' at ') : ''
    const amount = evaluateFormula(
        previewType.value.fields.find((f) => f.type === 'computed' && f.fill_amount)?.formula ?? '',
        numericValues(previewType.value, data),
    )
    const tail = [...parts]
    if (!head && amount === null) tail.unshift('250')
    return `${draft.value.name || 'Type'}: ${[head, ...tail].filter(Boolean).join(', ')}`
})

// uids: field key → list key to keep, so saving doesn't re-mount (and re-animate) the field cards.
const load = (type, uids = {}) => {
    draft.value = type
        ? {
              id: type.id,
              name: type.name,
              emoji: type.emoji || '',
              color: type.color || COLORS[0],
              fields: (type.fields ?? []).filter((f) => !f.hidden).map((f) => toDraftField(f, uids)),
              hidden: (type.fields ?? []).filter((f) => f.hidden).map((f) => toDraftField(f, uids)),
              default_tag_ids: [...(type.default_tag_ids ?? [])],
          }
        : blank()
    savedName.value = type?.name ?? ''
    pickerOpen.value = false
    previewData.value = samples()
}

const select = (type) => load(type)
const startNew = () => load(null)

const addField = (type) => {
    if (fieldCount.value >= MAX_FIELDS) return
    const label = FIELD_KINDS.find((k) => k.type === type)?.label.replace(' / ', '/') ?? 'Field'
    const fills = draft.value.fields.some((f) => f.type === 'computed' && f.fill_amount)
    draft.value.fields.push({
        uid: ++uid,
        saved: false,
        key: '',
        label: type === 'computed' ? 'Total' : label,
        type,
        required: false,
        unit: '',
        min: '',
        max: type === 'rating' ? 5 : '',
        options: [],
        formula: type === 'computed' ? numericKeys.value.slice(0, 2).join(' * ') : '',
        fill_amount: type === 'computed' && !fills,
    })
}

const onKindChange = (field) => {
    if (field.type === 'rating' && !RATING_SCALES.includes(Number(field.max))) field.max = 5
    if (field.type === 'computed') field.required = false
}

const move = (index, step) => {
    const list = draft.value.fields
    const [field] = list.splice(index, 1)
    list.splice(index + step, 0, field)
}

const remove = (index) => {
    const [field] = draft.value.fields.splice(index, 1)
    if (field.saved) draft.value.hidden.push(field)
}

const restoreField = (index) => {
    if (draft.value.fields.length >= MAX_FIELDS) return
    const [field] = draft.value.hidden.splice(index, 1)
    draft.value.fields.push(field)
}

const addOption = (field, event) => {
    const option = event.target.value.trim()
    event.target.value = ''
    if (option && !field.options.some((o) => o.toLowerCase() === option.toLowerCase())) field.options.push(option)
}

const insertKey = (field, key) => {
    const text = field.formula.trim()
    field.formula = text === '' || /[-+*/(×÷]$/.test(text) ? `${text} ${key}`.trim() : `${text} * ${key}`
}

const setFill = (index, on) => {
    draft.value.fields.forEach((field, i) => {
        if (field.type === 'computed') field.fill_amount = i === index ? on : on ? false : field.fill_amount
    })
}

const save = async () => {
    const body = {
        name: draft.value.name.trim(),
        emoji: draft.value.emoji || null,
        color: draft.value.color,
        fields: keyed.value.map(payloadField),
        default_tag_ids: draft.value.default_tag_ids.slice(0, 10),
    }
    const uids = Object.fromEntries([...keyed.value, ...draft.value.hidden].map((f) => [f.key, f.uid]))
    const saved = draft.value.id ? await typesStore.update(draft.value.id, body) : await typesStore.create(body)
    if (saved) load(typesStore.byId(saved.id) ?? saved, uids)
}

const archive = async () => {
    const ok = await typesStore.archive(draft.value.id)
    if (ok) load(typesStore.active[0] ?? null)
}

// Opened from Settings / the dock: refresh counts and show the first type.
let observer
onMounted(() => {
    observer = new MutationObserver(() => {
        if (!dialogRef.value?.open) return
        typesStore.fetch()
        const current = draft.value.id && typesStore.byId(draft.value.id)
        load(current && !current.archived_at ? current : (typesStore.active[0] ?? null))
    })
    observer.observe(dialogRef.value, { attributes: true, attributeFilter: ['open'] })
})
onBeforeUnmount(() => observer?.disconnect())
</script>

<style scoped>
.field-move-move {
    transition: transform 0.2s ease;
}
</style>
