import api from './axios'

export const getDepartments     = (params, signal) => api.get('/departments', { params, signal })
export const getDepartment      = (id, signal)       => api.get(`/departments/${id}`, { signal })
export const createDepartment   = (data)     => api.post('/departments', data)
export const updateDepartment   = (id, data) => api.put(`/departments/${id}`, data)
export const deleteDepartment   = (id)       => api.delete(`/departments/${id}`)
export const getDepartmentStats = (signal)         => api.get('/departments/stats', { signal })
export const getDeptMembers     = (id, signal)       => api.get(`/departments/${id}/members`, { signal })
export const addDeptMember      = (id, data) => api.post(`/departments/${id}/members`, data)
export const removeDeptMember   = (id, mId)  => api.delete(`/departments/${id}/members/${mId}`)
export const messageDepartment  = (id, data) => api.post(`/departments/${id}/message`, data)
