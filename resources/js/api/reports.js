import api from './axios'

export const getIncomeByCategoryReport = (params) =>
  api.get('/reports/finance/income-by-category', { params })

export const getExpenseByCategoryReport = (params) =>
  api.get('/reports/finance/expense-by-category', { params })

export const getCellComparisonReport = (params) =>
  api.get('/reports/cells/comparison', { params })

export const getAttendanceTrendsReport = (params) =>
  api.get('/reports/attendance/trends', { params })
