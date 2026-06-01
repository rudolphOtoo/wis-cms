import React, { useState, useEffect } from 'react'
import { getFollowUpSettings, updateFollowUpSettings } from '../../api/settings'

// Sample data for the live preview — matches the placeholders the
// PHP renderer (FollowUpTemplateRenderer.php) supports. Keep in sync
// with App\Services\FollowUpTemplateRenderer.
const SAMPLE = {
  name: 'Abena Boateng',
  first_name: 'Abena',
  cell: 'Bethel Fellowship',
  department: 'Choir',
  unit: 'Bethel Fellowship',
  church: 'Wesleyan International Society',
}

const PLACEHOLDERS = [
  { key: '{name}',        what: "member's full name" },
  { key: '{first_name}',  what: "first name only" },
  { key: '{cell}',        what: 'cell group name' },
  { key: '{department}',  what: 'department name' },
  { key: '{unit}',        what: 'whichever applies' },
  { key: '{date}',        what: 'meeting date' },
  { key: '{church}',      what: 'church/branch name' },
]

// Match the PHP renderer: case-insensitive replace, plus a formatted date.
function renderPreview(template) {
  if (!template) return ''
  const today = new Date()
  const dayName = today.toLocaleDateString('en-GB', { weekday: 'long' })
  const dayNum = today.getDate()
  const monthName = today.toLocaleDateString('en-GB', { month: 'long' })
  const date = `${dayName}, ${dayNum} ${monthName}`

  const vars = { ...SAMPLE, date }
  let out = template
  for (const [k, v] of Object.entries(vars)) {
    out = out.replace(new RegExp(`\\{${k}\\}`, 'gi'), v)
  }
  return out
}

const card = {
  backgroundColor: '#fff',
  border: '1px solid var(--color-surface-border)',
  borderRadius: '16px',
  boxShadow: '0 4px 12px rgba(13,31,60,0.05)',
  padding: '24px',
}

export default function FollowUpSettings() {
  const [form, setForm] = useState({
    follow_up_enabled: true,
    follow_up_delay_hours: 2,
    follow_up_present_template: '',
    follow_up_absent_template: '',
  })
  const [initial,  setInitial]  = useState(null)
  const [loading,  setLoading]  = useState(true)
  const [saving,   setSaving]   = useState(false)
  const [errors,   setErrors]   = useState({})
  const [savedAt,  setSavedAt]  = useState(null)

  useEffect(() => {
    getFollowUpSettings()
      .then(res => {
        setForm(res.data.data)
        setInitial(res.data.data)
      })
      .catch(err => console.error(err))
      .finally(() => setLoading(false))
  }, [])

  const set = (field) => (e) => {
    const value = e.target.type === 'checkbox' ? e.target.checked
                  : e.target.type === 'number' ? Number(e.target.value)
                  : e.target.value
    setForm(f => ({ ...f, [field]: value }))
    setErrors(e => ({ ...e, [field]: null }))
  }

  const isDirty = initial && (
    form.follow_up_enabled !== initial.follow_up_enabled ||
    form.follow_up_delay_hours !== initial.follow_up_delay_hours ||
    form.follow_up_present_template !== initial.follow_up_present_template ||
    form.follow_up_absent_template !== initial.follow_up_absent_template
  )

  const save = async () => {
    setSaving(true)
    setErrors({})
    try {
      const res = await updateFollowUpSettings(form)
      setForm(res.data.data)
      setInitial(res.data.data)
      setSavedAt(new Date())
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

  const reset = () => {
    if (initial) {
      setForm(initial)
      setErrors({})
    }
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center py-24">
        <svg className="animate-spin w-8 h-8" style={{color:'var(--color-navy)'}} fill="none" viewBox="0 0 24 24">
          <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
          <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
        </svg>
      </div>
    )
  }

  return (
    <div className="max-w-5xl mx-auto">
      <div className="mb-6">
        <h1 className="text-2xl font-bold" style={{fontFamily:'var(--font-display)', color:'var(--color-navy)'}}>Follow-up SMS Settings</h1>
        <p className="text-sm mt-1" style={{color:'#6b7280'}}>
          Configure the automatic SMS that goes to cell members after attendance is taken.
        </p>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-5 gap-6">
        {/* LEFT — FORM (3/5) */}
        <div className="lg:col-span-3 space-y-6">
          <div style={card}>
            <div className="flex items-start justify-between">
              <div>
                <h2 className="font-bold" style={{fontFamily:'var(--font-display)', fontSize:'18px', color:'var(--color-navy)'}}>Status</h2>
                <p className="text-sm mt-1" style={{color:'#6b7280'}}>
                  When enabled, the system sends SMS automatically after each meeting.
                </p>
              </div>
              <label className="inline-flex items-center cursor-pointer">
                <input type="checkbox" className="sr-only peer"
                       checked={form.follow_up_enabled}
                       onChange={set('follow_up_enabled')}/>
                <div className="relative w-11 h-6 rounded-full transition"
                     style={{backgroundColor: form.follow_up_enabled ? 'var(--color-navy)' : '#d1d5db'}}>
                  <div className="absolute top-0.5 w-5 h-5 bg-white rounded-full transition-transform"
                       style={{transform: form.follow_up_enabled ? 'translateX(22px)' : 'translateX(2px)'}}/>
                </div>
                <span className="ml-3 text-sm font-medium" style={{color: form.follow_up_enabled ? 'var(--color-navy)' : '#6b7280'}}>
                  {form.follow_up_enabled ? 'Enabled' : 'Disabled'}
                </span>
              </label>
            </div>

            <div className="mt-5">
              <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>
                Delay before sending (hours)
              </label>
              <input type="number" className="input-field" style={{maxWidth:'120px'}}
                     min="1" max="24"
                     value={form.follow_up_delay_hours}
                     onChange={set('follow_up_delay_hours')}/>
              <p className="text-xs mt-1" style={{color:'#9ca3af'}}>
                The SMS is queued this many hours after the leader starts taking attendance. 1-24 hours.
              </p>
              {errors.follow_up_delay_hours && (
                <p className="text-xs mt-1" style={{color:'#dc2626'}}>{errors.follow_up_delay_hours[0]}</p>
              )}
            </div>
          </div>

          <div style={card}>
            <h2 className="font-bold" style={{fontFamily:'var(--font-display)', fontSize:'18px', color:'var(--color-navy)'}}>Present member message</h2>
            <p className="text-sm mt-1 mb-3" style={{color:'#6b7280'}}>
              Sent to each member who was marked present.
            </p>
            <textarea className="input-field" rows={4}
                      value={form.follow_up_present_template}
                      onChange={set('follow_up_present_template')}
                      maxLength={1000}/>
            <div className="flex justify-between text-xs mt-1" style={{color:'#9ca3af'}}>
              <span>{form.follow_up_present_template.length} / 1000 characters</span>
              <span>Use placeholders like {'{name}'} or {'{cell}'} — see right panel</span>
            </div>
            {errors.follow_up_present_template && (
              <p className="text-xs mt-1" style={{color:'#dc2626'}}>{errors.follow_up_present_template[0]}</p>
            )}
          </div>

          <div style={card}>
            <h2 className="font-bold" style={{fontFamily:'var(--font-display)', fontSize:'18px', color:'var(--color-navy)'}}>Absent member message</h2>
            <p className="text-sm mt-1 mb-3" style={{color:'#6b7280'}}>
              Sent to each member who was marked absent.
            </p>
            <textarea className="input-field" rows={4}
                      value={form.follow_up_absent_template}
                      onChange={set('follow_up_absent_template')}
                      maxLength={1000}/>
            <div className="flex justify-between text-xs mt-1" style={{color:'#9ca3af'}}>
              <span>{form.follow_up_absent_template.length} / 1000 characters</span>
            </div>
            {errors.follow_up_absent_template && (
              <p className="text-xs mt-1" style={{color:'#dc2626'}}>{errors.follow_up_absent_template[0]}</p>
            )}
          </div>

          <div className="flex items-center justify-end gap-3">
            {savedAt && !isDirty && (
              <span className="text-sm" style={{color:'#15803d'}}>
                ✓ Saved {savedAt.toLocaleTimeString()}
              </span>
            )}
            <button type="button" onClick={reset} disabled={!isDirty || saving}
                    className="px-5 py-2 rounded-lg text-sm font-semibold"
                    style={{backgroundColor:'white', border:'1px solid var(--color-surface-border)', color:'#374151',
                            opacity: (!isDirty || saving) ? 0.5 : 1}}>
              Reset
            </button>
            <button type="button" onClick={save} disabled={!isDirty || saving}
                    className="btn-primary px-6 py-2"
                    style={{opacity: (!isDirty || saving) ? 0.5 : 1}}>
              {saving ? 'Saving…' : 'Save Settings'}
            </button>
          </div>
        </div>

        {/* RIGHT — REFERENCE + PREVIEW (2/5) */}
        <div className="lg:col-span-2 space-y-4">
          <div style={card}>
            <h2 className="font-bold mb-3" style={{fontFamily:'var(--font-display)', fontSize:'16px', color:'var(--color-navy)'}}>
              Available placeholders
            </h2>
            <div className="space-y-1.5">
              {PLACEHOLDERS.map(p => (
                <div key={p.key} className="flex items-baseline gap-2 text-sm">
                  <code style={{fontFamily:'monospace', color:'var(--color-navy)', fontWeight:600, fontSize:'12px',
                                backgroundColor:'#f3f4f6', padding:'2px 6px', borderRadius:'4px'}}>{p.key}</code>
                  <span style={{color:'#6b7280', fontSize:'12px'}}>{p.what}</span>
                </div>
              ))}
            </div>
            <p className="text-xs mt-3" style={{color:'#9ca3af', fontStyle:'italic'}}>
              Placeholders are case-insensitive. {'{NAME}'} works too.
            </p>
          </div>

          <div style={card}>
            <h2 className="font-bold mb-3" style={{fontFamily:'var(--font-display)', fontSize:'16px', color:'var(--color-navy)'}}>
              Live preview
            </h2>
            <p className="text-xs mb-2" style={{color:'#9ca3af'}}>
              With sample: {SAMPLE.name} in {SAMPLE.cell}
            </p>
            <div className="space-y-3">
              <div>
                <div style={{fontSize:'11px', fontWeight:600, color:'#15803d', textTransform:'uppercase', letterSpacing:'0.5px', marginBottom:'4px'}}>
                  ✓ Present
                </div>
                <div style={{backgroundColor:'#f0fdf4', border:'1px solid #86efac', borderRadius:'8px', padding:'10px', fontSize:'13px', color:'#1f2937', lineHeight:1.5}}>
                  {renderPreview(form.follow_up_present_template) || <span style={{color:'#9ca3af', fontStyle:'italic'}}>Type a message to preview…</span>}
                </div>
              </div>
              <div>
                <div style={{fontSize:'11px', fontWeight:600, color:'#92400e', textTransform:'uppercase', letterSpacing:'0.5px', marginBottom:'4px'}}>
                  ✕ Absent
                </div>
                <div style={{backgroundColor:'#fef3c7', border:'1px solid #fcd34d', borderRadius:'8px', padding:'10px', fontSize:'13px', color:'#1f2937', lineHeight:1.5}}>
                  {renderPreview(form.follow_up_absent_template) || <span style={{color:'#9ca3af', fontStyle:'italic'}}>Type a message to preview…</span>}
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
