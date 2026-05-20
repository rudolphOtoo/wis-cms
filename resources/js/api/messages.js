import api from './axios'

export const getMessages         = (params) => api.get('/messages', { params })
export const getMessage          = (id)     => api.get(`/messages/${id}`)
export const sendMessage         = (data)   => api.post('/messages/send', data)
export const previewRecipients   = (data)   => api.post('/messages/recipient-count', data)
export const getMessageStats     = ()       => api.get('/messages/stats')
