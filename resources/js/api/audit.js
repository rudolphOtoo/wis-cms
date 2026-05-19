import api from './axios'

export const getAuditLog = (params) => api.get('/audit', { params })
