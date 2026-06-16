import { useEffect, useState, useCallback } from 'react'
import {
  getReminderSettings,
  upsertReminderSettings,
  previewReminder,
  getUpcomingReminders,
  getReminderLog,
} from '../../api/reminders'

const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']
const HOURS = Array.from({ length: 24 }, (_, i) => i)
const MINUTES = [0, 15, 30, 45]

function formatHour12(h) {
  if (h === 0) return '12:00 AM'
  if (h === 12) return '12:00 PM'
  return h < 12 ? `${h}:00 AM` : `${h - 12}:00 PM`
}

function formatTime12(h, m) {
  const mm = String(m).padStart(2, '0')
  if (h === 0) return `12:${mm} AM`
  if (h === 12) return `12:${mm} PM`
  return h < 12 ? `${h}:${mm} AM` : `${h - 12}:${mm} PM`
}

function ConfigCard({ row, onSaved }) {
  const [editing, setEditing] = useState(false)
  const [form, setForm] = useState({
    template: row.template ?? row.default_template_suggestion,
    send_day_of_week: row.send_day_of_week ?? 6,
    send_hour: row.send_hour ?? 20,
    service_hour: row.service_hour ?? 9,
    service_minute: row.service_minute ?? 0,
    is_active: row.is_active ?? true,
  })
  const [saving, setSaving] = useState(false)
  const [errors, setErrors] = useState({})
  const [preview, setPreview] = useState('')
  const [charInfo, setCharInfo] = useState({ count: 0, segments: 0 })

  const fetchPreview = useCallback(async () => {
    try {
      const res = await previewReminder({
        template: form.template,
        service_type_id: row.service_type_id,
        service_hour: form.service_hour,
        service_minute: form.service_minute,
      })
      setPreview(res.data.data.rendered)
      setCharInfo({
        count: res.data.data.char_count,
        segments: res.data.data.sms_segments,
      })
    } catch {
      setPreview('(preview unavailable)')
    }
  }, [form.template, form.service_hour, form.service_minute, row.service_type_id])

  useEffect(() => {
    if (!editing) return
    const t = setTimeout(fetchPreview, 350)
    return () => clearTimeout(t)
  }, [editing, fetchPreview])

  const set = (k) => (e) => {
    const v = e.target.type === 'checkbox' ? e.target.checked
            : ['send_day_of_week', 'send_hour', 'service_hour', 'service_minute'].includes(k)
              ? Number(e.target.value) : e.target.value
    setForm(f => ({ ...f, [k]: v }))
    setErrors(er => ({ ...er, [k]: null }))
  }

  const save = async () => {
    setSaving(true)
    setErrors({})
    try {
      await upsertReminderSettings(row.service_type_id, form)
      setEditing(false)
      onSaved()
    } catch (err) {
      if (err.response?.status === 422) {
        setErrors(err.response.data.errors ?? {})
      } else {
        alert(err.response?.data?.message ?? 'Could not save settings.')
      }
    } finally {
      setSaving(false)
    }
  }

  const card = {
    backgroundColor: '#fff',
    border: '1px solid var(--color-surface-border)',
    borderRadius: '14px',
    boxShadow: '0 2px 8px rgba(13,31,60,0.04)',
    padding: '20px',
    marginBottom: '12px',
  }

  return (
    <div style={card}>
      <div className="flex items-start justify-between gap-3">
        <div>
          <h3 className="font-bold" style={{ color: 'var(--color-navy)', fontSize: '16px' }}>
            {row.service_type_name}
          </h3>
          <p className="text-sm mt-1" style={{ color: '#6b7280' }}>
            {row.configured ? (
              <>
                Fires {row.send_day_label} at {row.send_hour_label} for service at {row.service_time_label}
                {!row.is_active && <span style={{ color: '#b91c1c', marginLeft: '8px' }}>(paused)</span>}
              </>
            ) : (
              <span style={{ color: '#9ca3af' }}>Not configured yet</span>
            )}
          </p>
        </div>
        {!editing && (
          <button
            type="button"
            onClick={() => setEditing(true)}
            className="px-3 py-1.5 text-sm rounded-lg"
            style={{ border: '1px solid var(--color-surface-border)', color: 'var(--color-navy)' }}
          >
            {row.configured ? 'Edit' : 'Configure'}
          </button>
        )}
      </div>

      {editing && (
        <div className="mt-4 space-y-4" style={{ borderTop: '1px solid var(--color-surface-border)', paddingTop: '16px' }}>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="text-xs font-bold uppercase tracking-wider" style={{ color: '#44474f' }}>
                Send day
              </label>
              <select value={form.send_day_of_week} onChange={set('send_day_of_week')}
                className="w-full mt-1 px-3 py-2 rounded-lg"
                style={{ border: '1px solid var(--color-surface-border)' }}>
                {DAYS.map((d, i) => <option key={i} value={i}>{d}</option>)}
              </select>
            </div>
            <div>
              <label className="text-xs font-bold uppercase tracking-wider" style={{ color: '#44474f' }}>
                Send time
              </label>
              <select value={form.send_hour} onChange={set('send_hour')}
                className="w-full mt-1 px-3 py-2 rounded-lg"
                style={{ border: '1px solid var(--color-surface-border)' }}>
                {HOURS.map(h => <option key={h} value={h}>{formatHour12(h)}</option>)}
              </select>
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="text-xs font-bold uppercase tracking-wider" style={{ color: '#44474f' }}>
                Service hour
              </label>
              <select value={form.service_hour} onChange={set('service_hour')}
                className="w-full mt-1 px-3 py-2 rounded-lg"
                style={{ border: '1px solid var(--color-surface-border)' }}>
                {HOURS.map(h => <option key={h} value={h}>{formatHour12(h)}</option>)}
              </select>
            </div>
            <div>
              <label className="text-xs font-bold uppercase tracking-wider" style={{ color: '#44474f' }}>
                Service minute
              </label>
              <select value={form.service_minute} onChange={set('service_minute')}
                className="w-full mt-1 px-3 py-2 rounded-lg"
                style={{ border: '1px solid var(--color-surface-border)' }}>
                {MINUTES.map(m => <option key={m} value={m}>:{String(m).padStart(2, '0')}</option>)}
              </select>
            </div>
          </div>

          <div className="text-sm" style={{ color: '#6b7280' }}>
            Reminder fires <strong>{DAYS[form.send_day_of_week]} at {formatHour12(form.send_hour)}</strong> for service at <strong>{formatTime12(form.service_hour, form.service_minute)}</strong>.
          </div>

          <div>
            <label className="text-xs font-bold uppercase tracking-wider" style={{ color: '#44474f' }}>
              SMS template
            </label>
            <textarea
              value={form.template}
              onChange={set('template')}
              rows={4}
              maxLength={500}
              className="w-full mt-1 px-3 py-2 rounded-lg font-mono text-sm"
              style={{ border: '1px solid var(--color-surface-border)' }}
            />
            <div className="flex justify-between text-xs mt-1" style={{ color: '#6b7280' }}>
              <span>
                Placeholders: <code>{'{first_name}'}</code>, <code>{'{service_name}'}</code>, <code>{'{service_date}'}</code>, <code>{'{service_time}'}</code>, <code>{'{church_name}'}</code>
              </span>
              <span>{form.template.length}/500</span>
            </div>
            {errors.template && (
              <p className="text-xs mt-1" style={{ color: '#b91c1c' }}>{errors.template[0]}</p>
            )}
          </div>

          <div style={{
            backgroundColor: '#f8f9fa',
            border: '1px solid var(--color-surface-border)',
            borderRadius: '10px',
            padding: '12px',
          }}>
            <div className="text-xs font-bold uppercase tracking-wider mb-2" style={{ color: '#44474f' }}>
              Preview
            </div>
            <div className="text-sm" style={{ color: 'var(--color-navy)' }}>
              {preview || '(typing...)'}
            </div>
            <div className="text-xs mt-2" style={{ color: '#6b7280' }}>
              {charInfo.count} chars • {charInfo.segments} SMS segment{charInfo.segments === 1 ? '' : 's'}
            </div>
          </div>

          <label className="flex items-center gap-2 text-sm" style={{ color: 'var(--color-navy)' }}>
            <input type="checkbox" checked={form.is_active} onChange={set('is_active')} />
            <span>Active (uncheck to pause without losing the config)</span>
          </label>

          <div className="flex gap-2 pt-2">
            <button type="button" onClick={save} disabled={saving} className="btn-primary px-5 py-2">
              {saving ? 'Saving...' : 'Save'}
            </button>
            <button type="button" onClick={() => setEditing(false)} disabled={saving}
              className="px-5 py-2 rounded-lg"
              style={{ border: '1px solid var(--color-surface-border)', color: 'var(--color-navy)' }}>
              Cancel
            </button>
          </div>
        </div>
      )}
    </div>
  )
}

function UpcomingPanel({ upcoming }) {
  if (!upcoming.length) {
    return <p className="text-sm" style={{ color: '#6b7280' }}>No reminders scheduled in the next 7 days.</p>
  }
  return (
    <div className="space-y-2">
      {upcoming.map((u, i) => (
        <div key={i} className="flex items-center justify-between p-3 rounded-lg"
          style={{ backgroundColor: '#f8f9fa', border: '1px solid var(--color-surface-border)' }}>
          <div>
            <div className="font-medium" style={{ color: 'var(--color-navy)' }}>{u.service_type}</div>
            <div className="text-xs" style={{ color: '#6b7280' }}>Service at {u.service_time}</div>
          </div>
          <div className="text-right">
            <div className="text-sm font-medium" style={{ color: 'var(--color-navy)' }}>
              {u.send_day} {u.send_time}
            </div>
            <div className="text-xs" style={{ color: '#6b7280' }}>
              {new Date(u.fires_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })}
            </div>
          </div>
        </div>
      ))}
    </div>
  )
}

function LogPanel({ logs, statusFilter, setStatusFilter }) {
  const statusColors = {
    sent: { bg: '#d1fae5', fg: '#065f46' },
    no_phone: { bg: '#fef3c7', fg: '#92400e' },
    failed: { bg: '#fee2e2', fg: '#991b1b' },
  }

  return (
    <div>
      <div className="flex gap-2 mb-3">
        {['all', 'sent', 'no_phone', 'failed'].map(s => (
          <button key={s} type="button" onClick={() => setStatusFilter(s)}
            className="px-3 py-1 text-xs rounded-full"
            style={{
              border: '1px solid var(--color-surface-border)',
              backgroundColor: statusFilter === s ? 'var(--color-navy)' : 'white',
              color: statusFilter === s ? 'white' : 'var(--color-navy)',
            }}>
            {s === 'no_phone' ? 'No Phone' : s.charAt(0).toUpperCase() + s.slice(1)}
          </button>
        ))}
      </div>

      {!logs.length ? (
        <p className="text-sm" style={{ color: '#6b7280' }}>No reminders sent yet.</p>
      ) : (
        <div className="space-y-2 max-h-96 overflow-y-auto">
          {logs.map(log => {
            const c = statusColors[log.status] ?? { bg: '#f3f4f6', fg: '#4b5563' }
            return (
              <div key={log.id} className="p-3 rounded-lg"
                style={{ backgroundColor: '#f8f9fa', border: '1px solid var(--color-surface-border)' }}>
                <div className="flex items-center justify-between">
                  <div className="font-medium text-sm" style={{ color: 'var(--color-navy)' }}>{log.member_name}</div>
                  <span className="text-xs px-2 py-0.5 rounded-full"
                    style={{ backgroundColor: c.bg, color: c.fg, fontWeight: 600 }}>
                    {log.status === 'no_phone' ? 'no phone' : log.status}
                  </span>
                </div>
                <div className="text-xs mt-1" style={{ color: '#6b7280' }}>
                  {log.service_type} • for {log.intended_service_date} • sent {new Date(log.sent_at).toLocaleString()}
                </div>
                {log.message_body && (
                  <div className="text-xs mt-2 italic" style={{ color: '#6b7280' }}>"{log.message_body}"</div>
                )}
                {log.error_message && (
                  <div className="text-xs mt-1" style={{ color: '#b91c1c' }}>Error: {log.error_message}</div>
                )}
              </div>
            )
          })}
        </div>
      )}
    </div>
  )
}

export default function Reminders() {
  const [settings, setSettings] = useState([])
  const [upcoming, setUpcoming] = useState([])
  const [logs, setLogs] = useState([])
  const [statusFilter, setStatusFilter] = useState('all')
  const [loading, setLoading] = useState(true)

  const loadAll = useCallback(async () => {
    try {
      const [s, u, l] = await Promise.all([
        getReminderSettings(),
        getUpcomingReminders(),
        getReminderLog({ days: 30, status: statusFilter === 'all' ? undefined : statusFilter }),
      ])
      setSettings(s.data.data)
      setUpcoming(u.data.data)
      setLogs(l.data.data)
    } catch (err) {
      console.error(err)
    } finally {
      setLoading(false)
    }
  }, [statusFilter])

  useEffect(() => { loadAll() }, [loadAll])

  if (loading) {
    return (
      <div className="flex items-center justify-center py-24">
        <svg className="animate-spin w-8 h-8" style={{ color: 'var(--color-navy)' }} fill="none" viewBox="0 0 24 24">
          <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
          <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
        </svg>
      </div>
    )
  }

  return (
    <div className="max-w-6xl mx-auto space-y-8">
      <div>
        <h1 className="font-bold" style={{ fontFamily: 'var(--font-display)', fontSize: '32px', color: 'var(--color-navy)' }}>
          Service Reminders
        </h1>
        <p className="mt-1" style={{ color: '#44474f' }}>
          Configure SMS reminders that fire before each service. Members receive one reminder per service per week.
        </p>
      </div>

      <div>
        <h2 className="font-bold mb-3" style={{ fontFamily: 'var(--font-display)', fontSize: '20px', color: 'var(--color-navy)' }}>
          Reminder Configurations
        </h2>
        {settings.map(row => (
          <ConfigCard key={row.service_type_id} row={row} onSaved={loadAll} />
        ))}
      </div>

      <div>
        <h2 className="font-bold mb-3" style={{ fontFamily: 'var(--font-display)', fontSize: '20px', color: 'var(--color-navy)' }}>
          Upcoming This Week
        </h2>
        <UpcomingPanel upcoming={upcoming} />
      </div>

      <div>
        <h2 className="font-bold mb-3" style={{ fontFamily: 'var(--font-display)', fontSize: '20px', color: 'var(--color-navy)' }}>
          Send Log (last 30 days)
        </h2>
        <LogPanel logs={logs} statusFilter={statusFilter} setStatusFilter={setStatusFilter} />
      </div>
    </div>
  )
}
