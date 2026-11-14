// Transaction type fields on the client: kinds, keys, the computed-field formula and value display.
// Mirrors backend App\Support\TransactionFields / FieldFormula; the backend stays the judge on save.
import { formatDate } from './formatters.js'
import settings from './settings.js'

export const MAX_FIELDS = 30
export const RATING_SCALES = [3, 5, 10]
export const NUMERIC_KINDS = ['number', 'rating', 'duration', 'boolean']

export const FIELD_KINDS = [
    { type: 'number', label: 'Number' },
    { type: 'rating', label: 'Rating' },
    { type: 'select', label: 'One of' },
    { type: 'multiselect', label: 'Several of' },
    { type: 'text', label: 'Text' },
    { type: 'date', label: 'Date' },
    { type: 'boolean', label: 'Yes / No' },
    { type: 'duration', label: 'Duration' },
    { type: 'computed', label: 'Computed' },
]

export const kindLabel = (type) => FIELD_KINDS.find((kind) => kind.type === type)?.label ?? type

const CYRILLIC = {
    а: 'a',
    б: 'b',
    в: 'v',
    г: 'h',
    ґ: 'g',
    д: 'd',
    е: 'e',
    є: 'ye',
    ж: 'zh',
    з: 'z',
    и: 'y',
    і: 'i',
    ї: 'yi',
    й: 'y',
    к: 'k',
    л: 'l',
    м: 'm',
    н: 'n',
    о: 'o',
    п: 'p',
    р: 'r',
    с: 's',
    т: 't',
    у: 'u',
    ф: 'f',
    х: 'kh',
    ц: 'ts',
    ч: 'ch',
    ш: 'sh',
    щ: 'shch',
    ь: '',
    ю: 'yu',
    я: 'ya',
    ы: 'y',
    э: 'e',
    ё: 'yo',
    ъ: '',
}

/** "Price per liter" → "price_per_liter"; keys are formula identifiers, so never start with a digit. */
export const slugKey = (label, taken = []) => {
    const ascii = String(label ?? '')
        .toLowerCase()
        .split('')
        .map((ch) => CYRILLIC[ch] ?? ch)
        .join('')
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '')
    let slug =
        ascii
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '')
            .slice(0, 32) || 'field'
    if (/^\d/.test(slug)) slug = `f_${slug}`

    let key = slug
    for (let n = 2; taken.includes(key); n++) key = `${slug}_${n}`

    return key
}

// ---- Formula: numbers, keys, + - * / and parentheses (recursive descent, never eval) ----

const tokenize = (formula) => {
    const text = String(formula ?? '').trim()
    if (text === '') throw new Error('The formula is empty!')
    if (text.length > 200) throw new Error('The formula is too long!')

    const tokens = []
    const re = /\s+|(\d+(?:[.,]\d+)?)|([a-z_][a-z0-9_]*)|([-+*/()×÷])/iy
    re.lastIndex = 0

    while (re.lastIndex < text.length) {
        const start = re.lastIndex
        const m = re.exec(text)
        if (!m || re.lastIndex === start) {
            throw new Error('Formulas can only use field keys, numbers, + - * / and parentheses!')
        }
        if (m[1]) tokens.push(['num', m[1].replace(',', '.')])
        else if (m[2]) tokens.push(['key', m[2].toLowerCase()])
        else if (m[3]) tokens.push(['op', m[3] === '×' ? '*' : m[3] === '÷' ? '/' : m[3]])
    }

    return tokens
}

const parse = (formula, values) => {
    const t = tokenize(formula)
    const keys = []
    let pos = 0

    const factor = (depth = 0) => {
        if (depth > 32) throw new Error('The formula is nested too deeply!')
        const token = t[pos]
        if (!token) throw new Error('The formula ends too early!')

        if (token[0] === 'op' && (token[1] === '-' || token[1] === '+')) {
            pos++
            const value = factor(depth + 1)
            return value === null ? null : token[1] === '-' ? -value : value
        }
        if (token[0] === 'num') {
            pos++
            return Number(token[1])
        }
        if (token[0] === 'key') {
            pos++
            keys.push(token[1])
            if (!values) return 1
            const raw = values[token[1]]
            if (typeof raw === 'boolean') return raw ? 1 : 0
            if (typeof raw === 'number') return Number.isFinite(raw) ? raw : null
            if (typeof raw === 'string' && raw.trim() !== '' && !Number.isNaN(Number(raw))) return Number(raw)
            return null
        }
        if (token[1] === '(') {
            pos++
            const value = expr()
            if (t[pos]?.[1] !== ')') throw new Error('A "(" in the formula is never closed!')
            pos++
            return value
        }
        throw new Error(`Unexpected "${token[1]}" in the formula!`)
    }

    const term = () => {
        let left = factor()
        while (t[pos]?.[0] === 'op' && (t[pos][1] === '*' || t[pos][1] === '/')) {
            const op = t[pos++][1]
            const right = factor()
            if (left === null || right === null) left = null
            else if (op === '*') left *= right
            else left = Math.abs(right) < 1e-12 ? null : left / right
        }
        return left
    }

    const expr = () => {
        let left = term()
        while (t[pos]?.[0] === 'op' && (t[pos][1] === '+' || t[pos][1] === '-')) {
            const op = t[pos++][1]
            const right = term()
            left = left === null || right === null ? null : op === '+' ? left + right : left - right
        }
        return left
    }

    const result = expr()
    if (pos !== t.length) throw new Error(`Unexpected "${t[pos][1]}" in the formula!`)

    return { result, keys: [...new Set(keys)], tokens: t }
}

/** Keys a formula uses; throws Error with a user-facing message when it can't be parsed. */
export const formulaKeys = (formula) => parse(formula, null).keys

/** The formula's value for these field values, or null (missing input, division by zero, bad formula). */
export const evaluateFormula = (formula, values) => {
    try {
        const { result } = parse(formula, values ?? {})
        return result === null || !Number.isFinite(result) ? null : Math.round(result * 100) / 100
    } catch {
        return null
    }
}

/** Formula error for the editor, or null; checks keys against the type's numeric fields. */
export const formulaError = (formula, fields) => {
    try {
        const keys = formulaKeys(formula)
        const numeric = new Set(fields.filter((f) => NUMERIC_KINDS.includes(f.type)).map((f) => f.key))
        const unknown = keys.filter((key) => !numeric.has(key))
        return unknown.length ? `${unknown.join(', ')} is not a number field of this type` : null
    } catch (error) {
        return error.message
    }
}

// ---- Display ----

const CURRENCY_UNITS = ['₴', 'грн', 'uah', '$', 'usd', '€', 'eur', '£', 'zł', 'pln']
export const isCurrencyUnit = (unit) =>
    CURRENCY_UNITS.includes(
        String(unit ?? '')
            .trim()
            .toLowerCase(),
    )

export const formatFieldNumber = (number, unit) => {
    const money = isCurrencyUnit(unit)
    const text = new Intl.NumberFormat(settings.moneyFormat, {
        minimumFractionDigits: money ? 2 : 0,
        maximumFractionDigits: money ? 2 : 4,
    })
        .format(number)
        .replace(/[\u202f\u00a0]/g, ' ')

    return unit ? `${text} ${unit}` : text
}

export const formatMinutes = (minutes) => {
    const h = Math.floor(minutes / 60)
    const m = minutes % 60
    return h > 0 ? (m > 0 ? `${h}h ${m}m` : `${h}h`) : `${m}m`
}

export const isEmptyValue = (value) =>
    value === null || value === undefined || value === '' || (Array.isArray(value) && value.length === 0)

export const displayValue = (field, value) => {
    switch (field.type) {
        case 'number':
        case 'computed':
            return formatFieldNumber(Number(value), field.unit)
        case 'rating':
            return `${value}/${field.max ?? 5}`
        case 'duration':
            return formatMinutes(Number(value))
        case 'boolean':
            return value ? 'Yes' : 'No'
        case 'multiselect':
            return [].concat(value).join(', ')
        case 'date':
            return formatDate(`${value}T00:00:00`)
        default:
            return String(value)
    }
}

export const visibleFields = (type) => (type?.fields ?? []).filter((field) => !field.hidden)

export const fillField = (type) =>
    visibleFields(type).find((field) => field.type === 'computed' && field.fill_amount) ?? null

/** [{ key, label, value }] for the visible fields that have a value, in schema order. */
export const typeRows = (type, data) =>
    visibleFields(type)
        .filter((field) => !isEmptyValue(data?.[field.key]))
        .map((field) => ({ key: field.key, label: field.label, value: displayValue(field, data[field.key]) }))

/** "40 l · 58,90 ₴ · OKKO": first values for a list row, without the field that fills the amount. */
export const typeSummary = (type, data, limit = 3) => {
    const fill = fillField(type)?.key
    return typeRows(type, data)
        .filter((row) => row.key !== fill)
        .slice(0, limit)
        .map((row) => row.value)
        .join(' · ')
}

/** "40 l × 58,90 ₴": the formula with its keys replaced by the shown values. */
export const formulaText = (type, field, data) => {
    try {
        const { tokens } = parse(field.formula, null)
        const byKey = Object.fromEntries((type.fields ?? []).map((f) => [f.key, f]))
        const numbers = numericValues(type, data)
        const ops = { '*': '×', '/': '÷' }

        return tokens
            .map(([kind, text]) => {
                if (kind === 'key') {
                    const f = byKey[text]
                    const value = NUMERIC_KINDS.includes(f?.type) ? numbers[text] : data?.[text]
                    return f && !isEmptyValue(value) ? displayValue(f, value) : text
                }
                if (kind === 'num') return text
                return text === '(' || text === ')' ? text : ` ${ops[text] ?? text} `
            })
            .join('')
            .replace(/\(\s+/g, '(')
            .replace(/\s+\)/g, ')')
            .replace(/\s+/g, ' ')
            .trim()
    } catch {
        return field.formula
    }
}

/** Values of the type's numeric fields, coerced the way the backend reads them (for the live total). */
export const numericValues = (type, data) => {
    const out = {}
    for (const field of type?.fields ?? []) {
        if (!NUMERIC_KINDS.includes(field.type)) continue
        const raw = data?.[field.key]
        if (field.type === 'boolean') out[field.key] = raw === true ? true : raw === false ? false : undefined
        else if (field.type === 'duration') out[field.key] = parseDuration(raw)
        else out[field.key] = parseNumber(raw)
    }
    return out
}

/** "58,90" / "1 500.5" / "58.9 ₴" → number, or undefined. */
export const parseNumber = (raw) => {
    if (typeof raw === 'number') return Number.isFinite(raw) ? raw : undefined
    const text = String(raw ?? '')
        .replace(/[\s\u00a0\u202f']/g, '')
        .replace(/[^\d.,-]/g, '')
    if (text === '') return undefined
    const normalized = text.includes(',') && !text.includes('.') ? text.replace(',', '.') : text.replace(/,/g, '')
    const number = Number(normalized)
    return Number.isFinite(number) ? number : undefined
}

/** "45" / "45m" / "1h 20m" / "1:20" → minutes, or undefined. */
export const parseDuration = (raw) => {
    if (typeof raw === 'number') return Number.isFinite(raw) ? Math.round(raw) : undefined
    const text = String(raw ?? '')
        .trim()
        .toLowerCase()
    if (text === '') return undefined
    if (/^\d+$/.test(text)) return Number(text)
    const clock = text.match(/^(\d+):(\d{1,2})$/)
    if (clock) return Number(clock[1]) * 60 + Number(clock[2])
    const parts = text.match(/^(?:(\d+(?:[.,]\d+)?)\s*(?:h|год|г))?\s*(?:(\d+)\s*(?:m|min|хв))?$/)
    if (!parts || (!parts[1] && !parts[2])) return undefined
    return Math.round(Number((parts[1] ?? '0').replace(',', '.')) * 60) + Number(parts[2] ?? 0)
}
