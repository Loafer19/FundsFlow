// Per-user List / period / filter memory. Same key prefix as cache.js so logout clears it.
import { currentUserId } from './cache.js'

const PREFIX = 'ff:cache:v1:'
const NAME = 'ui'

export const defaultUiState = {
    periodType: 'month',
    periodDate: null,
    tagIds: [],
    untagged: false,
    search: '',
    compare: true,
    listSortKey: 'at',
    listSortDirection: 'desc',
    flowGroupBy: 'day',
}

export const readUiState = () => {
    const userId = currentUserId()
    if (userId == null) return { ...defaultUiState }

    try {
        const raw = localStorage.getItem(`${PREFIX}${userId}:${NAME}`)
        if (!raw) return { ...defaultUiState }

        const parsed = JSON.parse(raw)

        return { ...defaultUiState, ...(parsed && typeof parsed === 'object' ? parsed : {}) }
    } catch {
        return { ...defaultUiState }
    }
}

export const patchUiState = (partial) => {
    const userId = currentUserId()
    if (userId == null) return

    try {
        localStorage.setItem(`${PREFIX}${userId}:${NAME}`, JSON.stringify({ ...readUiState(), ...partial }))
    } catch {
        // QuotaExceeded or private mode — in-memory state still works.
    }
}
