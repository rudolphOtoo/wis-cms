import api from './axios'

export const getPastoralNotes = (params) =>
  api.get('/pastoral-notes', { params })

export const getPastoralFollowUps = (params) =>
  api.get('/pastoral-notes/follow-ups', { params })

export const createPastoralNote = (data) =>
  api.post('/pastoral-notes', data)

export const updatePastoralNote = (id, data) =>
  api.put(`/pastoral-notes/${id}`, data)

export const deletePastoralNote = (id) =>
  api.delete(`/pastoral-notes/${id}`)
