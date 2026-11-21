// YYYY-MM-DD → DD.MM.YYYY (the app's date style for card periods).
export const dmy = (date) =>
    String(date ?? '')
        .split('-')
        .reverse()
        .join('.')
