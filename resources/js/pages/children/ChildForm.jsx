import React, { useState, useEffect } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { createChild, updateChild, getChild } from '../../api/children'
import { getMembers } from '../../api/members'

const FIELD = ({ label, error, children }) => (
  <div>
    <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>{label}</label>
    {children}
    {error && <p className="text-xs mt-1" style={{color:'#dc2626'}}>{error}</p>}
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
        alert('Something went wrong. Please try again.')
      }
    } finally {
      setLoading(false)
    }
  }

  if (fetching) return (
    <div className="flex items-center justify-center py-24">
      <svg className="animate-spin w-8 h-8" style={{color:'var(--color-navy)'}}
           fill="none" viewBox="0 0 24 24">
        <circle className="opacity-25" cx="12" cy="12" r="10"
                stroke="currentColor" strokeWidth="4"/>
        <path className="opacity-75" fill="currentColor"
              d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
      </svg>
    </div>
  )

  return (
    <div className="max-w-2xl mx-auto space-y-6">

      <div className="flex items-center gap-4">
        <button onClick={() => navigate('/children')}
                className="p-2 rounded-lg"
                style={{backgroundColor:'white',border:'1px solid var(--color-surface-border)'}}>
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7"/>
          </svg>
        </button>
        <div>
          <h2 className="text-xl font-bold"
              style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
            {isEdit ? 'Edit Child' : 'Add Child'}
          </h2>
          <p className="text-sm" style={{color:'#6b7280'}}>
            {isEdit ? 'Update child details' : "Register a child in the church's children ministry"}
          </p>
        </div>
      </div>

      <form onSubmit={handleSubmit} className="space-y-6">

        <div className="card space-y-4">
          <h3 className="font-semibold text-sm uppercase tracking-wider"
              style={{color:'var(--color-navy)'}}>
            Child's Information
          </h3>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <FIELD label="First Name *" error={errors.first_name?.[0]}>
              <input type="text" className="input-field" value={form.first_name}
                     onChange={set('first_name')} required placeholder="e.g. Kojo"/>
            </FIELD>
            <FIELD label="Last Name *" error={errors.last_name?.[0]}>
              <input type="text" className="input-field" value={form.last_name}
                     onChange={set('last_name')} required placeholder="e.g. Asante"/>
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
              <input type="date" className="input-field" value={form.date_of_birth}
                     onChange={set('date_of_birth')}/>
            </FIELD>
            <FIELD label="Class Group" error={errors.class_group?.[0]}>
              <select className="input-field" value={form.class_group} onChange={set('class_group')}>
                <option value="">Select</option>
                {CLASS_OPTIONS.map(c => (
                  <option key={c} value={c}>{c}</option>
                ))}
              </select>
            </FIELD>
          </div>
        </div>

        <div className="card space-y-4">
          <h3 className="font-semibold text-sm uppercase tracking-wider"
              style={{color:'var(--color-navy)'}}>
            Guardian (Parent or Caretaker)
          </h3>
          <FIELD label="Guardian Member *" error={errors.guardian_member_id?.[0]}>
            <select className="input-field" value={form.guardian_member_id}
                    onChange={set('guardian_member_id')} required>
              <option value="">Select the parent or guardian</option>
              {members.map(m => (
                <option key={m.id} value={m.id}>
                  {m.full_name} ({m.member_number})
                </option>
              ))}
            </select>
          </FIELD>
          <p className="text-xs" style={{color:'#9ca3af'}}>
            The guardian must already be a registered church member.
          </p>
        </div>

        <div className="card space-y-4">
          <div className="flex items-center gap-3">
            <input type="checkbox" id="is_active" checked={form.is_active}
                   onChange={set('is_active')}
                   className="w-4 h-4" style={{accentColor:'var(--color-navy)'}}/>
            <label htmlFor="is_active" className="text-sm font-medium" style={{color:'#374151'}}>
              Child is actively attending children's service
            </label>
          </div>
          <FIELD label="Notes" error={errors.notes?.[0]}>
            <textarea className="input-field" value={form.notes} onChange={set('notes')}
                      rows={3} placeholder="Any allergies, special needs, or notes..."/>
          </FIELD>
        </div>

        <div className="flex items-center justify-end gap-3">
          <button type="button" onClick={() => navigate('/children')}
                  className="px-6 py-2.5 rounded-lg text-sm font-semibold"
                  style={{backgroundColor:'white',border:'1px solid var(--color-surface-border)',color:'#374151'}}>
            Cancel
          </button>
          <button type="submit" disabled={loading} className="btn-primary px-8 py-2.5">
            {loading ? 'Saving...' : isEdit ? 'Update Child' : 'Add Child'}
          </button>
        </div>
      </form>
    </div>
  )
}
