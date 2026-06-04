import api from './axios'

export const getIncomeByCategoryReport = (params) =>
  api.get('/reports/finance/income-by-category', { params })

export const getAttendanceTrendsReport = (params) =>
  api.get('/reports/attendance/trends', { params })
