import api from './axios'

export const getFollowUpSettings    = ()     => api.get('/settings/follow-up')
export const updateFollowUpSettings = (data) => api.put('/settings/follow-up', data)
