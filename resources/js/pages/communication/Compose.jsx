import React, { useState, useEffect } from 'react'
import { toast } from 'sonner'
import { useNavigate } from 'react-router-dom'
import { sendMessage, previewRecipients } from '../../api/messages'
import { getDepartments } from '../../api/departments'
import { useConfirm } from '../../hooks/useConfirm'

const cardBase = {
  backgroundColor: '#fff',
  border: '1px solid var(--color-surface-border)',
  borderRadius: '16px',
  boxShadow: '0 4px 12px rgba(13,31,60,0.05)',
}

const Icon = ({ d, size = 22 }) => (
  <svg width={size} height={size} fill="none" stroke="currentColor" strokeWidth={1.8}
       viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">{d}</svg>
)
const ICONS = {
  mail: <><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 7l-10 5L2 7"/></>,
  sms:  <><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></>,
  both: <><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/><path d="M8 9h8M8 13h5"/></>,
  groups: <><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/></>,
}

const CHANNEL_OPTS = [
  { v:'email', label:'Email',       desc:'Formal notices & newsletters', icon:'mail' },
  { v:'sms',   label:'SMS',         desc:'Urgent alerts & reminders',    icon:'sms'  },
  { v:'both',  label:'Email & SMS', desc:'Maximum visibility outreach',  icon:'both' },
]

export default function Compose() {
  const navigate = useNavigate()
  const { confirm, dialog } = useConfirm()
  const [form, setForm] = useState({
    channel:'email', subject:'', body:'',
    recipient_group:'all', department_id:'', gender:'', status:'active',
  })
  const [departments, setDepartments] = useState([])
  const [count,       setCount]       = useState(null)
  const [loading,     setLoading]     = useState(false)
  const [errors,      setErrors]      = useState({})

  useEffect(() => {
    getDepartments().then(res => setDepartments(res.data.data))
  }, [])

  useEffect(() => {
    if (form.recipient_group === 'individual') { setCount(null); return }
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
    if (!(await confirm(`Send this message to ${count} recipient(s)?`))) return
    setLoading(true)
    setErrors({})
    try {
      const res = await sendMessage(form)
      toast.success(res.data.message)
      navigate(`/communication/${res.data.data.id}`)
    } catch (err) {
      if (err.response?.status === 422) {
        setErrors(err.response.data.errors ?? {})
        if (err.response.data.message && !err.response.data.errors) toast.error(err.response.data.message)
      } else {
        toast.error('Failed to send. Please try again.')
      }
    } finally {
      setLoading(false)
    }
  }

  const showSubject = form.channel === 'email' || form.channel === 'both'

  return (
    <div style={{maxWidth:'1440px'}} className="space-y-6">

      {/* Header */}
      <div className="flex items-center gap-4">
        <button onClick={() => navigate('/communication')}
                className="w-11 h-11 flex items-center justify-center rounded-full"
                style={{backgroundColor:'white',border:'1px solid var(--color-surface-border)',color:'var(--color-navy)'}}>
          <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7"/>
          </svg>
        </button>
        <div>
          <h2 className="font-bold" style={{fontFamily:'var(--font-display)',fontSize:'28px',color:'var(--color-navy)'}}>Broadcast Message</h2>
          <p style={{color:'#44474f'}}>Reach out to your congregation via multi-channel communications.</p>
        </div>
      </div>

      <form onSubmit={handleSubmit}>
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">

          {/* LEFT: config */}
          <div className="lg:col-span-2 space-y-6">

            {/* Channel selector */}
            <div>
              <h3 className="uppercase tracking-wider mb-4 flex items-center gap-2" style={{fontSize:'14px',fontWeight:700,color:'var(--color-navy)'}}>
                <Icon d={ICONS.groups} size={18} /> Select Communication Channel
              </h3>
              <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                {CHANNEL_OPTS.map(opt => {
                  const active = form.channel === opt.v
                  return (
                    <button key={opt.v} type="button"
                            onClick={() => setForm(f => ({ ...f, channel: opt.v }))}
                            className="flex flex-col items-center text-center transition-all"
                            style={{...cardBase, padding:'24px',
                                    border: active ? '2px solid var(--color-navy)' : '1px solid var(--color-surface-border)',
                                    backgroundColor: active ? '#f0f4f9' : '#fff'}}>
                      <div className="w-12 h-12 rounded-full flex items-center justify-center mb-3"
                           style={{backgroundColor: active ? 'var(--color-navy)' : '#edeef1', color: active ? '#fff' : '#44474f'}}>
                        <Icon d={ICONS[opt.icon]} size={22} />
                      </div>
                      <span style={{fontFamily:'var(--font-display)',fontSize:'18px',fontWeight:600,color:'var(--color-navy)'}}>{opt.label}</span>
                      <p style={{fontSize:'12px',color:'#747780',marginTop:'4px'}}>{opt.desc}</p>
                      {active && (
                        <svg className="w-5 h-5 mt-2" style={{color:'var(--color-gold)'}} fill="currentColor" viewBox="0 0 24 24">
                          <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                        </svg>
                      )}
                    </button>
                  )
                })}
              </div>
              {form.channel !== 'email' && (
                <div className="mt-3 p-3 rounded-lg" style={{backgroundColor:'#fffbeb',border:'1px solid #fde68a'}}>
                  <p style={{fontSize:'12px',color:'#92400e'}}>
                    <strong>⚠️ SMS delivery is in dry-run mode.</strong> Messages will be logged but not actually sent until Arkesel SMS credentials are added in production.
                  </p>
                </div>
              )}
            </div>

            {/* Message fields */}
            <div style={{...cardBase, padding:'24px'}} className="space-y-4">
              {showSubject && (
                <div>
                  <label className="block mb-2" style={{fontSize:'14px',fontWeight:600,color:'var(--color-navy)'}}>Email Subject</label>
                  <input type="text" className="input-field" value={form.subject} onChange={set('subject')}
                         placeholder="e.g. Sunday Service Reminder"/>
                  {errors.subject && <p className="text-xs mt-1" style={{color:'#dc2626'}}>{errors.subject[0]}</p>}
                </div>
              )}
              <div>
                <label className="block mb-2" style={{fontSize:'14px',fontWeight:600,color:'var(--color-navy)'}}>Message Content *</label>
                <textarea className="input-field" value={form.body} onChange={set('body')} rows={8} required
                          placeholder="Type your message here..." style={{resize:'vertical',minHeight:'150px'}}/>
                <div className="flex justify-between mt-1">
                  <p className="text-xs" style={{color:'#9ca3af'}}>
                    {form.channel !== 'email' && form.body.length > 160 && (
                      <span style={{color:'#dc2626'}}>⚠️ SMS over 160 chars will split into multiple messages</span>
                    )}
                  </p>
                  <p className="text-xs" style={{color:'#9ca3af'}}>{form.body.length} chars</p>
                </div>
                {errors.body && <p className="text-xs mt-1" style={{color:'#dc2626'}}>{errors.body[0]}</p>}
              </div>
            </div>
          </div>

          {/* RIGHT: recipients + send (sticky) */}
          <div className="lg:col-span-1">
            <div style={{...cardBase, padding:'24px', position:'sticky', top:'24px'}} className="space-y-4">
              <h3 className="uppercase tracking-wider flex items-center gap-2" style={{fontSize:'14px',fontWeight:700,color:'var(--color-navy)'}}>
                <Icon d={ICONS.groups} size={18} /> Recipients
              </h3>

              <div>
                <label className="block mb-2 uppercase tracking-wider" style={{fontSize:'12px',fontWeight:700,color:'#747780'}}>Audience Target</label>
                <select className="input-field" value={form.recipient_group} onChange={set('recipient_group')} required>
                  <option value="all">All active members</option>
                  <option value="department">A specific department</option>
                  <option value="gender">By gender</option>
                  <option value="status">By membership status</option>
                </select>
              </div>

              {form.recipient_group === 'department' && (
                <select className="input-field" value={form.department_id} onChange={set('department_id')}>
                  <option value="">Select a department</option>
                  {departments.map(d => <option key={d.id} value={d.id}>{d.name} ({d.members_count} members)</option>)}
                </select>
              )}
              {form.recipient_group === 'gender' && (
                <select className="input-field" value={form.gender} onChange={set('gender')}>
                  <option value="">Select</option>
                  <option value="male">Males</option>
                  <option value="female">Females</option>
                </select>
              )}
              {form.recipient_group === 'status' && (
                <select className="input-field" value={form.status} onChange={set('status')}>
                  <option value="active">Active members</option>
                  <option value="inactive">Inactive members</option>
                </select>
              )}

              {/* Live count */}
              <div className="rounded-xl p-4 flex items-center gap-3" style={{backgroundColor:'rgba(215,226,255,0.4)',border:'1px solid var(--color-primary-fixed-dim,#acc7ff)'}}>
                <div className="w-12 h-12 rounded-full flex items-center justify-center flex-shrink-0" style={{backgroundColor:'var(--color-navy)',color:'#fff'}}>
                  <Icon d={ICONS.groups} size={22} />
                </div>
                <div>
                  <p style={{fontFamily:'var(--font-display)',fontSize:'20px',fontWeight:600,color:'var(--color-navy)'}}>
                    {count === 'loading' ? '...' : count === null ? '—' : count}
                    {typeof count === 'number' && <span style={{fontSize:'14px',fontWeight:400}}> recipient{count === 1 ? '' : 's'}</span>}
                  </p>
                  <p style={{fontSize:'12px',color:'#747780'}}>
                    {count === 'loading' ? 'Counting...'
                      : count === null ? 'Set recipient filters'
                      : count === 0 ? 'No recipients match' : 'will receive this message'}
                  </p>
                </div>
              </div>
              <p style={{fontSize:'11px',color:'#9ca3af'}}>Only members with valid contact info for the chosen channel are counted.</p>

              <div className="pt-4 space-y-3" style={{borderTop:'1px solid var(--color-surface-border)'}}>
                <button type="submit" disabled={loading || !count || count === 'loading'}
                        className="btn-primary w-full gap-2" style={{padding:'14px',fontSize:'16px'}}>
                  {loading ? 'Sending...' : (
                    <>Send to {count ?? '...'} <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg></>
                  )}
                </button>
                <button type="button" onClick={() => navigate('/communication')}
                        className="w-full py-3 rounded-xl transition-colors" style={{fontSize:'14px',fontWeight:600,color:'var(--color-navy)'}}>
                  Cancel
                </button>
              </div>
            </div>
          </div>
        </div>
      </form>
      {dialog}
    </div>
  )
}
