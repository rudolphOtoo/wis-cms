import api from './axios'

export const getMembers     = (params) => api.get('/members', { params })
export const getMember      = (id)     => api.get(`/members/${id}`)
export const createMember   = (data)   => api.post('/members', data)
export const updateMember   = (id, data) => api.put(`/members/${id}`, data)
export const deleteMember   = (id)     => api.delete(`/members/${id}`)
export const getMemberStats = ()       => api.get('/members/stats')

export const getMemberGiving = (id, year) => api.get(`/members/${id}/giving`, { params: { year } })
export const downloadGivingStatement = (id, year) =>
  api.get(`/members/${id}/giving-statement`, { params: { year }, responseType: 'blob' })

export const exportMembers = (params) =>
  api.get('/members/export', { params, responseType: 'blob' })
export const promoteMemberToLeader = (id, data) => api.post(`/members/${id}/promote-to-leader`, data)
export const createMemberLogin = (id, data) => api.post(`/members/${id}/create-login`, data)
