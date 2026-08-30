const STORAGE_KEY = 'onboarding:v1'

export const isOnboardingDone = () => localStorage.getItem(STORAGE_KEY) === 'done'

export const markOnboardingDone = () => {
    localStorage.setItem(STORAGE_KEY, 'done')
}

export const shouldShowOnboarding = (transactionCount) => !isOnboardingDone() && transactionCount === 0
