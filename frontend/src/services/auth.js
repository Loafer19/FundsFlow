import { defineStore } from 'pinia'
import api from './api.js'
import { clearUserCache } from './cache.js'
import { apiErrorMessage } from './formatters.js'
import { applyUserPreferences } from './settings.js'
import { signupSource } from './signupSource.js'
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
                applyUserPreferences(this.user?.preferences)

                toasts.success('Logged in!')

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Could not log in: '))

                return false
            } finally {
                this.isLoading = false
            }
        },

        async register(credentials) {
            this.isLoading = true

            try {
                const response = await api.post('/auth/register', { ...credentials, source: signupSource() })

                this.user = response.data.user
                this.setToken(response.data.token)
                applyUserPreferences(this.user?.preferences)

                toasts.success('Registered!')

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Could not register: '))

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
                applyUserPreferences(this.user?.preferences)

                toasts.success('Logged in!')

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Could not log in: '))

                return false
            } finally {
                this.isLoading = false
            }
        },

        async loginWithTelegramWebApp(initData) {
            this.isLoading = true

            try {
                const response = await api.post('/auth/telegram-webapp', { initData })

                if (response.data.needs_auth) {
                    return 'needs_auth'
                }

                this.user = response.data.user
                this.setToken(response.data.token)
                applyUserPreferences(this.user?.preferences)

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Could not log in with Telegram: '))

                return false
            } finally {
                this.isLoading = false
            }
        },

        async linkTelegramWebApp(initData) {
            try {
                const response = await api.post('/auth/telegram-webapp/link', { initData })

                this.user = response.data.user

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Could not link Telegram: '))

                return false
            }
        },

        async logout() {
            this.isLoading = true

            try {
                await api.post('/auth/logout')

                this.clearSession()

                toasts.info('Logged out!')
            } catch (error) {
                this.clearSession()

                toasts.error(apiErrorMessage(error, 'Could not log out: '))
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
                applyUserPreferences(this.user?.preferences)

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
