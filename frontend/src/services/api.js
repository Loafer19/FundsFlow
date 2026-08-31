import axios from 'axios'
import toasts from './toasts.js'

const api = axios.create({
    baseURL: import.meta.env.VITE_API_URL,
    timeout: 5000,
})

const IDEMPOTENT = new Set(['get', 'head', 'options'])
const MAX_RETRY_AFTER_SEC = 30

/** @returns {number|null} seconds to wait */
export const parseRetryAfter = (value) => {
    if (value == null || value === '') return null

    const asNumber = Number(value)
    if (Number.isFinite(asNumber)) return Math.max(0, Math.ceil(asNumber))

    const asDate = Date.parse(value)
    if (Number.isFinite(asDate)) return Math.max(0, Math.ceil((asDate - Date.now()) / 1000))

    return null
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

api.interceptors.request.use(
    (config) => {
        const token = localStorage.getItem('token')

        if (token) {
            config.headers.Authorization = `Bearer ${token}`
        } else {
            delete config.headers.Authorization
        }

        return config
    },
    (error) => Promise.reject(error),
)

api.interceptors.response.use(
    (response) => response,
    async (error) => {
        const { config, response } = error
        const status = response?.status
        const hadToken = Boolean(config?.headers?.Authorization)

        if (status === 401 && hadToken) {
            import('./auth.js').then(({ useAuthStore }) => {
                const auth = useAuthStore()

                if (auth.isAuthenticated || auth.token) {
                    auth.clearSession()
                    toasts.info('Session expired, please log in again')
                }
            })

            return Promise.reject(error)
        }

        if (status === 429 && config && !config.__retry429) {
            const retryAfter =
                parseRetryAfter(response.headers?.['retry-after']) ??
                parseRetryAfter(response.headers?.['Retry-After']) ??
                1

            error.retryAfter = retryAfter

            const method = (config.method || 'get').toLowerCase()

            if (IDEMPOTENT.has(method)) {
                config.__retry429 = true
                const waitSec = Math.min(retryAfter, MAX_RETRY_AFTER_SEC)

                toasts.info(`Too many requests. Retrying in ${waitSec}s…`)
                await sleep(waitSec * 1000)

                return api.request(config)
            }
        } else if (status === 429 && response) {
            error.retryAfter =
                parseRetryAfter(response.headers?.['retry-after']) ??
                parseRetryAfter(response.headers?.['Retry-After']) ??
                error.retryAfter ??
                1
        }

        return Promise.reject(error)
    },
)

export default api
