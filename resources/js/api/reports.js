import api from './axios'

export const getIncomeByCategoryReport = (params) =>
  api.get('/reports/finance/income-by-category', { params })

export const getExpenseByCategoryReport = (params) =>
  api.get('/reports/finance/expense-by-category', { params })

export const getCellComparisonReport = (params) =>
  api.get('/reports/cells/comparison', { params })

export const getAttendanceTrendsReport = (params) =>
  api.get('/reports/attendance/trends', { params })

// Downloads — return Blob responses for browser download

export const downloadIncomeByCategoryPdf = (params) =>
  api.get('/reports/finance/income-by-category/export-pdf', { params, responseType: 'blob' })

export const downloadIncomeByCategoryCsv = (params) =>
  api.get('/reports/finance/income-by-category/export-csv', { params, responseType: 'blob' })

export const downloadExpenseByCategoryPdf = (params) =>
  api.get('/reports/finance/expense-by-category/export-pdf', { params, responseType: 'blob' })

export const downloadExpenseByCategoryCsv = (params) =>
  api.get('/reports/finance/expense-by-category/export-csv', { params, responseType: 'blob' })

export const downloadAttendanceTrendsPdf = (params) =>
  api.get('/reports/attendance/trends/export-pdf', { params, responseType: 'blob' })

export const downloadAttendanceTrendsCsv = (params) =>
  api.get('/reports/attendance/trends/export-csv', { params, responseType: 'blob' })

export const downloadCellComparisonPdf = (params) =>
  api.get('/reports/cells/comparison/export-pdf', { params, responseType: 'blob' })

export const downloadCellComparisonCsv = (params) =>
  api.get('/reports/cells/comparison/export-csv', { params, responseType: 'blob' })
