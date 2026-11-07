import { version as APP_VERSION } from '../../package.json'
import api from './api.js'

export const FAQ_URL = 'https://fundsflow.fun/docs/help/faq/'

export const SUPPORT_STATUS = {
    new: { label: 'Sent', badge: 'badge-ghost' },
    in_progress: { label: 'In progress', badge: 'badge-info badge-soft' },
    closed: { label: 'Closed', badge: 'badge-ghost' },
}

export const listSupportTickets = () => api.get('/support/tickets')

export const getSupportTicket = (id) => api.get(`/support/tickets/${id}`)

export const createSupportTicket = ({ subject, body }) =>
    api.post('/support/tickets', { subject, body, app_version: APP_VERSION })

export const replyToSupportTicket = (id, body) => api.post(`/support/tickets/${id}/messages`, { body })
