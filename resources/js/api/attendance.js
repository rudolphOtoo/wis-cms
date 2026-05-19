import api from './axios'

export const getAttendance    = (params) => api.get('/attendance', { params })
export const getServiceTypes  = ()       => api.get('/attendance/service-types')
export const getAttendanceStats = ()     => api.get('/attendance/stats')
export const createSession    = (data)   => api.post('/attendance/sessions', data)
export const getSession       = (id)     => api.get(`/attendance/sessions/${id}`)
export const markAttendance   = (id, data) => api.post(`/attendance/sessions/${id}/mark`, data)
