import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  LineChart, Line, XAxis, YAxis, Tooltip,
  ResponsiveContainer, CartesianGrid,
} from 'recharts'
import {
  Users, CalendarDays, UserPlus, ClipboardList, X,
} from 'lucide-react'
import { messageDepartment } from '../../api/departments'
import { messageCell } from '../../api/cells'

import { NAVY, MUTED, PLACEHOLDER, BORDER, FONT_DISPLAY } from '../../constants/styles'
const greeting = () => {
  const h = new Date().getHours()
  if (h < 12) return 'Good morning'
  if (h < 17) return 'Good afternoon'
  return 'Good evening'
}

export default function LeaderDashboard({ data, user, navigate }) {
  const [msgDept, setMsgDept] = useState(null)
  const [msgCell, setMsgCell] = useState(null)

  const ROLE_PILL = {
    president: { bg: '#ffedd5', text: '#9a3412' },
    secretary: { bg: 'rgba(27,58,107,0.1)', text: NAVY },
    leader:    { bg: '#ffedd5', text: '#9a3412' },
    member:    { bg: '#e1e2e5', text: '#44474f' },
  }
  const pill     = (role) => ROLE_PILL[(role || 'member').toLowerCase()] ?? ROLE_PILL.member
  const initials = (name) => name.split(' ').map(w => w.charAt(0)).slice(0, 2).join('')

  const depts = data.departments ?? []
  const cells = data.cells ?? []

  return (
    <div className="space-y-6" style={{ maxWidth: '1440px' }}>

      <section
        className="rounded-xl relative overflow-hidden p-6 md:p-10"
        style={{ background: 'linear-gradient(135deg,#002452 0%,#1b3a6b 100%)' }}
      >
        <div className="relative z-10">
          <h2
            className="font-bold text-white text-2xl md:text-4xl leading-tight"
            style={{ fontFamily: FONT_DISPLAY }}
          >
            {greeting()}, {user?.name?.split(' ')[0]}
          </h2>
          <div className="flex flex-wrap items-center gap-3 mt-3">
            {depts.length === 0 ? (
              <span style={{ color: 'rgba(255,255,255,0.8)' }}>You are not assigned to lead a department yet.</span>
            ) : (
              <>
                <span
                  className="inline-flex items-center rounded-full font-bold"
                  style={{ padding: '6px 16px', backgroundColor: 'var(--color-gold)', color: NAVY, fontSize: '14px' }}
                >
                  Leading: {depts.map(d => d.name).join(', ')}
                </span>
                <span style={{ color: 'rgba(255,255,255,0.8)', fontSize: '14px' }}>
                  {data.totals.total_active_members} member{data.totals.total_active_members === 1 ? '' : 's'} in your care
                </span>
              </>
            )}
          </div>
        </div>
        <div
          className="absolute rounded-full pointer-events-none"
          style={{ right: '-10%', top: '-50%', width: '384px', height: '384px', background: 'rgba(255,255,255,0.05)', filter: 'blur(60px)' }}
        />
      </section>

      {depts.length === 0 && cells.length === 0 ? (
        <div className="surface-card p-12 text-center">
          <div
            className="w-14 h-14 rounded-2xl flex items-center justify-center mb-4 mx-auto"
            style={{ backgroundColor: 'rgba(27,58,107,0.08)' }}
          >
            <Users size={26} strokeWidth={1.5} style={{ color: NAVY }} aria-hidden="true" />
          </div>
          <div className="font-semibold" style={{ color: NAVY }}>No department or cell assigned</div>
          <p className="text-sm mt-1" style={{ color: '#6b7280' }}>An administrator will assign you to a department to lead.</p>
        </div>
      ) : depts.map(dept => (
        <div key={dept.id} className="space-y-6">

          {/* Stat cards */}
          <section className="grid grid-cols-2 lg:grid-cols-4 gap-6">
            {[
              { label: 'Active Members',       value: dept.active_members,                  icon: Users },
              { label: 'Last Meeting',         value: dept.attendance.last_present,         icon: CalendarDays },
              { label: 'Attendance Rate',      value: `${dept.attendance.attendance_rate}%`, icon: Users },
              { label: 'Meetings This Month',  value: dept.attendance.meetings_this_month,  icon: CalendarDays },
            ].map(s => {
              const IconComp = s.icon
              return (
                <div key={s.label} className="surface-card p-6 flex flex-col justify-between" style={{ minHeight: '130px' }}>
                  <div className="flex justify-between items-start">
                    <p className="uppercase tracking-wider" style={{ fontSize: '12px', fontWeight: 700, color: MUTED }}>{s.label}</p>
                    <IconComp size={20} strokeWidth={1.8} style={{ color: NAVY }} aria-hidden="true" />
                  </div>
                  <span style={{ fontFamily: FONT_DISPLAY, fontSize: '40px', fontWeight: 700, lineHeight: 1, color: NAVY }}>
                    {s.value}
                  </span>
                </div>
              )
            })}
          </section>

          {/* Attendance trend + quick actions */}
          <section className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div className="surface-card p-6 lg:col-span-2">
              <h3 className="mb-4" style={{ fontFamily: FONT_DISPLAY, fontSize: '20px', fontWeight: 600, color: NAVY }}>
                Attendance Trend
              </h3>
              {dept.attendance.trend.length === 0 ? (
                <div className="text-center py-12" style={{ color: PLACEHOLDER }}>
                  <CalendarDays size={32} strokeWidth={1} className="mx-auto mb-3" style={{ color: '#cbd5e1' }} aria-hidden="true" />
                  <div className="text-sm font-semibold" style={{ color: NAVY }}>No department meetings recorded yet</div>
                  <div className="text-xs mt-1">Use &ldquo;Take Attendance&rdquo; to record your first meeting.</div>
                </div>
              ) : (
                <ResponsiveContainer width="100%" height={220}>
                  <LineChart data={dept.attendance.trend}>
                    <CartesianGrid strokeDasharray="3 3" stroke="#E5E9F2" />
                    <XAxis dataKey="date" stroke="#9ca3af" style={{ fontSize: '12px' }} />
                    <YAxis stroke="#9ca3af" style={{ fontSize: '12px' }} allowDecimals={false} />
                    <Tooltip contentStyle={{ backgroundColor: 'white', border: BORDER, borderRadius: '8px', fontSize: '12px' }} />
                    <Line type="monotone" dataKey="count" stroke="var(--color-navy)" strokeWidth={2.5}
                          dot={{ fill: NAVY, r: 4 }} activeDot={{ r: 6 }} name="Present" />
                  </LineChart>
                </ResponsiveContainer>
              )}
            </div>
            <div className="surface-card p-6">
              <h3 className="mb-4" style={{ fontFamily: FONT_DISPLAY, fontSize: '20px', fontWeight: 600, color: NAVY }}>
                Quick Actions
              </h3>
              <div className="space-y-3">
                <button
                  onClick={() => navigate('/members/new')}
                  className="w-full flex items-center justify-center gap-2 rounded-xl transition-colors hover:opacity-90"
                  style={{ padding: '16px', backgroundColor: NAVY, color: 'white' }}
                >
                  <UserPlus size={20} strokeWidth={1.8} aria-hidden="true" />
                  <span style={{ fontSize: '14px', fontWeight: 600 }}>Add Member</span>
                </button>
                <button
                  onClick={() => navigate('/attendance/new')}
                  className="w-full flex items-center justify-center gap-2 rounded-xl transition-colors hover:bg-amber-50"
                  style={{ padding: '16px', backgroundColor: 'white', border: '2px solid var(--color-gold)', color: NAVY }}
                >
                  <ClipboardList size={20} strokeWidth={1.8} aria-hidden="true" />
                  <span style={{ fontSize: '14px', fontWeight: 600 }}>Take Attendance</span>
                </button>
                <button
                  onClick={() => setMsgDept(dept)}
                  className="w-full flex items-center justify-center gap-2 rounded-xl transition-colors hover:bg-slate-200"
                  style={{ padding: '16px', backgroundColor: '#f2f3f6', border: BORDER, color: NAVY }}
                >
                  <Users size={20} strokeWidth={1.8} aria-hidden="true" />
                  <span style={{ fontSize: '14px', fontWeight: 600 }}>Message Members</span>
                </button>
              </div>
            </div>
          </section>

          {/* Members table + recently added */}
          <section className="grid grid-cols-1 xl:grid-cols-3 gap-6">
            <div className="surface-card overflow-hidden xl:col-span-2">
              <div
                className="flex justify-between items-center p-6"
                style={{ borderBottom: BORDER }}
              >
                <h3 style={{ fontFamily: FONT_DISPLAY, fontSize: '20px', fontWeight: 600, color: NAVY }}>
                  {dept.name} Members
                </h3>
                <button
                  onClick={() => navigate(`/departments/${dept.id}`)}
                  className="text-xs font-semibold hover:underline transition-colors"
                  style={{ color: NAVY }}
                  aria-label={`View all members of ${dept.name}`}
                >
                  View all →
                </button>
              </div>
              {dept.members.length === 0 ? (
                <div className="text-center p-8 text-sm" style={{ color: PLACEHOLDER }}>No members in this department yet</div>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full text-left">
                    <thead>
                      <tr style={{ backgroundColor: '#f8f9fc' }}>
                        {['Name', 'Member ID', 'Phone', 'Role'].map(h => (
                          <th key={h} className="uppercase tracking-wider" style={{ padding: '12px 24px', fontSize: '12px', fontWeight: 700, color: MUTED }}>{h}</th>
                        ))}
                      </tr>
                    </thead>
                    <tbody>
                      {dept.members.map(m => {
                        const p = pill(m.role)
                        return (
                          <tr key={m.id} className="hover:bg-slate-50 transition-colors" style={{ borderTop: BORDER }}>
                            <td style={{ padding: '14px 24px' }}>
                              <div className="flex items-center gap-3">
                                <div
                                  className="w-8 h-8 rounded-full flex items-center justify-center font-bold text-xs text-white flex-shrink-0"
                                  style={{ backgroundColor: NAVY }}
                                  aria-hidden="true"
                                >
                                  {initials(m.name)}
                                </div>
                                <span style={{ fontSize: '14px', fontWeight: 600, color: NAVY }}>{m.name}</span>
                              </div>
                            </td>
                            <td style={{ padding: '14px 24px', fontSize: '14px', color: '#44474f' }}>{m.member_number}</td>
                            <td style={{ padding: '14px 24px', fontSize: '14px', color: '#44474f' }}>{m.phone || '—'}</td>
                            <td style={{ padding: '14px 24px' }}>
                              <span
                                className="rounded-full uppercase tracking-wide"
                                style={{ padding: '3px 12px', fontSize: '11px', fontWeight: 700, backgroundColor: p.bg, color: p.text }}
                              >
                                {m.role || 'member'}
                              </span>
                            </td>
                          </tr>
                        )
                      })}
                    </tbody>
                  </table>
                </div>
              )}
            </div>

            <div className="surface-card p-6">
              <h3 className="mb-6" style={{ fontFamily: FONT_DISPLAY, fontSize: '20px', fontWeight: 600, color: NAVY }}>
                Recently Added
              </h3>
              {dept.recent_members.length === 0 ? (
                <div className="text-sm" style={{ color: PLACEHOLDER }}>No recent additions</div>
              ) : (
                <div className="space-y-5">
                  {dept.recent_members.map((m, i) => (
                    <div key={i} className="flex items-center gap-3">
                      <div
                        className="w-9 h-9 rounded-full flex items-center justify-center text-sm font-bold text-white flex-shrink-0"
                        style={{ backgroundColor: NAVY }}
                        aria-hidden="true"
                      >
                        {initials(m.name)}
                      </div>
                      <div className="flex-1 min-w-0">
                        <div className="text-sm font-semibold truncate" style={{ color: NAVY }}>{m.name}</div>
                        <div className="uppercase tracking-widest" style={{ fontSize: '10px', fontWeight: 700, color: PLACEHOLDER }}>
                          {m.joined_at ? `Joined ${m.joined_at}` : m.member_number}
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </section>
        </div>
      ))}

      {cells.map(cell => (
        <div key={cell.id} className="space-y-6">
          <section>
            <h2 className="font-bold mb-1" style={{ fontFamily: FONT_DISPLAY, fontSize: '24px', color: NAVY }}>
              Cell — {cell.name}
            </h2>
            <p className="text-sm" style={{ color: '#6b7280' }}>
              {cell.active_members} member{cell.active_members === 1 ? '' : 's'} · Each member belongs to one cell.
            </p>
          </section>

          <section className="grid grid-cols-2 lg:grid-cols-4 gap-6">
            {[
              { label: 'Active Members',      value: cell.active_members,                  icon: Users },
              { label: 'Last Meeting',        value: cell.attendance.last_present,         icon: CalendarDays },
              { label: 'Attendance Rate',     value: `${cell.attendance.attendance_rate}%`, icon: Users },
              { label: 'Meetings This Month', value: cell.attendance.meetings_this_month,  icon: CalendarDays },
            ].map(s => {
              const IconComp = s.icon
              return (
                <div key={s.label} className="surface-card p-6 flex flex-col justify-between" style={{ minHeight: '130px' }}>
                  <div className="flex justify-between items-start">
                    <p className="uppercase tracking-wider" style={{ fontSize: '12px', fontWeight: 700, color: MUTED }}>{s.label}</p>
                    <IconComp size={20} strokeWidth={1.8} style={{ color: NAVY }} aria-hidden="true" />
                  </div>
                  <span style={{ fontFamily: FONT_DISPLAY, fontSize: '40px', fontWeight: 700, lineHeight: 1, color: NAVY }}>
                    {s.value}
                  </span>
                </div>
              )
            })}
          </section>

          <section className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div className="surface-card p-6 lg:col-span-2">
              <h3 className="mb-4" style={{ fontFamily: FONT_DISPLAY, fontSize: '20px', fontWeight: 600, color: NAVY }}>
                Attendance Trend
              </h3>
              {cell.attendance.trend.length === 0 ? (
                <div className="text-center py-12" style={{ color: PLACEHOLDER }}>
                  <CalendarDays size={32} strokeWidth={1} className="mx-auto mb-3" style={{ color: '#cbd5e1' }} aria-hidden="true" />
                  <div className="text-sm font-semibold" style={{ color: NAVY }}>No cell meetings recorded yet</div>
                  <div className="text-xs mt-1">Use &ldquo;Take Attendance&rdquo; to record your first meeting.</div>
                </div>
              ) : (
                <ResponsiveContainer width="100%" height={220}>
                  <LineChart data={cell.attendance.trend}>
                    <CartesianGrid strokeDasharray="3 3" stroke="#E5E9F2" />
                    <XAxis dataKey="date" stroke="#9ca3af" style={{ fontSize: '12px' }} />
                    <YAxis stroke="#9ca3af" style={{ fontSize: '12px' }} allowDecimals={false} />
                    <Tooltip contentStyle={{ backgroundColor: 'white', border: BORDER, borderRadius: '8px', fontSize: '12px' }} />
                    <Line type="monotone" dataKey="count" stroke="var(--color-navy)" strokeWidth={2.5}
                          dot={{ fill: NAVY, r: 4 }} activeDot={{ r: 6 }} name="Present" />
                  </LineChart>
                </ResponsiveContainer>
              )}
            </div>
            <div className="surface-card p-6">
              <h3 className="mb-4" style={{ fontFamily: FONT_DISPLAY, fontSize: '20px', fontWeight: 600, color: NAVY }}>
                Quick Actions
              </h3>
              <div className="space-y-3">
                <button
                  onClick={() => navigate(`/cells/${cell.id}`)}
                  className="w-full flex items-center justify-center gap-2 rounded-xl transition-colors hover:opacity-90"
                  style={{ padding: '16px', backgroundColor: NAVY, color: 'white' }}
                >
                  <Users size={20} strokeWidth={1.8} aria-hidden="true" />
                  <span style={{ fontSize: '14px', fontWeight: 600 }}>Manage Members</span>
                </button>
                <button
                  onClick={() => navigate('/attendance/new')}
                  className="w-full flex items-center justify-center gap-2 rounded-xl transition-colors hover:bg-amber-50"
                  style={{ padding: '16px', backgroundColor: 'white', border: '2px solid var(--color-gold)', color: NAVY }}
                >
                  <ClipboardList size={20} strokeWidth={1.8} aria-hidden="true" />
                  <span style={{ fontSize: '14px', fontWeight: 600 }}>Take Attendance</span>
                </button>
                <button
                  onClick={() => setMsgCell(cell)}
                  className="w-full flex items-center justify-center gap-2 rounded-xl transition-colors hover:bg-slate-200"
                  style={{ padding: '16px', backgroundColor: '#f2f3f6', border: BORDER, color: NAVY }}
                >
                  <Users size={20} strokeWidth={1.8} aria-hidden="true" />
                  <span style={{ fontSize: '14px', fontWeight: 600 }}>Message Members</span>
                </button>
              </div>
            </div>
          </section>
        </div>
      ))}

      {msgDept && <MessageModal dept={msgDept} onClose={() => setMsgDept(null)} />}
      {msgCell && <MessageModal cell={msgCell} onClose={() => setMsgCell(null)} />}
    </div>
  )
}


function MessageModal({ dept, cell, onClose }) {
  const target = dept || cell
  const isCell = !!cell
  const [channel, setChannel] = useState('sms')
  const [subject, setSubject] = useState('')
  const [body, setBody]       = useState('')
  const [sending, setSending] = useState(false)
  const [result, setResult]   = useState(null)

  const send = async () => {
    if (!body.trim()) return
    setSending(true)
    setResult(null)
    try {
      const res = isCell
        ? await messageCell(cell.id, { subject: subject || null, body, channel })
        : await messageDepartment(dept.id, { subject: subject || null, body, channel })
      setResult({ ok: true, text: res.data.message || 'Message sent.' })
    } catch (err) {
      const msg = err.response?.data?.message || 'Could not send the message.'
      setResult({ ok: false, text: msg })
    } finally {
      setSending(false)
    }
  }

  const channels = [
    { key: 'sms',   label: 'SMS' },
    { key: 'email', label: 'Email' },
    { key: 'both',  label: 'Both' },
  ]

  return (
    <div
      className="fixed inset-0 flex items-center justify-center z-50 p-4"
      style={{ backgroundColor: 'rgba(13,31,60,0.4)', backdropFilter: 'blur(4px)' }}
    >
      <div className="bg-white w-full max-w-lg rounded-2xl shadow-2xl">
        <div
          className="px-6 pt-6 pb-4 flex justify-between items-start"
          style={{ borderBottom: BORDER }}
        >
          <div>
            <h2 className="font-bold" style={{ fontFamily: FONT_DISPLAY, fontSize: '22px', color: NAVY }}>
              Message {target.name}
            </h2>
            <p style={{ fontSize: '13px', color: MUTED, marginTop: '2px' }}>
              {isCell ? 'Sent to your cell members with contact details.' : 'Sent to your department members with contact details.'}
            </p>
          </div>
          <button
            onClick={onClose}
            type="button"
            className="w-10 h-10 flex items-center justify-center rounded-xl transition-colors hover:bg-slate-100"
            style={{ color: MUTED }}
            aria-label="Close dialog"
          >
            <X size={20} strokeWidth={2} aria-hidden="true" />
          </button>
        </div>

        {result?.ok ? (
          <div className="p-6">
            <div className="rounded-lg p-4" style={{ backgroundColor: '#dcfce7', border: '1px solid #86efac' }}>
              <div style={{ fontSize: '14px', fontWeight: 700, color: '#15803d' }}>{result.text}</div>
              <p style={{ fontSize: '12px', color: '#166534', marginTop: '4px' }}>Messages are delivered once an SMS provider is connected.</p>
            </div>
            <div className="flex justify-end mt-6">
              <button onClick={onClose} className="btn-primary px-6 py-2">Done</button>
            </div>
          </div>
        ) : (
          <div className="p-6 space-y-4">
            <div>
              <label className="block mb-1.5" style={{ fontSize: '13px', fontWeight: 600, color: NAVY }}>Channel</label>
              <div className="flex gap-2">
                {channels.map(c => (
                  <button
                    key={c.key}
                    type="button"
                    onClick={() => setChannel(c.key)}
                    style={{
                      padding: '8px 18px', borderRadius: '999px', fontSize: '13px', fontWeight: 600,
                      backgroundColor: channel === c.key ? NAVY : '#f2f3f6',
                      color: channel === c.key ? 'white' : NAVY,
                      border: BORDER,
                    }}
                  >
                    {c.label}
                  </button>
                ))}
              </div>
            </div>
            <div>
              <label className="block mb-1.5" htmlFor="msg-subject" style={{ fontSize: '13px', fontWeight: 600, color: NAVY }}>
                Subject (optional)
              </label>
              <input
                id="msg-subject"
                type="text"
                className="input-field"
                value={subject}
                onChange={e => setSubject(e.target.value)}
                maxLength={200}
              />
            </div>
            <div>
              <label className="block mb-1.5" htmlFor="msg-body" style={{ fontSize: '13px', fontWeight: 600, color: NAVY }}>
                Message <span aria-hidden="true">*</span>
              </label>
              <textarea
                id="msg-body"
                className="input-field"
                rows={4}
                value={body}
                onChange={e => setBody(e.target.value)}
                placeholder="e.g. Choir rehearsal moved to 5pm on Saturday."
              />
            </div>
            {result && !result.ok && (
              <p style={{ fontSize: '13px', color: '#ba1a1a' }} role="alert">{result.text}</p>
            )}
            <p style={{ fontSize: '12px', color: PLACEHOLDER, fontStyle: 'italic' }}>
              Note: messages send for real once an SMS provider is connected.
            </p>
            <div
              className="flex justify-end gap-3 pt-2"
              style={{ borderTop: BORDER }}
            >
              <button
                type="button"
                onClick={onClose}
                className="btn-secondary px-5 py-2"
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={send}
                disabled={sending || !body.trim()}
                className="btn-primary px-6 py-2"
                style={{ opacity: (sending || !body.trim()) ? 0.6 : 1 }}
              >
                {sending ? 'Sending\u2026' : 'Send Message'}
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
