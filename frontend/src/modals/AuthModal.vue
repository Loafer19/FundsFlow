<template>
    <dialog id="auth_modal" class="modal">
        <div class="modal-box max-w-sm">
            <div class="tabs tabs-box justify-center mb-4">
                <a class="tab tab-bordered" :class="{ 'tab-active': !isRegister }" @click="isRegister = false">Login</a>
                <a class="tab tab-bordered" :class="{ 'tab-active': isRegister }"
                    @click="isRegister = true">Register</a>
            </div>

            <h2 class="card-title mb-4">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                    <path fill-rule="evenodd" clip-rule="evenodd"
                        d="M12 1.25C9.37666 1.25 7.25001 3.37665 7.25001 6C7.25001 8.62335 9.37666 10.75 12 10.75C14.6234 10.75 16.75 8.62335 16.75 6C16.75 3.37665 14.6234 1.25 12 1.25ZM8.75001 6C8.75001 4.20507 10.2051 2.75 12 2.75C13.7949 2.75 15.25 4.20507 15.25 6C15.25 7.79493 13.7949 9.25 12 9.25C10.2051 9.25 8.75001 7.79493 8.75001 6Z" />
                    <path fill-rule="evenodd" clip-rule="evenodd"
                        d="M12 12.25C9.68646 12.25 7.55494 12.7759 5.97546 13.6643C4.4195 14.5396 3.25001 15.8661 3.25001 17.5L3.24995 17.602C3.24882 18.7638 3.2474 20.222 4.52642 21.2635C5.15589 21.7761 6.03649 22.1406 7.22622 22.3815C8.41927 22.6229 9.97424 22.75 12 22.75C14.0258 22.75 15.5808 22.6229 16.7738 22.3815C17.9635 22.1406 18.8441 21.7761 19.4736 21.2635C20.7526 20.222 20.7512 18.7638 20.7501 17.602L20.75 17.5C20.75 15.8661 19.5805 14.5396 18.0246 13.6643C16.4451 12.7759 14.3136 12.25 12 12.25ZM4.75001 17.5C4.75001 16.6487 5.37139 15.7251 6.71085 14.9717C8.02681 14.2315 9.89529 13.75 12 13.75C14.1047 13.75 15.9732 14.2315 17.2892 14.9717C18.6286 15.7251 19.25 16.6487 19.25 17.5C19.25 18.8078 19.2097 19.544 18.5264 20.1004C18.1559 20.4022 17.5365 20.6967 16.4762 20.9113C15.4193 21.1252 13.9742 21.25 12 21.25C10.0258 21.25 8.58075 21.1252 7.5238 20.9113C6.46354 20.6967 5.84413 20.4022 5.4736 20.1004C4.79033 19.544 4.75001 18.8078 4.75001 17.5Z" />
                </svg>
                {{ isRegister ? 'Register' : 'Login' }}
            </h2>

            <form @submit.prevent="handleSubmit">
                <input v-if="isRegister" type="text" v-model="credentials.name" placeholder="Name"
                    class="input w-full mb-4" required />

                <input type="text" v-model="credentials.email" placeholder="Email" class="input w-full mb-4" required />

                <input type="password" v-model="credentials.password" placeholder="Password" class="input w-full"
                    required />

                <div class="modal-action">
                    <button type="submit" class="btn btn-success" :disabled="isSubmitting">
                        <span v-if="isSubmitting" class="loading loading-spinner"></span>

                        {{ isRegister ? 'Register' : 'Login' }}

                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"
                            xmlns="http://www.w3.org/2000/svg">
                            <path fill-rule="evenodd" clip-rule="evenodd"
                                d="M12 1.25C9.37666 1.25 7.25001 3.37665 7.25001 6C7.25001 8.62335 9.37666 10.75 12 10.75C14.6234 10.75 16.75 8.62335 16.75 6C16.75 3.37665 14.6234 1.25 12 1.25ZM8.75001 6C8.75001 4.20507 10.2051 2.75 12 2.75C13.7949 2.75 15.25 4.20507 15.25 6C15.25 7.79493 13.7949 9.25 12 9.25C10.2051 9.25 8.75001 7.79493 8.75001 6Z" />
                            <path
                                d="M19.8555 14.5729C20.1528 14.8614 20.1599 15.3362 19.8714 15.6334L18.0382 17.5223C17.8901 17.6749 17.6842 17.7575 17.4717 17.7495C17.2592 17.7414 17.0601 17.6436 16.9239 17.4802L16.0904 16.4802C15.8252 16.162 15.8681 15.6891 16.1863 15.4239C16.5045 15.1587 16.9774 15.2016 17.2426 15.5198L17.5424 15.8794L18.795 14.5888C19.0835 14.2915 19.5583 14.2844 19.8555 14.5729Z" />
                            <path fill-rule="evenodd" clip-rule="evenodd"
                                d="M14.7747 12.5129C13.9019 12.3421 12.9685 12.25 12 12.25C9.68646 12.25 7.55494 12.7759 5.97546 13.6643C4.41949 14.5396 3.25001 15.8661 3.25001 17.5L3.24995 17.602C3.24882 18.7638 3.2474 20.222 4.52642 21.2635C5.15589 21.7761 6.03649 22.1406 7.22622 22.3815C8.41927 22.6229 9.97424 22.75 12 22.75C14.8681 22.75 16.8099 22.4961 18.1196 22.0085C19.2985 21.5697 19.9973 20.9266 20.3704 20.1172C21.7927 19.2966 22.75 17.7601 22.75 16C22.75 13.3766 20.6234 11.25 18 11.25C16.7549 11.25 15.6217 11.7291 14.7747 12.5129ZM14.75 16C14.75 14.2051 16.2051 12.75 18 12.75C19.7949 12.75 21.25 14.2051 21.25 16C21.25 17.7949 19.7949 19.25 18 19.25C16.2051 19.25 14.75 17.7949 14.75 16ZM13.7557 13.865C13.4322 14.5069 13.25 15.2322 13.25 16C13.25 18.3893 15.0141 20.3666 17.3108 20.7004C16.2401 21.0366 14.578 21.25 12 21.25C10.0258 21.25 8.58075 21.1252 7.5238 20.9113C6.46354 20.6967 5.84413 20.4022 5.4736 20.1004C4.79033 19.544 4.75001 18.8078 4.75001 17.5C4.75001 16.6487 5.37139 15.7251 6.71085 14.9717C8.02681 14.2315 9.89529 13.75 12 13.75C12.6061 13.75 13.194 13.79 13.7557 13.865Z" />
                        </svg>
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
import { ref } from 'vue'
import { useAuthStore } from './../services/auth.js'

const authStore = useAuthStore()

const isRegister = ref(false)
const isSubmitting = ref(false)

const credentials = ref({
    name: '',
    email: '',
    password: '',
})

const handleSubmit = async () => {
    isSubmitting.value = true

    if (isRegister.value) {
        await authStore.register(credentials.value)
    } else {
        await authStore.login(credentials.value)
    }

    isSubmitting.value = false

    if (authStore.isAuthenticated) {
        credentials.value = { name: '', email: '', password: '' }

        auth_modal.close()
    }
}
</script>
