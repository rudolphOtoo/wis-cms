import api from './axios'

export const login          = (data) => api.post('/auth/login', data)
export const logout         = ()     => api.post('/auth/logout')
export const getMe          = ()     => api.get('/auth/me')
export const getBootstrap   = ()     => api.get('/bootstrap')
export const changePassword = (data) => api.post('/auth/change-password', data)
export const forgotPassword = (data) => api.post('/auth/forgot-password', data)
export const resetPassword  = (data) => api.post('/auth/reset-password', data)
export const keepAlive      = ()     => api.post('/auth/keep-alive')
