import { defineStore } from 'pinia'
import api from './api.js'

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: null,
        token: null,
        isAuthenticated: false,
        isLoading: false,
        toast: {
            type: 'success',
            message: '',
        },
    }),

    actions: {
        async login(credentials) {
            this.isLoading = true

            try {
                const response = await api.post('/auth/login', credentials)

                this.user = response.data.user
                this.setToken(response.data.token)

                this.toast = {
                    type: 'success',
                    message: 'Logged in successfully!',
                }
            } catch (error) {
                this.toast = {
                    type: 'error',
                    message: 'Login failed: ' + error.response?.data?.error || error.message,
                }
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

                this.toast = {
                    type: 'success',
                    message: 'Registered successfully!',
                }
            } catch (error) {
                this.toast = {
                    type: 'error',
                    message: 'Registration failed: ' + error.response?.data?.error || error.message,
                }
            } finally {
                this.isLoading = false
            }
        },

        async logout() {
            this.isLoading = true

            try {
                await api.post('/auth/logout')

                this.user = null
                this.token = null
                this.isAuthenticated = false
                localStorage.removeItem('token')

                this.toast = {
                    type: 'info',
                    message: 'Logged out successfully!',
                }
            } catch (error) {
                this.toast = {
                    type: 'error',
                    message: 'Logout failed: ' + error.response?.data?.error || error.message,
                }
            } finally {
                this.isLoading = false
            }
        },

        checkAuth() {
            const token = localStorage.getItem('token')

            if (token) {
                this.setToken(token)
            }
        },

        setToken(token) {
            this.token = token
            this.isAuthenticated = true
            localStorage.setItem('token', token)
        },
    },
})
