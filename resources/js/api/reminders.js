import api from './axios'

// List all service types with their reminder config (or default
// suggestion if no row exists yet). One unified shape.
export const getReminderSettings = () =>
  api.get('/reminders/settings')

// Create-or-update reminder settings for one service type.
export const upsertReminderSettings = (serviceTypeId, data) =>
  api.put(`/reminders/settings/${serviceTypeId}`, data)

// Live preview of a (possibly unsaved) template.
// payload: { template, service_type_id, service_hour, service_minute }
export const previewReminder = (payload) =>
  api.post('/reminders/preview', payload)

// Next 7 days of scheduled fires for the branch.
export const getUpcomingReminders = () =>
  api.get('/reminders/upcoming')

// Audit log with optional filters: { days, status, service_type_id }
export const getReminderLog = (params) =>
  api.get('/reminders/log', { params })
