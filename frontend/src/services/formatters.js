import Settings from './settings'

export const formatDateOptions = {
    'Year First': ['YYYY/MM/DD'],
    'Month First': ['MM/DD/YYYY', 'MM/DD'],
    Month: ['long Month with Day & Year', 'short Month with Day & Year', 'long Month with Day', 'short Month with Day'],
    'Day First': ['DD-MM-YYYY', 'DD/MM/YYYY', 'DD.MM.YYYY'],
    'Day First (Short)': ['DD-MM', 'DD/MM', 'DD.MM'],
}

export const formatDate = (date) => {
    const formatMap = {
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

    return new Intl.DateTimeFormat(formatMap[Settings.dateFormat].locale, formatMap[Settings.dateFormat]).format(
        new Date(date),
    )
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
        maximumFractionDigits: Settings.decimals ? 2 : 0,
    }

    return new Intl.NumberFormat(Settings.moneyFormat, options).format(amount)
}

export const formatPercentage = (value) => {
    if (value === Number.POSITIVE_INFINITY) return '∞'
    if (value === Number.NEGATIVE_INFINITY) return '-∞'
    if (Number.isNaN(value)) return 'N/A'
    return value.toFixed(1) + '%'
}
