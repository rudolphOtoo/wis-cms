import api from './axios'

export const getPortalProfile    = ()     => api.get('/portal/profile')
export const getPortalGiving     = (year) => api.get('/portal/giving', { params: { year } })
export const getPortalAttendance = ()     => api.get('/portal/attendance')
