// Type + custom fields in the Add / Edit Transaction forms: the picked type, its default tags, and an
// amount that follows the type's "fill amount" computed field until the user types their own.
import { computed, nextTick, ref, watch } from 'vue'
import { useTransactionTypesStore } from './transactionTypes.js'
import {
    evaluateFormula,
    fillField,
    formatFieldNumber,
    formulaText,
    isEmptyValue,
    numericValues,
    parseDuration,
    parseNumber,
    visibleFields,
} from './typeFields.js'

export const useTypedTransaction = (transaction, amountField) => {
    const typesStore = useTransactionTypesStore()

    // Linked = the amount shows the computed total. Typing an amount unlinks it; changing a field or
    // the reset button links it again.
    const linked = ref(true)
    const autoTags = ref([])
    let lastFilled = null
    let suppress = false

    const type = computed(() => typesStore.byId(transaction.value.transaction_type_id))
    const fill = computed(() => fillField(type.value))
    const inputs = computed(() => numericValues(type.value, transaction.value.data))
    const fillValue = computed(() => (fill.value ? evaluateFormula(fill.value.formula, inputs.value) : null))

    const sign = () => {
        const amount = Number(transaction.value.amount)
        if (amount) return Math.sign(amount)
        return amountField.value?.isIncome ? 1 : -1
    }

    const applyFill = () => {
        if (!linked.value || !fill.value) return

        const current = transaction.value.amount
        if (fillValue.value === null || fillValue.value === 0) {
            // Inputs cleared: drop only a total we wrote ourselves.
            if (lastFilled !== null && current === lastFilled) transaction.value.amount = ''
            lastFilled = null
            return
        }

        lastFilled = sign() * Math.abs(fillValue.value)
        transaction.value.amount = lastFilled
    }

    watch(fillValue, () => {
        if (!suppress) applyFill()
    })

    // Any change to a field the formula reads re-links the amount.
    watch(
        () => JSON.stringify(inputs.value),
        () => {
            if (suppress) return
            if (!linked.value) {
                linked.value = true
                applyFill()
            }
        },
    )

    const isLinked = computed(
        () =>
            Boolean(fill.value) &&
            fillValue.value !== null &&
            (linked.value || Math.abs(Number(transaction.value.amount)) === Math.abs(fillValue.value)),
    )

    const hint = computed(() => {
        if (!fill.value || fillValue.value === null) return null
        const name = `${type.value.name} ${fill.value.label.toLowerCase()}`

        return isLinked.value
            ? { linked: true, text: `From ${name} · ${formulaText(type.value, fill.value, transaction.value.data)}` }
            : { linked: false, text: `${name} is ${formatFieldNumber(fillValue.value, fill.value.unit)}` }
    })

    const onManualAmount = () => {
        if (fill.value) linked.value = false
    }

    const resetAmount = () => {
        linked.value = true
        applyFill()
    }

    /** Switch type: swap the previous type's auto-added tags for the new type's default tags. */
    const pickType = (id) => {
        const next = id == null ? null : Number(id)
        if (next === (transaction.value.transaction_type_id ?? null)) return

        const tags = transaction.value.tags.filter((tagId) => !autoTags.value.includes(tagId))
        const defaults = (typesStore.byId(next)?.default_tag_ids ?? []).filter((tagId) => !tags.includes(tagId))

        transaction.value.tags = [...tags, ...defaults]
        transaction.value.transaction_type_id = next
        transaction.value.data ??= {}
        autoTags.value = defaults
        linked.value = true
        nextTick(applyFill)
    }

    /** Form state for a fresh / loaded transaction; the link state is derived like the backend does. */
    const load = ({ amount = '' } = {}) => {
        suppress = true
        autoTags.value = []
        lastFilled = null
        nextTick(() => {
            suppress = false
            const value = fillValue.value
            linked.value = value === null || amount === '' || Math.abs(Number(amount)) === Math.abs(value)
            if (linked.value && value !== null) lastFilled = Number(amount) || null
        })
    }

    /** API fields: transaction_type_id, data (parsed numbers / minutes), add_options; amount left out when the type fills it. */
    const payload = (base) => {
        const out = { ...base, transaction_type_id: transaction.value.transaction_type_id ?? null }
        delete out.data

        const t = type.value
        if (!t) {
            if (!out.transaction_type_id) delete out.transaction_type_id
            return out
        }

        const data = {}
        let addOptions = false

        for (const field of visibleFields(t)) {
            if (field.type === 'computed') continue
            const raw = transaction.value.data?.[field.key]
            if (isEmptyValue(raw)) continue

            if (field.type === 'number') data[field.key] = parseNumber(raw) ?? raw
            else if (field.type === 'duration') data[field.key] = parseDuration(raw) ?? raw
            else data[field.key] = raw

            if (field.type === 'select' || field.type === 'multiselect') {
                addOptions ||= [].concat(raw).some((option) => !(field.options ?? []).includes(option))
            }
        }

        out.data = data
        if (addOptions) out.add_options = true
        if (fill.value && (out.amount === '' || out.amount == null)) delete out.amount

        return out
    }

    return { type, fill, fillValue, hint, isLinked, onManualAmount, resetAmount, pickType, load, payload }
}
