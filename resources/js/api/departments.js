import api from './axios'

export const getDepartments     = ()         => api.get('/departments')
export const getDepartment      = (id)       => api.get(`/departments/${id}`)
export const createDepartment   = (data)     => api.post('/departments', data)
export const updateDepartment   = (id, data) => api.put(`/departments/${id}`, data)
export const deleteDepartment   = (id)       => api.delete(`/departments/${id}`)
export const getDepartmentStats = ()         => api.get('/departments/stats')
export const getDeptMembers     = (id)       => api.get(`/departments/${id}/members`)
export const addDeptMember      = (id, data) => api.post(`/departments/${id}/members`, data)
export const removeDeptMember   = (id, mId)  => api.delete(`/departments/${id}/members/${mId}`)
export const messageDepartment  = (id, data) => api.post(`/departments/${id}/message`, data)
