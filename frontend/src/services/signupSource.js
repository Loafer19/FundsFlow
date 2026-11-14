// First-touch attribution for email and OAuth sign-up (UTM query + external referrer).
export const signupSource = () => {
    try {
        const params = new URLSearchParams(window.location.search)
        const utm = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'ref']
            .flatMap((key) => {
                const value = params.get(key)
                return value ? [`${key}=${value}`] : []
            })
            .join('&')
        const referrer = document.referrer
        const external =
            referrer && !referrer.includes(window.location.host) ? `referrer=${referrer}` : ''
        const source = [utm, external].filter(Boolean).join(' | ')

        return source.slice(0, 255) || null
    } catch {
        return null
    }
}

export const withSignupSource = (url) => {
    const source = signupSource()
    if (!source) return url
    const join = url.includes('?') ? '&' : '?'
    return `${url}${join}source=${encodeURIComponent(source)}`
}
