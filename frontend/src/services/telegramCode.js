// Mirrors backend App\Support\TelegramCode: 8 chars, no 0/O/1/I/L, shown as K7QM-2XPA.
export const TELEGRAM_CODE_LENGTH = 8

/** Uppercase and keep only code symbols (drops dashes, spaces, look-alikes). */
export const normalizeTelegramCode = (input) =>
    String(input ?? '')
        .toUpperCase()
        .replace(/[^A-HJKMNP-Z2-9]/g, '')
        .slice(0, TELEGRAM_CODE_LENGTH)

/** Grouped display; also usable while typing (dash appears after the 4th char). */
export const formatTelegramCode = (input) => {
    const code = normalizeTelegramCode(input)
    return code.length > 4 ? `${code.slice(0, 4)}-${code.slice(4)}` : code
}
