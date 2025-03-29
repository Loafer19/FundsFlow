import axios from 'axios'

const api = axios.create({
    baseURL: import.meta.env.VITE_API_URL,
    timeout: 5000,
    withCredentials: true,
})

api.interceptors.request.use(
    (config) => {
        const token = localStorage.getItem('token')

        config.headers.Authorization = `Bearer ${token}`

        return config
    },
    (error) => Promise.reject(error),
)

api.interceptors.response.use(
    (response) => {
        return response
    },
    (error) => {
        if (error.response?.status === 401) {
            localStorage.removeItem('token')
        }

        return Promise.reject(error)
    },
)

export default api
