import api from './axios'

export const login          = (data) => api.post('/auth/login', data)
export const logout         = ()     => api.post('/auth/logout')
export const getMe          = (signal)     => api.get('/auth/me', { signal })
export const changePassword = (data) => api.post('/auth/change-password', data)
export const forgotPassword = (data) => api.post('/auth/forgot-password', data)
export const resetPassword  = (data) => api.post('/auth/reset-password', data)
