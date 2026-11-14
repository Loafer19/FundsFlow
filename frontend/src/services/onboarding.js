import api from './api.js'
import { useAuthStore } from './auth.js'

const STORAGE_KEY = 'onboarding:v1'

export const isOnboardingDoneLocally = () => localStorage.getItem(STORAGE_KEY) === 'done'

export const markOnboardingDone = () => {
    localStorage.setItem(STORAGE_KEY, 'done')
}

export const isOnboardingDoneOnServer = (user) =>
    Boolean(user?.onboarding_completed_at || user?.first_transaction_at)

export const isOnboardingDone = (user) => isOnboardingDoneOnServer(user) || isOnboardingDoneLocally()

export const shouldShowOnboarding = (transactionCount, user) =>
    !isOnboardingDone(user) && transactionCount === 0

export const reportOnboarding = (step) => {
    if (step === 'completed' || step === 'dismissed') {
        markOnboardingDone()
    }

    const auth = useAuthStore()
    if (auth.user) {
        auth.user = {
            ...auth.user,
            onboarding_step: step,
            onboarding_completed_at:
                step === 'completed' || step === 'dismissed'
                    ? auth.user.onboarding_completed_at || new Date().toISOString()
                    : auth.user.onboarding_completed_at,
        }
    }

    return api.patch('/account/onboarding', { step }).then(({ data }) => {
        if (data?.user) {
            auth.user = data.user
        }
        return data
    }).catch(() => {
        // localStorage is the fallback when the request fails
    })
}

export const syncOnboardingFromUser = (user) => {
    if (isOnboardingDoneOnServer(user)) {
        markOnboardingDone()
    }
}
