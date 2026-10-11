// Parses an amount the way people type it, not the way <input type="number">
// wants it: "1 500,50", "1,500.50", "12.5", "120+35-5", "+300".
//
// A leading + or - is the type (income / expense), not arithmetic; everything
// after it is a sum of terms. Math runs in integer cents, so 0.1 + 0.2 is 0.30.

const MAX_CENTS = 999_999_999_999 // transactions.amount is decimal(15,2)

const separatorCache = new Map()

/** Group and decimal characters of a locale, e.g. uk-UA → { group: ' ', decimal: ',' }. */
export const localeSeparators = (locale) => {
    if (!separatorCache.has(locale)) {
        let group = ','
        let decimal = '.'

        try {
            for (const part of new Intl.NumberFormat(locale).formatToParts(12345.6)) {
                if (part.type === 'group') group = part.value
                if (part.type === 'decimal') decimal = part.value
            }
        } catch {
            // Unknown locale — keep en-US defaults.
        }

        separatorCache.set(locale, { group, decimal })
    }

    return separatorCache.get(locale)
}

const notNumber = (raw) => ({ error: `“${raw}” is not a number` })

// One term: digits with "." / "," as decimal or thousands separators.
//   - 1–2 digits after the last separator → decimal: "12,5", "1.500,50", "1,500.50".
//   - exactly 3 digits → thousands, but only when unambiguous: the char repeats
//     ("1.500.000") or the user's number format groups with it (en-US "1,500").
//     Otherwise "1.500" could be 1500 or 1.5, so it's an error, not a guess.
const parseTerm = (input, group) => {
    const raw = /^[.,]/.test(input) ? `0${input}` : input

    if (!/^\d+([.,]\d+)*$/.test(raw)) return notNumber(input)

    const separators = raw.match(/[.,]/g) ?? []

    if (separators.length === 0) return { cents: Number(raw) * 100 }

    const last = separators[separators.length - 1]
    const lastIndex = raw.lastIndexOf(last)
    const tail = raw.slice(lastIndex + 1)
    const lastRepeats = separators.filter((s) => s === last).length > 1
    const lastIsGroup = tail.length === 3 && (lastRepeats || (!/\s/.test(group) && last === group))

    let integerPart = raw
    let fraction = ''

    if (!lastIsGroup) {
        if (lastRepeats) return notNumber(input)
        if (tail.length === 3) return { error: `“${input}” is ambiguous — type 1500 or 1,5` }
        if (tail.length > 2) return { error: 'Use at most 2 digits after the decimal point' }

        integerPart = raw.slice(0, lastIndex)
        fraction = tail
    }

    const groups = integerPart.split(/[.,]/)

    if (groups.length > 1) {
        const groupChars = new Set(integerPart.match(/[.,]/g))
        const malformed =
            groupChars.size > 1 ||
            (!lastIsGroup && groupChars.has(last)) ||
            groups[0].length > 3 ||
            groups.slice(1).some((g) => g.length !== 3)

        if (malformed) return notNumber(input)
    }

    return { cents: Number(groups.join('')) * 100 + Number(fraction.padEnd(2, '0')) }
}

/**
 * @returns {{
 *   empty: boolean,
 *   sign: -1 | 0 | 1,        leading +/- typed by the user, 0 when none
 *   value: number | null,    magnitude (> 0) when valid
 *   expression: boolean,     more than one term was summed
 *   incomplete: boolean,     ends with an operator — still typing
 *   error: string | null,
 * }}
 */
export const parseAmountInput = (input, locale = 'uk-UA') => {
    const { group } = localeSeparators(locale)
    // Drop every kind of space and apostrophe: "1 500", "1 500", "1'500", "120 + 35".
    let text = String(input ?? '')
        .replace(/[\s  '’]/g, '')
        .replace(/[−–]/g, '-')

    const result = { empty: false, sign: 0, value: null, expression: false, incomplete: false, error: null }

    if (text[0] === '+' || text[0] === '-') {
        result.sign = text[0] === '+' ? 1 : -1
        text = text.slice(1)
    }

    if (text === '') return { ...result, empty: true }

    const terms = text.split(/(?=[+-])/)
    let cents = 0

    for (const [index, term] of terms.entries()) {
        const negative = term[0] === '-'
        const body = term[0] === '+' || term[0] === '-' ? term.slice(1) : term

        if (body === '') {
            const incomplete = index === terms.length - 1

            return { ...result, incomplete, error: incomplete ? 'Finish the expression' : notNumber(term).error }
        }

        const parsed = parseTerm(body, group)
        if (parsed.error) return { ...result, error: parsed.error }

        cents += negative ? -parsed.cents : parsed.cents
    }

    if (cents <= 0) return { ...result, error: 'Amount must be greater than zero' }
    if (cents > MAX_CENTS) return { ...result, error: 'Amount is too large' }

    return { ...result, value: cents / 100, expression: terms.length > 1 }
}

/** Plain editable form of a number in the user's locale: 1500.5 → "1500,5" for uk-UA. */
export const toEditableAmount = (value, locale = 'uk-UA') => {
    const number = Math.abs(Number(value))

    if (!Number.isFinite(number)) return ''

    return String(Math.round(number * 100) / 100).replace('.', localeSeparators(locale).decimal)
}
