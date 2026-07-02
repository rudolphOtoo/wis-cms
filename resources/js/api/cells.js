import api from './axios'

export const getCells         = (params, signal) => api.get('/cells', { params, signal })
export const getCell          = (id, signal)      => api.get(`/cells/${id}`, { signal })
export const createCell       = (data)    => api.post('/cells', data)
export const updateCell       = (id, data)=> api.put(`/cells/${id}`, data)
export const deleteCell       = (id)      => api.delete(`/cells/${id}`)
export const assignToCell     = (id, mId) => api.post(`/cells/${id}/members/${mId}`)
export const unassignFromCell = (id, mId) => api.delete(`/cells/${id}/members/${mId}`)
export const messageCell      = (id, data)=> api.post(`/cells/${id}/message`, data)
