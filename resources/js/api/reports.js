import api from './axios'

export const getIncomeByCategoryReport = (params) =>
  api.get('/reports/finance/income-by-category', { params })
