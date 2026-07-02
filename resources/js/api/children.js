import api from './axios'

export const getChildren     = (params, signal) => api.get('/children', { params, signal })
export const getChild        = (id, signal)     => api.get(`/children/${id}`, { signal })
export const createChild     = (data)   => api.post('/children', data)
export const updateChild     = (id, data) => api.put(`/children/${id}`, data)
export const deleteChild     = (id)     => api.delete(`/children/${id}`)
export const getChildrenStats = (signal)      => api.get('/children/stats', { signal })
