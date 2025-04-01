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
                const response = await api.post('/login', credentials)

                this.user = response.data.user
                this.token = response.data.token
                this.isAuthenticated = true
                localStorage.setItem('token', this.token)

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
            try {
                const response = await api.post('/register', credentials)

                this.user = response.data.user
                this.token = response.data.token
                this.isAuthenticated = true
                localStorage.setItem('token', this.token)

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
            try {
                await api.post('/logout')

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
                this.token = token
                this.isAuthenticated = true
            }
        },
    },
})
