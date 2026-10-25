// Small built-in set so a new tag gets a random emoji without loading the full emoji dataset.
const DEFAULT_EMOJIS = [
    '🏷️',
    '💰',
    '🛒',
    '🍔',
    '☕',
    '🚗',
    '🏠',
    '💡',
    '🎬',
    '💊',
    '🎁',
    '✈️',
    '📚',
    '👕',
    '🐾',
    '💼',
    '🎮',
    '🧾',
    '🍕',
    '🚕',
]

export const randomEmoji = () => DEFAULT_EMOJIS[(Math.random() * DEFAULT_EMOJIS.length) | 0]
