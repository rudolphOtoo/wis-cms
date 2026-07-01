import React, { useState, useEffect } from 'react'
import { toast } from 'sonner'
import { useNavigate, useParams } from 'react-router-dom'
import { createChild, updateChild, getChild } from '../../api/children'
import { getMembers } from '../../api/members'

const cardBase = {
  backgroundColor: '#fff',
  border: '1px solid var(--color-surface-border)',
  borderRadius: '16px',
  boxShadow: '0 4px 12px rgba(13,31,60,0.05)',
}

const FIELD = ({ label, error, children }) => (
  <div>
    <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>{label}</label>
    {children}
    {error && <p className="text-xs mt-1" style={{color:'#dc2626'}}>{error}</p>}
  </div>
)

const SectionHeader = ({ num, title }) => (
  <div className="flex items-center gap-2 mb-4">
    <span className="rounded-full text-white flex items-center justify-center font-bold"
          style={{width:'24px',height:'24px',fontSize:'12px',backgroundColor:'var(--color-navy)'}}>{num}</span>
    <h3 className="uppercase tracking-wider" style={{fontSize:'14px',fontWeight:700,color:'var(--color-navy)'}}>{title}</h3>
  </div>
)

const CLASS_OPTIONS = [
  'Nursery (0-3)', 'Beginners (4-5)', 'Primary 1 (6-7)',
  'Primary 2 (8-9)', 'Juniors (10-11)', 'Teens (12-13)',
]

export default function ChildForm() {
  const navigate = useNavigate()
  const { id }   = useParams()
  const isEdit   = Boolean(id)

  const [form, setForm] = useState({
    guardian_member_id:'', first_name:'', last_name:'',
    gender:'', date_of_birth:'', class_group:'',
    is_active: true, notes:'',
  })
  const [members,    setMembers]    = useState([])
  const [errors,     setErrors]     = useState({})
  const [loading,    setLoading]    = useState(false)
  const [fetching,   setFetching]   = useState(isEdit)

  useEffect(() => {
    getMembers({ per_page: 500, status: 'active' })
      .then(res => setMembers(res.data.data))

    if (isEdit) {
      setFetching(true)
      getChild(id)
        .then(res => {
          const c = res.data.data
          setForm({
            guardian_member_id: c.guardian?.id   ?? '',
            first_name:         c.first_name     ?? '',
            last_name:          c.last_name      ?? '',
            gender:             c.gender         ?? '',
            date_of_birth:      c.date_of_birth  ?? '',
            class_group:        c.class_group    ?? '',
            is_active:          c.is_active      ?? true,
            notes:              c.notes          ?? '',
          })
        })
        .catch(() => navigate('/children'))
        .finally(() => setFetching(false))
    }
  }, [id, isEdit])

  const set = (field) => (e) => {
    const v = e.target.type === 'checkbox' ? e.target.checked : e.target.value
    setForm(f => ({ ...f, [field]: v }))
    setErrors(e => ({ ...e, [field]: null }))
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    setLoading(true)
    setErrors({})
    try {
      if (isEdit) { await updateChild(id, form) }
      else        { await createChild(form) }
      navigate('/children')
    } catch (err) {
      if (err.response?.status === 422) {
        setErrors(err.response.data.errors ?? {})
      } else {
        toast.error('Something went wrong. Please try again.')
      }
    } finally {
      setLoading(false)
    }
  }

  if (fetching) return (
    <div className="flex items-center justify-center py-24">
      <svg className="animate-spin w-8 h-8" style={{color:'var(--color-navy)'}} fill="none" viewBox="0 0 24 24">
        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
      </svg>
    </div>
  )

  return (
    <div className="max-w-2xl mx-auto space-y-6">

      <div className="flex items-center gap-4">
        <button onClick={() => navigate('/children')}
                className="w-10 h-10 flex items-center justify-center rounded-full"
                style={{backgroundColor:'white',border:'1px solid var(--color-surface-border)',color:'var(--color-navy)'}}>
          <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7"/>
          </svg>
        </button>
        <div>
          <h2 className="font-bold" style={{fontFamily:'var(--font-display)',fontSize:'24px',color:'var(--color-navy)'}}>
            {isEdit ? 'Edit Child' : 'Add Child to Register'}
          </h2>
          <p className="text-sm" style={{color:'#6b7280'}}>
            {isEdit ? 'Update child details' : 'Connect a new young member to the church family.'}
          </p>
        </div>
      </div>

      <form onSubmit={handleSubmit} className="space-y-6">

        {/* Section 1 */}
        <div style={{...cardBase, padding:'24px'}}>
          <SectionHeader num="1" title="Child's Information" />
          <div className="space-y-4">
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <FIELD label="First Name *" error={errors.first_name?.[0]}>
                <input type="text" className="input-field" value={form.first_name} onChange={set('first_name')} required placeholder="e.g. Kojo"/>
              </FIELD>
              <FIELD label="Last Name *" error={errors.last_name?.[0]}>
                <input type="text" className="input-field" value={form.last_name} onChange={set('last_name')} required placeholder="e.g. Asante"/>
              </FIELD>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <FIELD label="Gender *" error={errors.gender?.[0]}>
                <select className="input-field" value={form.gender} onChange={set('gender')} required>
                  <option value="">Select</option>
                  <option value="male">Male</option>
                  <option value="female">Female</option>
                </select>
              </FIELD>
              <FIELD label="Date of Birth" error={errors.date_of_birth?.[0]}>
                <input type="date" className="input-field" value={form.date_of_birth} onChange={set('date_of_birth')}/>
              </FIELD>
              <FIELD label="Class Group" error={errors.class_group?.[0]}>
                <select className="input-field" value={form.class_group} onChange={set('class_group')}>
                  <option value="">Select</option>
                  {CLASS_OPTIONS.map(c => <option key={c} value={c}>{c}</option>)}
                </select>
              </FIELD>
            </div>
          </div>
        </div>

        {/* Section 2 */}
        <div style={{...cardBase, padding:'24px'}}>
          <SectionHeader num="2" title="Guardian Information" />
          <FIELD label="Guardian Member *" error={errors.guardian_member_id?.[0]}>
            <select className="input-field" value={form.guardian_member_id} onChange={set('guardian_member_id')} required>
              <option value="">Select the parent or guardian</option>
              {members.map(m => (
                <option key={m.id} value={m.id}>{m.full_name} ({m.member_number})</option>
              ))}
            </select>
          </FIELD>
          <p className="text-xs mt-2" style={{color:'#9ca3af'}}>The guardian must already be a registered church member.</p>
        </div>

        {/* Section 3 */}
        <div style={{...cardBase, padding:'24px'}}>
          <SectionHeader num="3" title="Settings" />
          <div className="space-y-4">
            <div className="flex items-center justify-between rounded-lg p-3"
                 style={{border:'1px solid var(--color-surface-border)',backgroundColor:'#f8f9fc'}}>
              <div className="flex items-center gap-2">
                <svg className="w-5 h-5" style={{color:'var(--color-navy)'}} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <div>
                  <p style={{fontSize:'14px',fontWeight:600,color:'#191c1e'}}>Active Status</p>
                  <p style={{fontSize:'12px',color:'#747780'}}>Child will be active in the register.</p>
                </div>
              </div>
              <label className="relative inline-flex items-center cursor-pointer">
                <input type="checkbox" className="sr-only peer" checked={form.is_active} onChange={set('is_active')}/>
                <div className="peer rounded-full"
                     style={{width:'44px',height:'24px',backgroundColor: form.is_active ? 'var(--color-navy)' : '#c4c6d0',transition:'background-color 0.2s'}}>
                  <div className="rounded-full bg-white"
                       style={{width:'20px',height:'20px',marginTop:'2px',marginLeft: form.is_active ? '22px' : '2px',transition:'margin-left 0.2s',boxShadow:'0 1px 2px rgba(0,0,0,0.2)'}}/>
                </div>
              </label>
            </div>
            <FIELD label="Internal Notes" error={errors.notes?.[0]}>
              <textarea className="input-field" value={form.notes} onChange={set('notes')} rows={3}
                        placeholder="Allergies, special needs, or pastoral notes..."/>
            </FIELD>
          </div>
        </div>

        <div className="flex items-center justify-end gap-3">
          <button type="button" onClick={() => navigate('/children')}
                  className="px-6 py-2.5 rounded-lg text-sm font-semibold"
                  style={{backgroundColor:'white',border:'1px solid var(--color-navy)',color:'var(--color-navy)'}}>
            Cancel
          </button>
          <button type="submit" disabled={loading} className="btn-primary px-8 py-2.5 gap-2">
            {!loading && (
              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
            )}
            {loading ? 'Saving...' : isEdit ? 'Update Child' : 'Add to Register'}
          </button>
        </div>
      </form>
    </div>
  )
}
