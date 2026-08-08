import api from './axios'

export const getAttendance         = (params, signal) => api.get('/attendance', { params, signal })
export const getServiceTypes       = ()               => api.get('/attendance/service-types')
export const getAttendanceStats    = (signal)         => api.get('/attendance/stats', { signal })
export const getSundayAttendance   = (params, signal) => api.get('/attendance/sundays', { params, signal })
export const createSession         = (data)           => api.post('/attendance/sessions', data)
export const getSession            = (id)             => api.get(`/attendance/sessions/${id}`)
export const markAttendance        = (id, data)       => api.post(`/attendance/sessions/${id}/mark`, data)
export const markHeadcount         = (id, data)       => api.post(`/attendance/sessions/${id}/headcount`, data)
