import api from './axios'

export const getUsers      = (params, signal) => api.get('/users', { params, signal })
export const getUserRoles  = ()       => api.get('/users/roles')
export const getUser       = (id)     => api.get(`/users/${id}`)
export const createUser    = (data)   => api.post('/users', data)
export const updateUser    = (id, data) => api.put(`/users/${id}`, data)
export const deleteUser    = (id)     => api.delete(`/users/${id}`)
export const linkMember         = (userId, memberId) => api.post(`/users/${userId}/link-member`, { member_id: memberId })
export const createAndLinkMember = (userId, data)    => api.post(`/users/${userId}/create-and-link`, data)
export const unlinkMember       = (userId)           => api.delete(`/users/${userId}/link-member`)
