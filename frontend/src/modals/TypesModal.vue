<template>
    <dialog id="types_modal" ref="dialogRef" class="modal" aria-labelledby="types_modal_title"
        @cancel.prevent="requestClose" @click.capture="onClickCapture">
        <div class="modal-box max-w-6xl overflow-x-hidden">
            <ModalHeader title-id="types_modal_title" :icon="Shapes">
                Manage Types
                <template #actions>
                    <div v-if="draft.id" class="tooltip tooltip-bottom" :data-tip="`Analytics for ${savedName}`">
                        <button type="button" class="btn btn-ghost btn-sm max-sm:btn-square"
                            :aria-label="`Analytics for ${savedName}`" @click="openAnalytics(draft.id)">
                            <ChartPie :size="18" />
                            <span class="max-sm:hidden">Analytics</span>
                        </button>
                    </div>
                    <button type="button" class="btn btn-primary btn-sm max-sm:btn-square" aria-label="New type"
                        @click="startNew">
                        <Plus :size="20" />
                        <span class="max-sm:hidden">New type</span>
                    </button>
                </template>
            </ModalHeader>

            <EmptyState v-if="!hasAnyType && !creating" :icon="Shapes" title="No types yet"
                description="A type adds fields to a transaction: liters and price for Fuel, a rating for a restaurant."
                action-label="New type" @action="startNew">
                <TypeTemplates :templates="availableTemplates" @pick="applyTemplate" />
            </EmptyState>

            <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-[15rem_minmax(0,1fr)_17rem] items-start">
                <!-- Types: a sidebar on wide screens, a row of chips on narrow ones -->
                <aside class="lg:rounded-box lg:border lg:border-base-300 lg:p-2" aria-label="Types">
                    <div class="flex items-center justify-between px-2 py-1 text-sm font-semibold max-lg:hidden">
                        <span>Types</span>
                        <span class="text-base-content/50 font-normal">{{ typesStore.active.length }}</span>
                    </div>
                    <p v-if="!typesStore.active.length" class="px-2 py-3 text-sm text-base-content/60">
                        All types are archived. Restore one below or start a new type.
                    </p>
                    <ul class="flex gap-0.5 lg:flex-col max-lg:gap-1.5 max-lg:overflow-x-auto max-lg:pb-1">
                        <li v-for="type in typesStore.active" :key="type.id" class="max-lg:shrink-0">
                            <button type="button"
                                class="flex items-center gap-2 rounded-field text-left transition-colors duration-200 hover:bg-base-200 lg:w-full lg:px-2 lg:py-2 max-lg:rounded-full max-lg:border max-lg:border-base-300 max-lg:px-3 max-lg:py-1.5 max-lg:whitespace-nowrap"
                                :class="draft.id === type.id ? 'bg-primary/15 max-lg:border-primary' : ''"
                                :aria-current="draft.id === type.id" @click="select(type)">
                                <span class="size-2 rounded-full shrink-0" :class="type.color ? '' : 'bg-base-content/30'"
                                    :style="type.color ? { backgroundColor: type.color } : null" aria-hidden="true"></span>
                                <span class="text-xl w-7 text-center shrink-0 max-lg:w-auto max-lg:text-base">{{ type.emoji || '🏷️' }}</span>
                                <span class="min-w-0 flex-1">
                                    <span class="block font-medium truncate">{{ type.name }}</span>
                                    <span class="block text-xs text-base-content/60 max-lg:hidden">{{ countsLine(type) }}</span>
                                </span>
                                <Check v-if="draft.id === type.id" :size="16" class="shrink-0 max-lg:hidden" />
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
                <form ref="formRef" class="min-w-0" @submit.prevent="save">
                    <div class="flex items-center gap-3 mb-3">
                        <h3 class="font-semibold text-lg min-w-0 truncate">{{ draft.id ? `Edit ${savedName}` : 'New type' }}</h3>
                        <span v-if="dirty" class="inline-flex items-center gap-1.5 text-xs text-warning shrink-0" role="status">
                            <span class="size-2 rounded-full bg-warning" aria-hidden="true"></span>
                            Unsaved
                        </span>
                    </div>

                    <TypeTemplates v-if="showTemplates" class="mb-4" left :templates="availableTemplates"
                        @pick="applyTemplate" />

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
                            <div v-for="(color, i) in COLORS" :key="color.value" class="tooltip" :data-tip="color.name">
                                <button type="button" role="radio" :aria-checked="draft.color === color.value"
                                    :aria-label="color.name" :tabindex="swatchTabbable(color, i) ? 0 : -1"
                                    class="size-7 rounded-full border-2 transition-transform duration-200"
                                    :class="draft.color === color.value ? 'border-base-content scale-110' : 'border-transparent'"
                                    :style="{ backgroundColor: color.value }" @click="draft.color = color.value"
                                    @keydown="onSwatchKey($event, i)"></button>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-baseline justify-between mb-2">
                        <span class="font-semibold">Fields</span>
                        <span class="text-xs text-base-content/60">Renaming keeps the values</span>
                    </div>

                    <p v-if="!draft.fields.length" class="text-sm text-base-content/60 mb-3">
                        Add the details you want to log with this type.
                    </p>

                    <TransitionGroup tag="div" name="field-move"
                        class="mb-3 border-y border-base-300 divide-y divide-base-300" :class="{ 'border-y-0': !draft.fields.length }">
                        <div v-for="(field, index) in keyed" :key="field.uid" class="py-3">
                            <div class="grid grid-cols-[auto_minmax(0,1fr)_auto] sm:grid-cols-[auto_minmax(0,1fr)_8rem_auto_auto] items-center gap-x-2 gap-y-2">
                                <div class="flex flex-col max-sm:row-span-2 max-sm:row-start-1 max-sm:col-start-1">
                                    <button type="button" class="btn btn-ghost btn-square min-h-0 max-sm:size-8 sm:h-[18px] sm:w-7"
                                        :disabled="index === 0" :aria-label="`Move ${field.label || 'field'} up`"
                                        @click="move(index, -1)">
                                        <ChevronUp :size="14" />
                                    </button>
                                    <button type="button" class="btn btn-ghost btn-square min-h-0 max-sm:size-8 sm:h-[18px] sm:w-7"
                                        :disabled="index === keyed.length - 1" :aria-label="`Move ${field.label || 'field'} down`"
                                        @click="move(index, 1)">
                                        <ChevronDown :size="14" />
                                    </button>
                                </div>
                                <div class="tooltip tooltip-bottom w-full max-sm:col-start-2 max-sm:row-start-1"
                                    :data-tip="`key: ${field.key}`">
                                    <input v-model="draft.fields[index].label" type="text" class="input input-sm w-full"
                                        maxlength="64" required placeholder="Label" :aria-label="`Field ${index + 1} label`" />
                                </div>
                                <select v-model="draft.fields[index].type" class="select select-sm w-full max-sm:col-start-2 max-sm:row-start-2"
                                    :aria-label="`${field.label || 'Field'} kind`" @change="onKindChange(draft.fields[index])">
                                    <option v-for="kind in FIELD_KINDS" :key="kind.type" :value="kind.type">{{ kind.label }}</option>
                                </select>
                                <label v-if="field.type !== 'computed'"
                                    class="label text-sm gap-1 cursor-pointer max-sm:col-start-3 max-sm:row-start-2">
                                    <input v-model="draft.fields[index].required" type="checkbox" class="checkbox checkbox-sm" />
                                    Required
                                </label>
                                <button type="button"
                                    class="btn btn-ghost btn-sm btn-square text-error tooltip max-sm:col-start-3 max-sm:row-start-1 max-sm:justify-self-end"
                                    data-tip="Remove field" :aria-label="`Remove ${field.label || 'field'}`" @click="remove(index)">
                                    <Trash2 :size="16" />
                                </button>
                            </div>

                            <div v-if="hasExtras(field)" class="pl-10 sm:pl-9 mt-2 flex flex-col gap-2 text-sm">
                                <template v-if="field.type === 'number'">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <label class="flex items-center gap-2">Unit
                                            <input v-model="draft.fields[index].unit" type="text" class="input input-sm w-20"
                                                maxlength="16" placeholder="l, km, ₴" /></label>
                                        <button type="button" class="btn btn-ghost btn-xs gap-1"
                                            :aria-expanded="limitsShown(field)" @click="toggleLimits(field)">
                                            <ChevronRight :size="14" class="transition-transform duration-200"
                                                :class="{ 'rotate-90': limitsShown(field) }" />
                                            Limits
                                        </button>
                                    </div>
                                    <div v-if="limitsShown(field)" class="flex flex-wrap items-center gap-2">
                                        <label class="flex items-center gap-2">Min
                                            <input v-model="draft.fields[index].min" type="number" step="any" class="input input-sm w-24" /></label>
                                        <label class="flex items-center gap-2">Max
                                            <input v-model="draft.fields[index].max" type="number" step="any" class="input input-sm w-24" /></label>
                                    </div>
                                </template>

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
                                            class="input input-sm flex-1 min-w-0 font-mono" maxlength="200"
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
                            </div>
                        </div>
                    </TransitionGroup>

                    <div v-if="draft.hidden.length" class="text-xs text-base-content/60 mb-3 flex flex-wrap items-center gap-1">
                        Removed fields keep their old values:
                        <button v-for="(field, h) in draft.hidden" :key="field.key" type="button" class="badge badge-ghost gap-1 tooltip"
                            :data-tip="`Restore ${field.label}`" @click="restoreField(h)">
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
                        <div class="flex items-baseline justify-between">
                            <span class="block text-sm font-semibold">Default tags</span>
                            <span class="text-xs" :class="tooManyTags ? 'text-error' : 'text-base-content/60'">
                                {{ draft.default_tag_ids.length }}/{{ MAX_DEFAULT_TAGS }}
                            </span>
                        </div>
                        <span class="block text-xs text-base-content/60 mb-2">Applied automatically when this type is used.</span>
                        <div v-if="draftTags.length" class="flex flex-wrap gap-1.5 mb-2">
                            <span v-for="tag in draftTags" :key="tag.id" class="badge badge-info badge-soft gap-1 pr-0.5">
                                {{ tag.emoji }} {{ tag.title }}
                                <button type="button" class="btn btn-ghost btn-circle size-5 min-h-0 p-0"
                                    :aria-label="`Remove ${tag.title}`" @click="removeTag(tag.id)">
                                    <X :size="12" />
                                </button>
                            </span>
                        </div>
                        <details class="rounded-box border border-base-300">
                            <summary class="cursor-pointer px-3 py-2 text-sm">
                                {{ draftTags.length ? 'Change tags' : 'Choose tags' }}
                            </summary>
                            <div class="px-3 pt-1">
                                <TagPicker v-model="draft.default_tag_ids" />
                            </div>
                        </details>
                    </div>

                    <details v-if="!wide" class="rounded-box border border-base-300 mb-4">
                        <summary class="cursor-pointer px-3 py-2 text-sm font-semibold">Preview</summary>
                        <div class="p-3 pt-1">
                            <TypePreview :color="draft.color" :emoji="draft.emoji" :name="draft.name" :type="previewType"
                                :telegram-example="telegramExample" v-model:data="previewData" />
                        </div>
                    </details>

                    <div
                        class="sticky bottom-0 z-10 flex flex-wrap items-center justify-between gap-x-2 gap-y-1 border-t border-base-300 bg-base-100 py-3 max-lg:-mx-6 max-lg:px-6 max-lg:-mb-6">
                        <p v-if="blockMessage" class="basis-full text-xs text-error" role="alert">{{ blockMessage }}</p>
                        <button v-if="draft.id" type="button" class="btn btn-ghost text-error" :disabled="Boolean(typesStore.isLoading)"
                            @click="archive">
                            <Archive :size="18" />
                            Archive
                        </button>
                        <span v-else></span>
                        <div class="tooltip tooltip-left" :data-tip="saveTip">
                            <button type="submit" class="btn btn-primary" :disabled="!canSave">
                                <span v-if="typesStore.isLoading === true" class="loading loading-spinner"></span>
                                <Save v-else :size="20" />
                                Save
                            </button>
                        </div>
                    </div>
                </form>

                <!-- Preview -->
                <aside v-if="wide" class="rounded-box border border-base-300 p-3 flex flex-col gap-3" aria-label="Preview">
                    <span class="text-sm font-semibold">Preview</span>
                    <TypePreview :color="draft.color" :emoji="draft.emoji" :name="draft.name" :type="previewType"
                        :telegram-example="telegramExample" v-model:data="previewData" />
                </aside>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button>close</button>
        </form>
    </dialog>
</template>

<script setup>
import {
    Archive,
    ChartPie,
    Check,
    ChevronDown,
    ChevronRight,
    ChevronUp,
    Plus,
    Save,
    Shapes,
    Trash2,
    Undo2,
    X,
} from '@lucide/vue'
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import EmptyState from '../components/EmptyState.vue'
import LazyEmojiPicker from '../components/LazyEmojiPicker.js'
import ModalHeader from '../components/ModalHeader.vue'
import TagPicker from '../components/TagPicker.vue'
import TypePreview from '../components/TypePreview.vue'
import TypeTemplates from '../components/TypeTemplates.vue'
import { askConfirm, confirmDialog } from '../services/confirmDialog.js'
import { useTagsStore } from '../services/tags.js'
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
import { TYPE_TEMPLATES } from '../services/typeTemplates.js'

const COLORS = [
    { value: '#f59e0b', name: 'Amber' },
    { value: '#ef4444', name: 'Red' },
    { value: '#ec4899', name: 'Pink' },
    { value: '#a855f7', name: 'Purple' },
    { value: '#6366f1', name: 'Indigo' },
    { value: '#3b82f6', name: 'Blue' },
    { value: '#06b6d4', name: 'Cyan' },
    { value: '#14b8a6', name: 'Teal' },
    { value: '#22c55e', name: 'Green' },
    { value: '#6b7280', name: 'Gray' },
]
const MAX_DEFAULT_TAGS = 10
// Kinds that have settings under their row; text, date, duration and yes/no are just the row.
const EXTRA_KINDS = ['number', 'rating', 'select', 'multiselect', 'computed']

const typesStore = useTransactionTypesStore()
const tagsStore = useTagsStore()

const dialogRef = ref(null)
const formRef = ref(null)
const pickerOpen = ref(false)
const savedName = ref('')
const previewData = ref({})
const creating = ref(false)
const snapshot = ref('')
// Field uids whose Min / Max are showing (they open on their own when a limit is set).
const limitsOpen = reactive(new Set())
let uid = 0

// The preview sits in a third column on wide screens and folds into the form on narrow ones.
const wideQuery = window.matchMedia('(min-width: 1024px)')
const wide = ref(wideQuery.matches)
const onWideChange = (event) => {
    wide.value = event.matches
}

const blank = () => ({
    id: null,
    name: '',
    emoji: '🏷️',
    color: COLORS[0].value,
    fields: [],
    hidden: [],
    default_tag_ids: [],
})
const draft = ref(blank())

const archived = computed(() => typesStore.types.filter((type) => type.archived_at))
const hasAnyType = computed(() => typesStore.types.length > 0)

const availableTemplates = computed(() =>
    TYPE_TEMPLATES.filter(
        (template) => !typesStore.active.some((type) => type.name.toLowerCase() === template.name.toLowerCase()),
    ),
)
const showTemplates = computed(
    () => !draft.value.id && !draft.value.name && !draft.value.fields.length && availableTemplates.value.length > 0,
)

const openAnalytics = (id) => typesStore.openAnalytics(id, 'manage')

const countsLine = (type) => {
    const fields = (type.fields ?? []).filter((field) => !field.hidden).length
    const count = type.transactions_count ?? 0
    return `${fields} field${fields === 1 ? '' : 's'} · ${count} transaction${count === 1 ? '' : 's'}`
}

const hasLimit = (field) => (field.min !== '' && field.min != null) || (field.max !== '' && field.max != null)

const toDraftField = (field, uids = {}) => {
    const out = {
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
    }
    if (field.type === 'number' && hasLimit(out)) limitsOpen.add(out.uid)

    return out
}

const newField = (spec) => ({
    uid: ++uid,
    saved: false,
    key: '',
    label: spec.label,
    type: spec.type,
    required: false,
    unit: spec.unit ?? '',
    min: spec.min ?? '',
    max: spec.max ?? (spec.type === 'rating' ? 5 : ''),
    options: [...(spec.options ?? [])],
    formula: spec.formula ?? '',
    fill_amount: Boolean(spec.fill_amount),
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
const fieldCount = computed(() => draft.value.fields.length + draft.value.hidden.length)

const hasExtras = (field) => EXTRA_KINDS.includes(field.type)
const limitsShown = (field) => limitsOpen.has(field.uid)
const toggleLimits = (field) => {
    if (limitsOpen.has(field.uid)) limitsOpen.delete(field.uid)
    else limitsOpen.add(field.uid)
}

// Default tags as chips; the picker below is only for changing them.
const tooManyTags = computed(() => draft.value.default_tag_ids.length > MAX_DEFAULT_TAGS)
const draftTags = computed(() =>
    draft.value.default_tag_ids.map((id) => tagsStore.tags.find((tag) => tag.id === id)).filter(Boolean),
)
const removeTag = (id) => {
    draft.value.default_tag_ids = draft.value.default_tag_ids.filter((tagId) => tagId !== id)
}

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

// Unsaved changes: the draft is compared with how it looked when it was loaded.
const signature = (d) =>
    JSON.stringify({
        name: d.name.trim(),
        emoji: d.emoji || '',
        color: d.color,
        fields: d.fields.map(({ uid: _uid, ...field }) => field),
        hidden: d.hidden.map((field) => field.key),
        tags: [...d.default_tag_ids].sort((a, b) => a - b),
    })
const dirty = computed(() => signature(draft.value) !== snapshot.value)
snapshot.value = signature(draft.value)

const blockMessage = computed(() => {
    const index = formulaErrors.value.findIndex(Boolean)
    if (index !== -1) return `Fix the formula in ${keyed.value[index]?.label || 'the computed field'} to save.`
    if (tooManyTags.value) return `Pick at most ${MAX_DEFAULT_TAGS} default tags to save.`
    if (dirty.value && !draft.value.name.trim()) return 'Name the type to save.'

    return ''
})
const canSave = computed(() => dirty.value && !blockMessage.value && !typesStore.isLoading)
const saveKeys = /Mac|iPhone|iPad/.test(navigator.userAgent) ? '⌘S' : 'Ctrl+S'
const saveTip = computed(() => {
    if (blockMessage.value) return blockMessage.value
    if (!dirty.value) return 'No changes to save'

    return `Save (${saveKeys})`
})

// uids: field key → list key to keep, so saving doesn't re-mount (and re-animate) the field cards.
const load = (type, uids = {}) => {
    draft.value = type
        ? {
              id: type.id,
              name: type.name,
              emoji: type.emoji || '',
              color: type.color || COLORS[0].value,
              fields: (type.fields ?? []).filter((f) => !f.hidden).map((f) => toDraftField(f, uids)),
              hidden: (type.fields ?? []).filter((f) => f.hidden).map((f) => toDraftField(f, uids)),
              default_tag_ids: [...(type.default_tag_ids ?? [])],
          }
        : blank()
    snapshot.value = signature(draft.value)
    savedName.value = type?.name ?? ''
    pickerOpen.value = false
    previewData.value = samples()
}

const confirmDiscard = async () => {
    if (!dirty.value) return true

    return askConfirm({
        title: 'Discard changes?',
        message: `Your edits${draft.value.id ? ` to ${savedName.value}` : ' to the new type'} haven't been saved.`,
        actionLabel: 'Discard',
        danger: true,
    })
}

const select = async (type) => {
    if (type.id === draft.value.id) return
    if (await confirmDiscard()) load(type)
}

const startNew = async () => {
    if (!(await confirmDiscard())) return
    creating.value = true
    load(null)
}

const applyTemplate = async (template) => {
    if (!(await confirmDiscard())) return
    creating.value = true
    load(null)
    draft.value.name = template.name
    draft.value.emoji = template.emoji
    draft.value.color = template.color
    draft.value.fields = template.fields.map(newField)
    previewData.value = samples()
}

const addField = (type) => {
    if (fieldCount.value >= MAX_FIELDS) return
    const label = FIELD_KINDS.find((k) => k.type === type)?.label.replace(' / ', '/') ?? 'Field'
    const fills = draft.value.fields.some((f) => f.type === 'computed' && f.fill_amount)
    draft.value.fields.push(
        newField({
            label: type === 'computed' ? 'Total' : label,
            type,
            formula: type === 'computed' ? numericKeys.value.slice(0, 2).join(' * ') : '',
            fill_amount: type === 'computed' && !fills,
        }),
    )
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

// One tab stop for the colors; the arrow keys move the selection (radio group).
const swatchTabbable = (color, index) =>
    draft.value.color === color.value || (index === 0 && !COLORS.some((c) => c.value === draft.value.color))
const onSwatchKey = (event, index) => {
    const step = ['ArrowRight', 'ArrowDown'].includes(event.key)
        ? 1
        : ['ArrowLeft', 'ArrowUp'].includes(event.key)
          ? -1
          : 0
    if (!step) return
    event.preventDefault()
    const group = event.currentTarget.closest('[role="radiogroup"]')
    const next = (index + step + COLORS.length) % COLORS.length
    draft.value.color = COLORS[next].value
    nextTick(() => group?.querySelectorAll('[role="radio"]')[next]?.focus())
}

const save = async () => {
    if (!canSave.value) return
    const body = {
        name: draft.value.name.trim(),
        emoji: draft.value.emoji || null,
        color: draft.value.color,
        fields: keyed.value.map(payloadField),
        default_tag_ids: draft.value.default_tag_ids.slice(0, MAX_DEFAULT_TAGS),
    }
    const uids = Object.fromEntries([...keyed.value, ...draft.value.hidden].map((f) => [f.key, f.uid]))
    const saved = draft.value.id ? await typesStore.update(draft.value.id, body) : await typesStore.create(body)
    if (saved) load(typesStore.byId(saved.id) ?? saved, uids)
}

const archive = async () => {
    const type = typesStore.byId(draft.value.id)
    const count = type?.transactions_count ?? 0
    const ok = await askConfirm({
        title: `Archive ${savedName.value}?`,
        message: count
            ? `Its ${count} transaction${count === 1 ? '' : 's'} keep the type, but it is no longer offered for new ones. You can restore it later.`
            : 'It is no longer offered for new transactions. You can restore it later.',
        actionLabel: 'Archive',
        danger: true,
    })
    if (!ok) return
    if (await typesStore.archive(draft.value.id)) load(typesStore.active[0] ?? null)
}

// Closing (X, backdrop, Esc) asks first when there are unsaved changes.
const requestClose = async () => {
    if (await confirmDiscard()) dialogRef.value?.close()
}

const onClickCapture = (event) => {
    if (!dirty.value) return
    const target = event.target
    if (!target.closest?.('[aria-label="Close"], .modal-backdrop button')) return
    event.preventDefault()
    event.stopPropagation()
    requestClose()
}

// On the window, so it still works after focus was lost (e.g. a removed field's button).
const onKeydown = (event) => {
    if (event.key.toLowerCase() !== 's' || !(event.ctrlKey || event.metaKey)) return
    if (!dialogRef.value?.open || confirmDialog.open) return
    event.preventDefault()
    if (canSave.value) formRef.value?.requestSubmit()
}

// Opened from Settings / the dock: refresh counts and show the first type.
let observer
onMounted(() => {
    wideQuery.addEventListener('change', onWideChange)
    window.addEventListener('keydown', onKeydown)
    observer = new MutationObserver(() => {
        if (!dialogRef.value?.open) return
        creating.value = false
        typesStore.fetch()
        const current = draft.value.id && typesStore.byId(draft.value.id)
        load(current && !current.archived_at ? current : (typesStore.active[0] ?? null))
    })
    observer.observe(dialogRef.value, { attributes: true, attributeFilter: ['open'] })
})
onBeforeUnmount(() => {
    observer?.disconnect()
    wideQuery.removeEventListener('change', onWideChange)
    window.removeEventListener('keydown', onKeydown)
})
</script>

<style scoped>
.field-move-move {
    transition: transform 0.2s ease;
}
</style>
