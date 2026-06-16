import api from './axios'

export const getTransactions     = (params) => api.get('/finance/transactions', { params })
export const getTransaction      = (id)     => api.get(`/finance/transactions/${id}`)
export const createTransaction   = (data)   => api.post('/finance/transactions', data)
export const updateTransaction   = (id, data) => api.put(`/finance/transactions/${id}`, data)
export const deleteTransaction   = (id)     => api.delete(`/finance/transactions/${id}`)
export const getFinanceStats     = ()       => api.get('/finance/stats')
export const getFinanceCategories = (params) => api.get('/finance/categories', { params })
export const exportTransactions = (params) =>
  api.get('/finance/transactions/export', { params, responseType: 'blob' })

export const downloadLedgerPdf = (params) =>
  api.get('/finance/reports/ledger', { params, responseType: 'blob' })
