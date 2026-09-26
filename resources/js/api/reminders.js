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

// Individual dispatches that are still live, with an explicit per-row
// `on_cloud` flag so the UI can show whether mNotify actually holds the
// message before offering a cancel.
export const getScheduledSms = (params) =>
  api.get('/sms/scheduled', { params })

// Withdraw one scheduled dispatch. This is NOT a local-only operation: the
// server issues DELETE /scheduled/{id} against mNotify (falling back to
// parking the job in 2099) and only then reports back.
//
// The response distinguishes two outcomes, and callers MUST respect it:
//   200 -> { cloud_cancelled: true }  confirmed withdrawn from the provider
//   202 -> { cloud_cancelled: false, retry_queued: true }  mNotify has not
//          confirmed yet; the withdraw is queued and the message must be
//          treated as possibly still active in the cloud.
export const cancelScheduledSms = (deliveryId) =>
  api.post(`/sms/scheduled/${deliveryId}/cancel`)
