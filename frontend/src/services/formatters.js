import settings from './settings'

export const formatDateOptions = {
    'Year First': ['YYYY/MM/DD'],
    'Month First': ['MM/DD/YYYY', 'MM/DD'],
    Month: ['long Month with Day & Year', 'short Month with Day & Year', 'long Month with Day', 'short Month with Day'],
    'Day First': ['DD-MM-YYYY', 'DD/MM/YYYY', 'DD.MM.YYYY'],
    'Day First (Short)': ['DD-MM', 'DD/MM', 'DD.MM'],
}

const dateFormatMap = {
    'YYYY/MM/DD': { month: '2-digit', day: '2-digit', year: 'numeric', locale: 'zh-CN' },
    'MM/DD/YYYY': { month: '2-digit', day: '2-digit', year: 'numeric', locale: 'en-US' },
    'MM/DD': { month: '2-digit', day: '2-digit', locale: 'en-US' },
    'long Month with Day & Year': { day: '2-digit', month: 'long', year: 'numeric' },
    'short Month with Day & Year': { day: '2-digit', month: 'short', year: 'numeric' },
    'long Month with Day': { day: '2-digit', month: 'long' },
    'short Month with Day': { day: '2-digit', month: 'short' },
    'DD-MM-YYYY': { day: '2-digit', month: '2-digit', year: 'numeric', locale: 'nl-NL' },
    'DD/MM/YYYY': { day: '2-digit', month: '2-digit', year: 'numeric', locale: 'en-GB' },
    'DD.MM.YYYY': { day: '2-digit', month: '2-digit', year: 'numeric', locale: 'uk-UA' },
    'DD-MM': { day: '2-digit', month: '2-digit', locale: 'nl-NL' },
    'DD/MM': { day: '2-digit', month: '2-digit', locale: 'en-GB' },
    'DD.MM': { day: '2-digit', month: '2-digit', locale: 'uk-UA' },
}

export const formatDate = (date) => {
    const config = dateFormatMap[settings.dateFormat]

    return new Intl.DateTimeFormat(config.locale, config).format(new Date(date))
}

export const formatMoneyOptions = {
    '1.234,56': 'de-DE',
    '1 234,56': 'uk-UA',
    '1,234.56': 'en-US',
    "1'234.56": 'de-CH',
    '12,34,567.89': 'en-IN',
}

export const formatMoney = (amount) => {
    const options = {
        style: 'decimal',
        minimumFractionDigits: 0,
        maximumFractionDigits: settings.decimals ? 2 : 0,
    }

    return new Intl.NumberFormat(settings.moneyFormat, options).format(amount)
}

export const formatPercentage = (value) => {
    if (value === Number.POSITIVE_INFINITY) return '∞'
    if (value === Number.NEGATIVE_INFINITY) return '-∞'
    if (Number.isNaN(value)) return 'N/A'
    return value.toFixed(1) + '%'
}

export const toLocalDateStr = (value) => {
    if (typeof value === 'string') {
        return value.split('T')[0]
    }

    const date = value instanceof Date ? value : new Date(value)
    const year = date.getFullYear()
    const month = String(date.getMonth() + 1).padStart(2, '0')
    const day = String(date.getDate()).padStart(2, '0')

    return `${year}-${month}-${day}`
}

// "2026-01-16" as a wall-clock Date — new Date(dateOnlyString) parses as UTC
// midnight and can land on the wrong local day. Pair for toLocalDateStr().
export const parseLocalDate = (dateStr) => {
    const [year, month, day] = dateStr.split('-').map(Number)

    return new Date(year, month - 1, day)
}

export const transactionSourceLabels = {
    web: 'Added from the web app',
    telegram: 'Added via Telegram',
    recurring: 'Created automatically (recurring)',
}

export const getTransactionSourceLabel = (source) => transactionSourceLabels[source] ?? transactionSourceLabels.web

export const apiErrorMessage = (error, prefix = '') => {
    const data = error.response?.data
    const fieldErrors = data?.errors

    if (fieldErrors && typeof fieldErrors === 'object') {
        const messages = Object.values(fieldErrors)
            .flat()
            .filter(Boolean)

        if (messages.length) {
            return prefix + messages.join(' ')
        }
    }

    return prefix + (data?.error || data?.message || error.message)
}
