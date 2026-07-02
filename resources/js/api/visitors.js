import api from './axios'

export const getVisitors     = (params, signal) => api.get('/visitors', { params, signal })
export const getVisitor      = (id, signal)     => api.get(`/visitors/${id}`, { signal })
export const createVisitor   = (data)   => api.post('/visitors', data)
export const updateVisitor   = (id, data) => api.put(`/visitors/${id}`, data)
export const deleteVisitor   = (id)     => api.delete(`/visitors/${id}`)
export const getVisitorStats = (signal)       => api.get('/visitors/stats', { signal })

export const convertVisitor = (id, data) => api.post(`/visitors/${id}/convert`, data)
