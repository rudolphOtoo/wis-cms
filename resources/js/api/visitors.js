import api from './axios'

export const getVisitors     = (params) => api.get('/visitors', { params })
export const getVisitor      = (id)     => api.get(`/visitors/${id}`)
export const createVisitor   = (data)   => api.post('/visitors', data)
export const updateVisitor   = (id, data) => api.put(`/visitors/${id}`, data)
export const deleteVisitor   = (id)     => api.delete(`/visitors/${id}`)
export const getVisitorStats = ()       => api.get('/visitors/stats')

export const convertVisitor = (id, data) => api.post(`/visitors/${id}/convert`, data)
