import { defineStore } from 'pinia'
import api from './api.js'
import { clearUserCache } from './cache.js'
import { apiErrorMessage } from './formatters.js'
import toasts from './toasts.js'

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: null,
        token: null,
        isAuthenticated: false,
        isLoading: false,
    }),

    actions: {
        async login(credentials) {
            this.isLoading = true

            try {
                const response = await api.post('/auth/login', credentials)

                this.user = response.data.user
                this.setToken(response.data.token)

                toasts.success('Logged in successfully!')

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Login failed: '))

                return false
            } finally {
                this.isLoading = false
            }
        },

        async register(credentials) {
            this.isLoading = true

            try {
                const response = await api.post('/auth/register', credentials)

                this.user = response.data.user
                this.setToken(response.data.token)

                toasts.success('Registered successfully!')

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Registration failed: '))

                return false
            } finally {
                this.isLoading = false
            }
        },

        async loginWithTelegramCode(code) {
            this.isLoading = true

            try {
                const response = await api.post('/auth/telegram-code', { code })

                this.user = response.data.user
                this.setToken(response.data.token)

                toasts.success('Logged in successfully!')

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Login failed: '))

                return false
            } finally {
                this.isLoading = false
            }
        },

        async logout() {
            this.isLoading = true

            try {
                await api.post('/auth/logout')

                this.clearSession()

                toasts.info('Logged out successfully!')
            } catch (error) {
                this.clearSession()

                toasts.error(apiErrorMessage(error, 'Logout failed: '))
            } finally {
                this.isLoading = false
            }
        },

        async checkAuth() {
            const token = localStorage.getItem('token')

            if (!token) {
                this.clearSession()
                return false
            }

            this.token = token

            try {
                const response = await api.get('/auth/me')

                this.user = response.data.user
                this.isAuthenticated = true

                return true
            } catch {
                this.clearSession()

                return false
            }
        },

        setToken(token) {
            this.token = token
            this.isAuthenticated = true
            localStorage.setItem('token', token)
        },

        clearSession() {
            const userId = this.user?.id

            this.user = null
            this.token = null
            this.isAuthenticated = false
            localStorage.removeItem('token')
            clearUserCache(userId)
        },
    },
})
