import api from './axios'

export const getIncomeByCategoryReport = (params) =>
  api.get('/reports/finance/income-by-category', { params })

export const getExpenseByCategoryReport = (params) =>
  api.get('/reports/finance/expense-by-category', { params })

export const getCellComparisonReport = (params) =>
  api.get('/reports/cells/comparison', { params })

export const getAttendanceTrendsReport = (params) =>
  api.get('/reports/attendance/trends', { params })

export const getAttendanceSummaryReport = (params) =>
  api.get('/reports/attendance/summary', { params })

export const getMemberWelfareReport = (params) =>
  api.get('/reports/members/welfare', { params })

// Downloads — return Blob responses for browser download

export const downloadIncomeByCategoryPdf = (params) =>
  api.get('/reports/finance/income-by-category/export-pdf', { params, responseType: 'blob' })

export const downloadIncomeByCategoryXlsx = (params) =>
  api.get('/reports/finance/income-by-category/export-xlsx', { params, responseType: 'blob' })

export const downloadExpenseByCategoryPdf = (params) =>
  api.get('/reports/finance/expense-by-category/export-pdf', { params, responseType: 'blob' })

export const downloadExpenseByCategoryXlsx = (params) =>
  api.get('/reports/finance/expense-by-category/export-xlsx', { params, responseType: 'blob' })

export const downloadAttendanceTrendsPdf = (params) =>
  api.get('/reports/attendance/trends/export-pdf', { params, responseType: 'blob' })

export const downloadAttendanceTrendsXlsx = (params) =>
  api.get('/reports/attendance/trends/export-xlsx', { params, responseType: 'blob' })

export const downloadAttendanceSummaryPdf = (params) =>
  api.get('/reports/attendance/summary/export-pdf', { params, responseType: 'blob' })

export const downloadAttendanceSummaryXlsx = (params) =>
  api.get('/reports/attendance/summary/export-xlsx', { params, responseType: 'blob' })

export const downloadMemberWelfarePdf = (params) =>
  api.get('/reports/members/welfare/export-pdf', { params, responseType: 'blob' })

export const downloadMemberWelfareXlsx = (params) =>
  api.get('/reports/members/welfare/export-xlsx', { params, responseType: 'blob' })

export const downloadCellComparisonPdf = (params) =>
  api.get('/reports/cells/comparison/export-pdf', { params, responseType: 'blob' })

export const downloadCellComparisonXlsx = (params) =>
  api.get('/reports/cells/comparison/export-xlsx', { params, responseType: 'blob' })
