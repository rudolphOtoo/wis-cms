import api from './axios'

export const getLifeEvents = (params) => api.get('/life-events', { params })
export const getLifeEvent = (id) => api.get(`/life-events/${id}`)
export const getLifeEventsStats = (params) => api.get('/life-events/stats', { params })
export const createLifeEvent = (data) => api.post('/life-events', data)
export const updateLifeEvent = (id, data) => api.put(`/life-events/${id}`, data)
export const deleteLifeEvent = (id) => api.delete(`/life-events/${id}`)
