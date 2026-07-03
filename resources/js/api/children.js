import api from './axios'

export const getChildren     = (params) => api.get('/children', { params })
export const getChild        = (id)     => api.get(`/children/${id}`)
export const createChild     = (data)   => api.post('/children', data)
export const updateChild     = (id, data) => api.put(`/children/${id}`, data)
export const deleteChild     = (id)     => api.delete(`/children/${id}`)
export const getChildrenStats = ()      => api.get('/children/stats')
