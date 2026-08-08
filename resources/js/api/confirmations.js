import api from './axios'

export const getConfirmations = (params) =>
  api.get('/confirmations', { params })

export const createConfirmation = (data) =>
  api.post('/confirmations', data)

export const deleteConfirmation = (id) =>
  api.delete(`/confirmations/${id}`)
