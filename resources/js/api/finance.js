import api from './axios'

export const getTransactions      = (params, signal) => api.get('/finance/transactions', { params, signal })
export const getTransaction       = (id)             => api.get(`/finance/transactions/${id}`)
export const createTransaction    = (data)           => api.post('/finance/transactions', data)
export const updateTransaction    = (id, data)       => api.put(`/finance/transactions/${id}`, data)
export const deleteTransaction    = (id)             => api.delete(`/finance/transactions/${id}`)
export const getFinanceStats      = (signal)         => api.get('/finance/stats', { signal })
export const getFinanceCategories = (params, signal) => api.get('/finance/categories', { params, signal })
export const exportTransactions = (params) =>
  api.get('/finance/transactions/export', { params, responseType: 'blob' })

export const downloadLedgerPdf = (params) =>
  api.get('/finance/reports/ledger', { params, responseType: 'blob' })
