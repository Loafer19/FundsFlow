class Settings {
    #defaults = {
        dateFormat: 'short Month with Day',
        moneyFormat: 'uk-UA',
        decimals: true,
        theme: 'bumblebee',
    }

    #settings = { ...this.#defaults }

    constructor() {
        const savedSettings = this.#getSavedSettings()
        this.#settings = { ...this.#defaults, ...savedSettings }
    }

    #getSavedSettings() {
        const settings = {}

        const dateFormat = localStorage.getItem('format:date')
        if (dateFormat) settings.dateFormat = dateFormat

        const moneyFormat = localStorage.getItem('format:money')
        if (moneyFormat) settings.moneyFormat = moneyFormat

        const decimals = localStorage.getItem('format:money:decimals')
        if (decimals !== null) settings.decimals = JSON.parse(decimals)

        const theme = localStorage.getItem('theme')
        if (theme) settings.theme = theme

        return settings
    }

    #save(key, value) {
        localStorage.setItem(key, typeof value === 'boolean' ? JSON.stringify(value) : value)
    }

    updateDateFormat(format) {
        this.#settings.dateFormat = format
        this.#save('format:date', format)
    }

    updateMoneyFormat(format) {
        this.#settings.moneyFormat = format
        this.#save('format:money', format)
    }

    updateDecimals(decimals) {
        this.#settings.decimals = decimals
        this.#save('format:money:decimals', decimals)
    }

    updateTheme(theme) {
        this.#settings.theme = theme
        this.#save('theme', theme)
    }

    get dateFormat() {
        return this.#settings.dateFormat
    }

    get moneyFormat() {
        return this.#settings.moneyFormat
    }

    get decimals() {
        return this.#settings.decimals
    }

    get theme() {
        return this.#settings.theme
    }
}

export default new Settings()
