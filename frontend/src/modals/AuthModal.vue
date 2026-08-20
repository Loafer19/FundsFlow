<template>
    <dialog id="auth_modal" class="modal">
        <div class="modal-box max-w-sm">
            <div class="tabs tabs-box justify-center mb-4">
                <a class="tab tab-bordered" :class="{ 'tab-active': !isRegister }" @click="isRegister = false">Login</a>
                <a class="tab tab-bordered" :class="{ 'tab-active': isRegister }"
                    @click="isRegister = true">Register</a>
            </div>

            <h2 class="card-title mb-4">
                <UserPlus v-if="isRegister" :size="24" />
                <User v-else :size="24" />
                {{ isRegister ? 'Register' : 'Login' }}
            </h2>

            <form @submit.prevent="handleSubmit">
                <input v-if="isRegister" type="text" v-model="credentials.name" placeholder="Name"
                    class="input w-full mb-4" maxlength="255" required />

                <input type="email" v-model="credentials.email" placeholder="Email" class="input w-full mb-4"
                    maxlength="255" required />

                <input type="password" v-model="credentials.password" placeholder="Password" class="input w-full"
                    minlength="8" maxlength="255" required />

                <div class="modal-action justify-between">
                    <div class="flex gap-2">
                        <a :href="googleAuthUrl" class="btn btn-ghost btn-square">
                            <svg height="30" width="30" viewBox="-0.5 0 48 48" version="1.1">
                                <path
                                    d="M9.82727273,24 C9.82727273,22.4757333 10.0804318,21.0144 10.5322727,19.6437333 L2.62345455,13.6042667 C1.08206818,16.7338667 0.213636364,20.2602667 0.213636364,24 C0.213636364,27.7365333 1.081,31.2608 2.62025,34.3882667 L10.5247955,28.3370667 C10.0772273,26.9728 9.82727273,25.5168 9.82727273,24"
                                    fill="#FBBC05">
                                </path>
                                <path
                                    d="M23.7136364,10.1333333 C27.025,10.1333333 30.0159091,11.3066667 32.3659091,13.2266667 L39.2022727,6.4 C35.0363636,2.77333333 29.6954545,0.533333333 23.7136364,0.533333333 C14.4268636,0.533333333 6.44540909,5.84426667 2.62345455,13.6042667 L10.5322727,19.6437333 C12.3545909,14.112 17.5491591,10.1333333 23.7136364,10.1333333"
                                    fill="#EB4335">
                                </path>
                                <path
                                    d="M23.7136364,37.8666667 C17.5491591,37.8666667 12.3545909,33.888 10.5322727,28.3562667 L2.62345455,34.3946667 C6.44540909,42.1557333 14.4268636,47.4666667 23.7136364,47.4666667 C29.4455,47.4666667 34.9177955,45.4314667 39.0249545,41.6181333 L31.5177727,35.8144 C29.3995682,37.1488 26.7323182,37.8666667 23.7136364,37.8666667"
                                    fill="#34A853">
                                </path>
                                <path
                                    d="M46.1454545,24 C46.1454545,22.6133333 45.9318182,21.12 45.6113636,19.7333333 L23.7136364,19.7333333 L23.7136364,28.8 L36.3181818,28.8 C35.6879545,31.8912 33.9724545,34.2677333 31.5177727,35.8144 L39.0249545,41.6181333 C43.3393409,37.6138667 46.1454545,31.6490667 46.1454545,24"
                                    fill="#4285F4">
                                </path>
                            </svg>
                        </a>
                        <a :href="githubAuthUrl" class="btn btn-ghost btn-square">
                            <svg height="33" width="33" viewBox="0 0 24 24" version="1.1">
                                <path
                                    d="M12 1C5.9225 1 1 5.9225 1 12C1 16.8675 4.14875 20.9787 8.52125 22.4362C9.07125 22.5325 9.2775 22.2025 9.2775 21.9137C9.2775 21.6525 9.26375 20.7862 9.26375 19.865C6.5 20.3737 5.785 19.1912 5.565 18.5725C5.44125 18.2562 4.905 17.28 4.4375 17.0187C4.0525 16.8125 3.5025 16.3037 4.42375 16.29C5.29 16.2762 5.90875 17.0875 6.115 17.4175C7.105 19.0812 8.68625 18.6137 9.31875 18.325C9.415 17.61 9.70375 17.1287 10.02 16.8537C7.5725 16.5787 5.015 15.63 5.015 11.4225C5.015 10.2262 5.44125 9.23625 6.1425 8.46625C6.0325 8.19125 5.6475 7.06375 6.2525 5.55125C6.2525 5.55125 7.17375 5.2625 9.2775 6.67875C10.1575 6.43125 11.0925 6.3075 12.0275 6.3075C12.9625 6.3075 13.8975 6.43125 14.7775 6.67875C16.8813 5.24875 17.8025 5.55125 17.8025 5.55125C18.4075 7.06375 18.0225 8.19125 17.9125 8.46625C18.6138 9.23625 19.04 10.2125 19.04 11.4225C19.04 15.6437 16.4688 16.5787 14.0213 16.8537C14.42 17.1975 14.7638 17.8575 14.7638 18.8887C14.7638 20.36 14.75 21.5425 14.75 21.9137C14.75 22.2025 14.9563 22.5462 15.5063 22.4362C19.8513 20.9787 23 16.8537 23 12C23 5.9225 18.0775 1 12 1Z">
                                </path>
                            </svg>
                        </a>
                    </div>

                    <button type="submit" class="btn btn-success" :disabled="authStore.isLoading">
                        <span v-if="authStore.isLoading" class="loading loading-spinner"></span>

                        {{ isRegister ? 'Register' : 'Login' }}

                        <UserPlus v-if="isRegister" :size="24" />
                        <LogIn v-else :size="24" />
                    </button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button>close</button>
        </form>
    </dialog>
</template>

<script setup>
import { LogIn, User, UserPlus } from 'lucide-vue-next'
import { ref } from 'vue'
import { useAuthStore } from './../services/auth.js'
import toasts from './../services/toasts.js'

const googleAuthUrl = import.meta.env.VITE_API_URL + '/auth/google'
const githubAuthUrl = import.meta.env.VITE_API_URL + '/auth/github'

const authStore = useAuthStore()

const isRegister = ref(false)

const credentials = ref({
    name: '',
    email: '',
    password: '',
})

const consumeAuthHash = async () => {
    const params = new URLSearchParams(window.location.hash.replace(/^#/, ''))
    const token = params.get('token')
    const authError = params.get('auth_error')

    if (token) {
        authStore.setToken(token)
        await authStore.checkAuth()
        window.history.replaceState({}, '', window.location.pathname + window.location.search)
    }

    if (authError) {
        toasts.error(authError)
        window.history.replaceState({}, '', window.location.pathname + window.location.search)
    }
}

consumeAuthHash()

const handleSubmit = async () => {
    const ok = isRegister.value ? await authStore.register(credentials.value) : await authStore.login(credentials.value)

    if (!ok) return

    credentials.value = { name: '', email: '', password: '' }
    auth_modal.close()
}
</script>
