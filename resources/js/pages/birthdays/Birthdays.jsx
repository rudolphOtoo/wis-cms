import { useState, useEffect, useRef } from 'react'
import {
  getBirthdaySettings,
  updateBirthdaySettings,
  previewBirthdayMessage,
  getUpcomingBirthdays,
  getBirthdayLog,
} from '../../api/birthdays'
import { usePermission } from '../../hooks/usePermission'

// Status badge colors — match Cell Comparison's gentle palette
const STATUS_META = {
  sent:     { label: 'Sent',     color: '#2e7d32', bg: '#2e7d3215' },
  no_phone: { label: 'No phone', color: '#c9a84c', bg: '#c9a84c20' },
  failed:   { label: 'Failed',   color: '#a14d4d', bg: '#a14d4d20' },
}

function formatDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('en-GH', { month: 'short', day: 'numeric', year: 'numeric' })
}

function formatDateTime(d) {
  if (!d) return '—'
  return new Date(d).toLocaleString('en-GH', {
    month: 'short', day: 'numeric', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  })
}

export default function Birthdays() {
  const { can } = usePermission()
  const canManage = can('manage birthday messages')
  const canView   = can('view birthday messages')

  // SETTINGS state
  const [, setSettings] = useState(null)
  const [editedTemplate, setEditedTemplate] = useState('')
  const [editedActive, setEditedActive] = useState(true)
  const [savingSettings, setSavingSettings] = useState(false)
  const [settingsError, setSettingsError] = useState(null)
  const [settingsSavedMsg, setSettingsSavedMsg] = useState('')

  // PREVIEW state
  const [previewData, setPreviewData] = useState(null)
  const [previewError, setPreviewError] = useState(null)
  const previewDebounce = useRef(null)

  // UPCOMING state
  const [upcomingDays, setUpcomingDays] = useState(7)
  const [upcoming, setUpcoming] = useState({ data: [], meta: { scope: '', count: 0 } })
  const [loadingUpcoming, setLoadingUpcoming] = useState(false)

  // LOG state (admin only)
  const [logFilter, setLogFilter] = useState('')
  const [log, setLog] = useState({ data: [], meta: { summary: {}, total_in_window: 0 } })
  const [loadingLog, setLoadingLog] = useState(false)

  // PREVIEW (debounced on template change) — declared early so
  // loadSettings can await it without violating eslint immutability.
  const refreshPreview = async (template) => {
    try {
      const res = await previewBirthdayMessage(template)
      setPreviewData(res.data.data)
      setPreviewError(null)
    } catch (err) {
      setPreviewError(err?.response?.data?.message || 'Preview failed')
      setPreviewData(null)
    }
  }

  const loadSettings = async () => {
    try {
      const res = await getBirthdaySettings()
      setSettings(res.data)
      setEditedTemplate(res.data.data.template)
      setEditedActive(res.data.data.is_active)
      await refreshPreview(res.data.data.template)
    } catch (err) {
      setSettingsError(err?.response?.data?.message || 'Failed to load settings')
    }
  }

  const loadUpcoming = async (days) => {
    setLoadingUpcoming(true)
    try {
      const res = await getUpcomingBirthdays(days)
      setUpcoming(res.data)
    } catch {
      setUpcoming({ data: [], meta: { scope: '', count: 0 } })
    } finally {
      setLoadingUpcoming(false)
    }
  }

  const loadLog = async (status) => {
    setLoadingLog(true)
    try {
      const res = await getBirthdayLog(status ? { days: 30, status } : { days: 30 })
      setLog(res.data)
    } catch {
      setLog({ data: [], meta: { summary: {}, total_in_window: 0 } })
    } finally {
      setLoadingLog(false)
    }
  }

  // INITIAL LOAD — declared after the load functions so eslint's
  // react-hooks immutability rule is satisfied.
  useEffect(() => {
    if (canManage) loadSettings()
    if (canView) loadUpcoming(upcomingDays)
    if (canManage) loadLog()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

const onTemplateChange = (value) => {
    setEditedTemplate(value)
    setSettingsSavedMsg('')
    if (previewDebounce.current) clearTimeout(previewDebounce.current)
    previewDebounce.current = setTimeout(() => refreshPreview(value), 350)
  }

  const onSaveSettings = async () => {
    setSavingSettings(true)
    setSettingsError(null)
    setSettingsSavedMsg('')
    try {
      await updateBirthdaySettings({
        template: editedTemplate,
        is_active: editedActive,
      })
      setSettingsSavedMsg('Settings saved successfully.')
      setTimeout(() => setSettingsSavedMsg(''), 3000)
    } catch (err) {
      const msg = err?.response?.data?.errors?.template?.[0]
        || err?.response?.data?.message
        || 'Failed to save'
      setSettingsError(msg)
    } finally {
      setSavingSettings(false)
    }
  }

  const onLogFilterChange = (status) => {
    setLogFilter(status)
    loadLog(status)
  }

  if (!canView && !canManage) {
    return (
      <div className="bg-white rounded-xl p-6 text-center" style={{border:'1px solid var(--color-surface-border)',color:'#9ca3af'}}>
        You don't have permission to view this page.
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="font-bold" style={{fontFamily:'var(--font-display)',fontSize:'32px',color:'var(--color-navy)'}}>
          Birthday Messages
        </h1>
        <p style={{color:'#44474f',marginTop:'4px'}}>
          {canManage
            ? 'Edit the template, see who has a birthday coming up, and review the send log.'
            : 'Members with birthdays in your cell this week.'}
        </p>
      </div>

      {/* SETTINGS — admin only */}
      {canManage && (
        <div className="bg-white rounded-xl overflow-hidden"
             style={{border:'1px solid var(--color-surface-border)'}}>
          <div className="px-6 py-4 flex flex-wrap items-center justify-between gap-3"
               style={{borderBottom:'1px solid var(--color-surface-border)'}}>
            <h2 className="font-bold"
                style={{fontFamily:'var(--font-display)',fontSize:'20px',color:'var(--color-navy)'}}>
              Template & Settings
            </h2>
            <label className="inline-flex items-center gap-2 text-sm">
              <input type="checkbox" checked={editedActive}
                     onChange={e => setEditedActive(e.target.checked)}
                     style={{width:'18px',height:'18px'}} />
              <span style={{color:'#44474f',fontWeight:600}}>Enabled</span>
            </label>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-6 p-6">
            {/* Editor */}
            <div>
              <label className="text-xs font-bold uppercase tracking-wider mb-2 block" style={{color:'#44474f'}}>
                Message Template
              </label>
              <textarea value={editedTemplate}
                        onChange={e => onTemplateChange(e.target.value)}
                        rows={6}
                        maxLength={500}
                        className="w-full px-3 py-2 rounded-lg font-mono text-sm"
                        style={{border:'1px solid var(--color-surface-border)'}} />
              <div className="flex justify-between text-xs mt-1" style={{color:'#9ca3af'}}>
                <span>{editedTemplate.length} / 500 characters</span>
                {previewData && (
                  <span>{previewData.sms_segments} SMS segment{previewData.sms_segments === 1 ? '' : 's'}</span>
                )}
              </div>

              <div className="mt-4 text-xs" style={{color:'#44474f'}}>
                <div className="font-bold uppercase tracking-wider mb-2">Available Placeholders</div>
                <div className="space-y-1">
                  <code className="block">{'{first_name}'} — Member's first name</code>
                  <code className="block">{'{last_name}'} — Member's last name</code>
                  <code className="block">{'{full_name}'} — Full name</code>
                  <code className="block">{'{church_name}'} — Church name</code>
                </div>
              </div>
            </div>

            {/* Live preview */}
            <div>
              <label className="text-xs font-bold uppercase tracking-wider mb-2 block" style={{color:'#44474f'}}>
                Live Preview
              </label>
              <div className="rounded-lg p-4 min-h-[140px]"
                   style={{backgroundColor:'#f8f9fa',border:'1px solid var(--color-surface-border)'}}>
                {previewError ? (
                  <span style={{color:'#991b1b'}}>{previewError}</span>
                ) : previewData ? (
                  <p style={{color:'var(--color-navy)',lineHeight:'1.5'}}>
                    {previewData.rendered_message}
                  </p>
                ) : (
                  <span style={{color:'#9ca3af'}}>Type to preview…</span>
                )}
              </div>
              {previewData && (
                <div className="text-xs mt-2" style={{color:'#9ca3af'}}>
                  Sample: {previewData.sample_source === 'real_member' ? 'real branch member' : 'placeholder'}
                </div>
              )}
            </div>
          </div>

          <div className="px-6 py-4 flex flex-wrap items-center justify-between gap-3"
               style={{borderTop:'1px solid var(--color-surface-border)',backgroundColor:'#f8f9fa'}}>
            {settingsSavedMsg && (
              <span style={{color:'#2e7d32',fontWeight:600,fontSize:'14px'}}>✓ {settingsSavedMsg}</span>
            )}
            {settingsError && (
              <span style={{color:'#991b1b',fontWeight:600,fontSize:'14px'}}>{settingsError}</span>
            )}
            <div className="flex-1" />
            <button onClick={onSaveSettings} disabled={savingSettings}
                    className="btn-primary px-6 py-2">
              {savingSettings ? 'Saving...' : 'Save Settings'}
            </button>
          </div>
        </div>
      )}

      {/* UPCOMING — everyone with view */}
      {canView && (
        <div className="bg-white rounded-xl overflow-hidden"
             style={{border:'1px solid var(--color-surface-border)'}}>
          <div className="px-6 py-4 flex flex-wrap items-center justify-between gap-3"
               style={{borderBottom:'1px solid var(--color-surface-border)'}}>
            <div>
              <h2 className="font-bold"
                  style={{fontFamily:'var(--font-display)',fontSize:'20px',color:'var(--color-navy)'}}>
                Upcoming Birthdays
              </h2>
              <p className="text-xs mt-1" style={{color:'#9ca3af'}}>
                {upcoming.meta.scope === 'cell_leader_only' ? 'Your cell members' : 'All branch members'}
                {' '}— {upcoming.meta.count} in next {upcomingDays} day{upcomingDays === 1 ? '' : 's'}
              </p>
            </div>
            <select value={upcomingDays}
                    onChange={e => { const d = parseInt(e.target.value, 10); setUpcomingDays(d); loadUpcoming(d); }}
                    className="px-3 py-2 rounded-lg text-sm"
                    style={{border:'1px solid var(--color-surface-border)'}}>
              <option value={7}>Next 7 days</option>
              <option value={14}>Next 14 days</option>
              <option value={30}>Next 30 days</option>
            </select>
          </div>

          {loadingUpcoming ? (
            <div className="p-6 text-center" style={{color:'#9ca3af'}}>Loading…</div>
          ) : upcoming.data.length === 0 ? (
            <div className="p-6 text-center" style={{color:'#9ca3af'}}>
              No birthdays in the next {upcomingDays} days.
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead style={{backgroundColor:'#edeef1'}}>
                  <tr>
                    <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Member</th>
                    <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Cell</th>
                    <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Date</th>
                    <th className="text-right px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Days</th>
                    <th className="text-right px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Turning</th>
                    <th className="text-center px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>SMS</th>
                  </tr>
                </thead>
                <tbody>
                  {upcoming.data.map(m => (
                    <tr key={m.id} style={{borderTop:'1px solid var(--color-surface-border)'}}>
                      <td className="px-6 py-3 font-medium" style={{color:'var(--color-navy)'}}>{m.full_name}</td>
                      <td className="px-6 py-3" style={{color:'#44474f'}}>{m.cell?.name ?? '—'}</td>
                      <td className="px-6 py-3" style={{color:'#44474f'}}>{formatDate(m.birthday_this_year)}</td>
                      <td className="px-6 py-3 text-right font-mono">
                        {m.days_away === 0 ? <span style={{color:'#c9a84c',fontWeight:700}}>Today!</span>
                         : m.days_away === 1 ? 'Tomorrow'
                         : `${m.days_away}d`}
                      </td>
                      <td className="px-6 py-3 text-right font-mono">{m.age_turning}</td>
                      <td className="px-6 py-3 text-center">
                        {m.has_phone
                          ? <span style={{color:'#2e7d32',fontSize:'18px'}}>✓</span>
                          : <span style={{color:'#c9a84c',fontSize:'12px'}} title="No phone — will be skipped">✗</span>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      {/* SEND LOG — admin only */}
      {canManage && (
        <div className="bg-white rounded-xl overflow-hidden"
             style={{border:'1px solid var(--color-surface-border)'}}>
          <div className="px-6 py-4 flex flex-wrap items-center justify-between gap-3"
               style={{borderBottom:'1px solid var(--color-surface-border)'}}>
            <div>
              <h2 className="font-bold"
                  style={{fontFamily:'var(--font-display)',fontSize:'20px',color:'var(--color-navy)'}}>
                Send Log
              </h2>
              <p className="text-xs mt-1" style={{color:'#9ca3af'}}>
                Last 30 days — sent: {log.meta.summary?.sent ?? 0},
                no_phone: {log.meta.summary?.no_phone ?? 0},
                failed: {log.meta.summary?.failed ?? 0}
              </p>
            </div>
            <select value={logFilter} onChange={e => onLogFilterChange(e.target.value)}
                    className="px-3 py-2 rounded-lg text-sm"
                    style={{border:'1px solid var(--color-surface-border)'}}>
              <option value="">All statuses</option>
              <option value="sent">Sent</option>
              <option value="no_phone">No phone</option>
              <option value="failed">Failed</option>
            </select>
          </div>

          {loadingLog ? (
            <div className="p-6 text-center" style={{color:'#9ca3af'}}>Loading…</div>
          ) : log.data.length === 0 ? (
            <div className="p-6 text-center" style={{color:'#9ca3af'}}>
              No send activity in the last 30 days.
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead style={{backgroundColor:'#edeef1'}}>
                  <tr>
                    <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>When</th>
                    <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Member</th>
                    <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Status</th>
                    <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Phone</th>
                    <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Detail</th>
                  </tr>
                </thead>
                <tbody>
                  {log.data.map(entry => {
                    const meta = STATUS_META[entry.status] ?? { label: entry.status, color: '#44474f', bg: '#f8f9fa' }
                    return (
                      <tr key={entry.id} style={{borderTop:'1px solid var(--color-surface-border)'}}>
                        <td className="px-6 py-3" style={{color:'#44474f'}}>{formatDateTime(entry.sent_at)}</td>
                        <td className="px-6 py-3 font-medium" style={{color:'var(--color-navy)'}}>
                          {entry.member?.name ?? '(deleted)'}
                        </td>
                        <td className="px-6 py-3">
                          <span style={{
                            display:'inline-block',padding:'2px 8px',borderRadius:'10px',
                            fontSize:'11px',fontWeight:600,
                            backgroundColor:meta.bg,color:meta.color,
                          }}>{meta.label}</span>
                        </td>
                        <td className="px-6 py-3 font-mono text-xs" style={{color:'#44474f'}}>
                          {entry.phone_used ?? '—'}
                        </td>
                        <td className="px-6 py-3 text-xs" style={{color:'#9ca3af',maxWidth:'400px'}}>
                          {entry.error_message
                            ? <span style={{color:'#991b1b'}}>{entry.error_message}</span>
                            : (entry.message_body ? entry.message_body.slice(0, 80) + (entry.message_body.length > 80 ? '…' : '') : '—')}
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}
    </div>
  )
}
