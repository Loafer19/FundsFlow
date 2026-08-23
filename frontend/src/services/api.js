import axios from 'axios'
import toasts from './toasts.js'

const api = axios.create({
    baseURL: import.meta.env.VITE_API_URL,
    timeout: 5000,
})

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
    (error) => {
        const hadToken = Boolean(error.config?.headers?.Authorization)

        if (error.response?.status === 401 && hadToken) {
            import('./auth.js').then(({ useAuthStore }) => {
                const auth = useAuthStore()

                if (auth.isAuthenticated || auth.token) {
                    auth.clearSession()
                    toasts.info('Session expired, please log in again')
                }
            })
        }

        return Promise.reject(error)
    },
)

export default api
