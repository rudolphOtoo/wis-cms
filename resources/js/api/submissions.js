import api from './axios'

// List all submissions for the branch.
// params: { status?, page? }
export const getSubmissions = (params = {}) =>
  api.get('/submissions', { params })

// Get full detail of one submission.
export const getSubmission = (id) =>
  api.get(`/submissions/${id}`)

// Approve a submission, optionally with a cell assignment + notes.
// data: { cell_id?, notes? }
export const approveSubmission = (id, data = {}) =>
  api.post(`/submissions/${id}/approve`, data)

// Reject a submission, optionally with notes.
// data: { notes? }
export const rejectSubmission = (id, data = {}) =>
  api.post(`/submissions/${id}/reject`, data)
