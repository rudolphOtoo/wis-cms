import api from './axios'

export const getBirthdaySettings  = ()         => api.get('/birthdays/settings')
export const updateBirthdaySettings = (data)   => api.put('/birthdays/settings', data)
export const previewBirthdayMessage = (template) => api.post('/birthdays/preview', { template })
export const getUpcomingBirthdays = (days = 7) => api.get('/birthdays/upcoming', { params: { days } })
export const getBirthdayLog       = (params)   => api.get('/birthdays/log', { params })
