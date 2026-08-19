import api from './axios'

export const initializePayment     = (data)              => api.post('/payments/initialize', data)
export const verifyPayment         = (reference)         => api.get(`/payments/verify/${reference}`)
export const getPayments           = (params, signal)    => api.get('/payments/history', { params, signal })
export const getPaymentStats       = (signal)            => api.get('/payments/stats', { signal })
