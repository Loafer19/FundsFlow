<template>
    <div class="flex flex-col gap-1">
        <label :for="inputId" class="text-sm text-base-content/80" :id="labelId">
            {{ field.label }}<span v-if="field.required" class="text-error"> *</span>
        </label>

        <!-- Number: free text so "58,90" and "1 500" work; the unit sits inside the field. -->
        <label v-if="field.type === 'number'" class="input input-sm w-full">
            <input :id="inputId" type="text" inputmode="decimal" autocomplete="off" :value="model ?? ''"
                :required="field.required" :placeholder="numberHint" @input="model = $event.target.value" />
            <span v-if="field.unit" class="text-base-content/60">{{ field.unit }}</span>
        </label>

        <input v-else-if="field.type === 'duration'" :id="inputId" type="text" autocomplete="off"
            class="input input-sm w-full" :value="durationText" :required="field.required" placeholder="45m or 1h 20m"
            @input="model = $event.target.value" />

        <div v-else-if="field.type === 'rating'" class="join" role="radiogroup" :aria-labelledby="labelId">
            <button v-for="n in Number(field.max ?? 5)" :key="n" type="button" role="radio" :aria-checked="model === n"
                class="btn btn-sm join-item px-2.5" :class="model === n ? 'btn-primary' : 'btn-soft'"
                @click="model = model === n ? null : n">{{ n }}</button>
        </div>

        <div v-else-if="field.type === 'boolean'" class="join" role="radiogroup" :aria-labelledby="labelId">
            <button type="button" role="radio" :aria-checked="model === true" class="btn btn-sm join-item"
                :class="model === true ? 'btn-primary' : 'btn-soft'" @click="model = model === true ? null : true">Yes</button>
            <button type="button" role="radio" :aria-checked="model === false" class="btn btn-sm join-item"
                :class="model === false ? 'btn-primary' : 'btn-soft'" @click="model = model === false ? null : false">No</button>
        </div>

        <div v-else-if="field.type === 'select' || field.type === 'multiselect'" class="flex flex-wrap items-center gap-1"
            :aria-labelledby="labelId" role="group">
            <button v-for="option in options" :key="option" type="button" class="btn btn-xs"
                :class="isPicked(option) ? 'btn-primary' : 'btn-soft'" :aria-pressed="isPicked(option)"
                @click="pick(option)">{{ option }}</button>
            <input v-if="allowNew" v-model="draft" type="text" class="input input-xs w-28" maxlength="48"
                placeholder="Add option +" :aria-label="`New ${field.label} option`" @keydown.enter.prevent="addOption"
                @blur="addOption" />
        </div>

        <input v-else-if="field.type === 'date'" :id="inputId" type="date" class="input input-sm w-full"
            :value="model ?? ''" :required="field.required" @input="model = $event.target.value || null" />

        <input v-else-if="field.type === 'computed'" :id="inputId" type="text" readonly tabindex="-1"
            class="input input-sm w-full bg-base-200 font-semibold" :value="computedText" />

        <input v-else :id="inputId" type="text" class="input input-sm w-full" maxlength="500" :value="model ?? ''"
            :required="field.required" @input="model = $event.target.value" />

        <p v-if="field.type === 'computed' && field.fill_amount" class="text-xs text-base-content/60">Fills amount</p>
    </div>
</template>

<script setup>
import { computed, ref, useId } from 'vue'
import { formatMinutes } from '../services/typeFields.js'

const props = defineProps({
    field: { type: Object, required: true },
    /** Shown value of a computed field (already formatted), or ''. */
    computedText: { type: String, default: '' },
    /** Let the user type a new select option (saved to the type with the transaction). */
    allowNew: { type: Boolean, default: true },
})

const model = defineModel({ default: null })

const inputId = useId()
const labelId = useId()
const draft = ref('')
const added = ref([])

const numberHint = computed(() => {
    const { min, max } = props.field
    if (min != null && max != null) return `${min}–${max}`
    if (min != null) return `≥ ${min}`
    if (max != null) return `≤ ${max}`
    return ''
})

const durationText = computed(() =>
    typeof model.value === 'number' ? formatMinutes(model.value) : (model.value ?? ''),
)

const options = computed(() => {
    const picked = [].concat(model.value ?? [])
    return [...new Set([...(props.field.options ?? []), ...added.value, ...picked])]
})

const isPicked = (option) =>
    props.field.type === 'multiselect' ? [].concat(model.value ?? []).includes(option) : model.value === option

const pick = (option) => {
    if (props.field.type === 'multiselect') {
        const list = [].concat(model.value ?? [])
        model.value = list.includes(option) ? list.filter((o) => o !== option) : [...list, option]
        return
    }
    model.value = model.value === option ? null : option
}

const addOption = () => {
    const option = draft.value.trim()
    draft.value = ''
    if (!option) return

    const known = options.value.find((o) => o.toLowerCase() === option.toLowerCase())
    if (!known) added.value.push(option)
    if (!isPicked(known ?? option)) pick(known ?? option)
}
</script>
