import React, { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { sendMessage, previewRecipients } from '../../api/messages'
import { getDepartments } from '../../api/departments'

export default function Compose() {
  const navigate = useNavigate()
  const [form, setForm] = useState({
    channel:         'email',
    subject:         '',
    body:            '',
    recipient_group: 'all',
    department_id:   '',
    gender:          '',
    status:          'active',
  })
  const [departments, setDepartments] = useState([])
  const [count,       setCount]       = useState(null)
  const [loading,     setLoading]     = useState(false)
  const [errors,      setErrors]      = useState({})

  useEffect(() => {
    getDepartments().then(res => setDepartments(res.data.data))
  }, [])

  // Recompute recipient count whenever filters change
  useEffect(() => {
    if (form.recipient_group === 'individual') {
      setCount(null)
      return
    }
    setCount('loading')
    const t = setTimeout(() => {
      previewRecipients(form)
        .then(res => setCount(res.data.data.count))
        .catch(() => setCount(null))
    }, 300)
    return () => clearTimeout(t)
  }, [form.channel, form.recipient_group, form.department_id, form.gender, form.status])

  const set = (field) => (e) => {
    setForm(f => ({ ...f, [field]: e.target.value }))
    setErrors(e => ({ ...e, [field]: null }))
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    if (!confirm(`Send this message to ${count} recipient(s)?`)) return

    setLoading(true)
    setErrors({})
    try {
      const res = await sendMessage(form)
      alert(res.data.message)
      navigate(`/communication/${res.data.data.id}`)
    } catch (err) {
      if (err.response?.status === 422) {
        setErrors(err.response.data.errors ?? {})
        if (err.response.data.message && !err.response.data.errors) {
          alert(err.response.data.message)
        }
      } else {
        alert('Failed to send. Please try again.')
      }
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="max-w-3xl mx-auto space-y-6">

      <div className="flex items-center gap-4">
        <button onClick={() => navigate('/communication')}
                className="p-2 rounded-lg"
                style={{backgroundColor:'white',border:'1px solid var(--color-surface-border)'}}>
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7"/>
          </svg>
        </button>
        <div>
          <h2 className="text-xl font-bold"
              style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
            Compose Message
          </h2>
          <p className="text-sm" style={{color:'#6b7280'}}>
            Send an announcement to members of the church
          </p>
        </div>
      </div>

      <form onSubmit={handleSubmit} className="space-y-6">

        {/* Channel */}
        <div className="card">
          <label className="block text-sm font-semibold mb-3" style={{color:'#374151'}}>
            Delivery Channel *
          </label>
          <div className="grid grid-cols-3 gap-3">
            {[
              { v: 'email', label: '📧 Email',     desc: 'Send via email' },
              { v: 'sms',   label: '📱 SMS',       desc: 'Send via SMS' },
              { v: 'both',  label: '📨 Both',      desc: 'Email + SMS' },
            ].map(opt => (
              <button key={opt.v} type="button"
                      onClick={() => setForm(f => ({ ...f, channel: opt.v }))}
                      className="p-3 rounded-xl text-left transition-all"
                      style={{
                        backgroundColor: form.channel === opt.v ? 'rgba(27,58,107,0.08)' : 'white',
                        border: form.channel === opt.v
                          ? '2px solid var(--color-navy)'
                          : '2px solid var(--color-surface-border)',
                      }}>
                <div className="text-sm font-bold mb-0.5"
                     style={{color: form.channel === opt.v ? 'var(--color-navy)' : '#374151'}}>
                  {opt.label}
                </div>
                <div className="text-xs" style={{color:'#6b7280'}}>{opt.desc}</div>
              </button>
            ))}
          </div>
          {form.channel !== 'email' && (
            <div className="mt-3 p-3 rounded-lg"
                 style={{backgroundColor:'#fffbeb',border:'1px solid #fde68a'}}>
              <p className="text-xs" style={{color:'#92400e'}}>
                <strong>⚠️ SMS delivery is in dry-run mode.</strong> Messages will be logged but not actually sent until Arkesel SMS credentials are added in production.
              </p>
            </div>
          )}
        </div>

        {/* Recipients */}
        <div className="card space-y-4">
          <h3 className="font-semibold text-sm uppercase tracking-wider"
              style={{color:'var(--color-navy)'}}>
            Recipients
          </h3>

          <div>
            <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>
              Who should receive this?
            </label>
            <select className="input-field" value={form.recipient_group}
                    onChange={set('recipient_group')} required>
              <option value="all">All active members</option>
              <option value="department">A specific department</option>
              <option value="gender">By gender</option>
              <option value="status">By membership status</option>
            </select>
          </div>

          {form.recipient_group === 'department' && (
            <div>
              <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>
                Department
              </label>
              <select className="input-field" value={form.department_id} onChange={set('department_id')}>
                <option value="">Select a department</option>
                {departments.map(d => (
                  <option key={d.id} value={d.id}>{d.name} ({d.members_count} members)</option>
                ))}
              </select>
            </div>
          )}

          {form.recipient_group === 'gender' && (
            <div>
              <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>
                Gender
              </label>
              <select className="input-field" value={form.gender} onChange={set('gender')}>
                <option value="">Select</option>
                <option value="male">Males</option>
                <option value="female">Females</option>
              </select>
            </div>
          )}

          {form.recipient_group === 'status' && (
            <div>
              <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>
                Member Status
              </label>
              <select className="input-field" value={form.status} onChange={set('status')}>
                <option value="active">Active members</option>
                <option value="inactive">Inactive members</option>
              </select>
            </div>
          )}

          {/* Recipient count */}
          <div className="rounded-lg p-3 flex items-center gap-3"
               style={{backgroundColor:'#f0f9ff',border:'1px solid #bae6fd'}}>
            <div className="text-2xl">👥</div>
            <div>
              <div className="text-sm font-semibold" style={{color:'#0c4a6e'}}>
                {count === 'loading' ? 'Counting...'
                  : count === null ? 'Set recipient filters above'
                  : count === 0 ? 'No recipients match these filters'
                  : `${count} recipient${count === 1 ? '' : 's'} will receive this message`}
              </div>
              <div className="text-xs mt-0.5" style={{color:'#075985'}}>
                Only members with valid contact info for the chosen channel are counted
              </div>
            </div>
          </div>
        </div>

        {/* Message */}
        <div className="card space-y-4">
          <h3 className="font-semibold text-sm uppercase tracking-wider"
              style={{color:'var(--color-navy)'}}>
            Message
          </h3>

          {(form.channel === 'email' || form.channel === 'both') && (
            <div>
              <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>
                Subject (email only)
              </label>
              <input type="text" className="input-field" value={form.subject}
                     onChange={set('subject')}
                     placeholder="e.g. Sunday Service Reminder"/>
              {errors.subject && <p className="text-xs mt-1" style={{color:'#dc2626'}}>{errors.subject[0]}</p>}
            </div>
          )}

          <div>
            <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>
              Message Body *
            </label>
            <textarea className="input-field" value={form.body} onChange={set('body')}
                      rows={8} required
                      placeholder="Type your message here..."
                      style={{resize:'vertical', minHeight:'150px'}}/>
            <div className="flex justify-between mt-1">
              <p className="text-xs" style={{color:'#9ca3af'}}>
                {form.channel !== 'email' && form.body.length > 160 && (
                  <span style={{color:'#dc2626'}}>⚠️ SMS messages over 160 chars will split into multiple messages</span>
                )}
              </p>
              <p className="text-xs" style={{color:'#9ca3af'}}>{form.body.length} chars</p>
            </div>
            {errors.body && <p className="text-xs mt-1" style={{color:'#dc2626'}}>{errors.body[0]}</p>}
          </div>
        </div>

        <div className="flex items-center justify-end gap-3">
          <button type="button" onClick={() => navigate('/communication')}
                  className="px-6 py-2.5 rounded-lg text-sm font-semibold"
                  style={{backgroundColor:'white',border:'1px solid var(--color-surface-border)',color:'#374151'}}>
            Cancel
          </button>
          <button type="submit" disabled={loading || !count || count === 'loading'}
                  className="btn-primary px-8 py-2.5">
            {loading ? 'Sending...' : `Send to ${count ?? '...'} →`}
          </button>
        </div>
      </form>
    </div>
  )
}
