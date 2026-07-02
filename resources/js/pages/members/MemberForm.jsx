import React, { useState, useEffect, cloneElement, isValidElement } from 'react'
import { toast } from 'sonner'
import { useNavigate, useParams } from 'react-router-dom'
import { createMember, updateMember, getMember } from '../../api/members'

import { NAVY, MUTED, PLACEHOLDER, BORDER, FONT_DISPLAY } from '../../constants/styles'
const FIELD = ({ label, error, children, name }) => {
  const fieldId = name ? `field-${name}` : undefined
  return (
    <div>
      <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}} htmlFor={fieldId}>{label}</label>
      {fieldId && isValidElement(children) ? cloneElement(children, { id: fieldId }) : children}
      {error && <p className="text-xs mt-1" style={{color:'#dc2626'}}>{error}</p>}
    </div>
  )
}

export default function MemberForm() {
  const navigate    = useNavigate()
  const { id }      = useParams()
  const isEdit      = Boolean(id)

  const [form, setForm] = useState({
    first_name:'', last_name:'', other_names:'', gender:'',
    date_of_birth:'', phone:'', email:'', address:'',
    occupation:'', marital_status:'', join_date:'',
    is_baptised: false, baptism_date:'', status:'active', notes:'',
  })
  const [errors,   setErrors]   = useState({})
  const [loading,  setLoading]  = useState(false)
  const [fetching, setFetching] = useState(isEdit)

  useEffect(() => {
    if (!isEdit) return
    const controller = new AbortController()
    let mounted = true
    setFetching(true)
    getMember(id, controller.signal)
      .then(res => {
        if (!mounted) return
        const m = res.data.data
        setForm({
          first_name:    m.first_name    ?? '',
          last_name:     m.last_name     ?? '',
          other_names:   m.other_names   ?? '',
          gender:        m.gender        ?? '',
          date_of_birth: m.date_of_birth ?? '',
          phone:         m.phone         ?? '',
          email:         m.email         ?? '',
          address:       m.address       ?? '',
          occupation:    m.occupation    ?? '',
          marital_status:m.marital_status?? '',
          join_date:     m.join_date     ?? '',
          is_baptised:   m.is_baptised   ?? false,
          baptism_date:  m.baptism_date  ?? '',
          status:        m.status        ?? 'active',
          notes:         m.notes         ?? '',
        })
      })
      .catch(() => { if (mounted) navigate('/members') })
      .finally(() => { if (mounted) setFetching(false) })
    return () => { mounted = false; controller.abort() }
  }, [id, isEdit])

  const set = (field) => (e) => {
    const value = e.target.type === 'checkbox' ? e.target.checked : e.target.value
    setForm(f => ({ ...f, [field]: value }))
    setErrors(e => ({ ...e, [field]: null }))
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    setLoading(true)
    setErrors({})
    try {
      if (isEdit) {
        await updateMember(id, form)
      } else {
        await createMember(form)
      }
      navigate('/members')
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
      <svg className="animate-spin w-8 h-8" style={{color:NAVY}} fill="none" viewBox="0 0 24 24">
        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
      </svg>
    </div>
  )

  return (
    <div className="max-w-3xl mx-auto space-y-6">
      {/* Header */}
      <div className="flex items-center gap-4">
        <button onClick={() => navigate('/members')}
                 aria-label="Back to members"
                 className="min-w-[44px] min-h-[44px] flex items-center justify-center p-2 rounded-lg transition-colors"
                 style={{backgroundColor:'white',border:BORDER}}>
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7"/>
          </svg>
        </button>
        <div>
          <h2 className="text-xl font-bold" style={{fontFamily:FONT_DISPLAY,color:NAVY}}>
            {isEdit ? 'Edit Member' : 'Register New Member'}
          </h2>
          <p className="text-sm" style={{color:'#6b7280'}}>
            {isEdit ? 'Update member information' : 'Add a new member to the church register'}
          </p>
        </div>
      </div>

      <form onSubmit={handleSubmit} className="space-y-6">

        {/* Personal Information */}
        <div className="card space-y-4">
          <h3 className="font-semibold text-sm uppercase tracking-wider" style={{color:NAVY}}>
            Personal Information
          </h3>
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <FIELD label="First Name *" error={errors.first_name?.[0]} name="first_name">
              <input type="text" className="input-field" value={form.first_name}
                     onChange={set('first_name')} required placeholder="e.g. Kofi"/>
            </FIELD>
            <FIELD label="Last Name *" error={errors.last_name?.[0]} name="last_name">
              <input type="text" className="input-field" value={form.last_name}
                     onChange={set('last_name')} required placeholder="e.g. Mensah"/>
            </FIELD>
            <FIELD label="Other Names" error={errors.other_names?.[0]} name="other_names">
              <input type="text" className="input-field" value={form.other_names}
                     onChange={set('other_names')} placeholder="Middle name(s)"/>
            </FIELD>
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <FIELD label="Gender *" error={errors.gender?.[0]} name="gender">
              <select className="input-field" value={form.gender} onChange={set('gender')} required>
                <option value="">Select gender</option>
                <option value="male">Male</option>
                <option value="female">Female</option>
              </select>
            </FIELD>
            <FIELD label="Date of Birth" error={errors.date_of_birth?.[0]} name="date_of_birth">
              <input type="date" className="input-field" value={form.date_of_birth}
                     onChange={set('date_of_birth')}/>
            </FIELD>
            <FIELD label="Marital Status" error={errors.marital_status?.[0]} name="marital_status">
              <select className="input-field" value={form.marital_status} onChange={set('marital_status')}>
                <option value="">Select status</option>
                <option value="single">Single</option>
                <option value="married">Married</option>
                <option value="widowed">Widowed</option>
                <option value="divorced">Divorced</option>
              </select>
            </FIELD>
          </div>
        </div>

        {/* Contact Information */}
        <div className="card space-y-4">
          <h3 className="font-semibold text-sm uppercase tracking-wider" style={{color:NAVY}}>
            Contact Information
          </h3>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <FIELD label="Phone Number" error={errors.phone?.[0]} name="phone">
              <input type="tel" className="input-field" value={form.phone}
                     onChange={set('phone')} placeholder="e.g. 0244000000"/>
            </FIELD>
            <FIELD label="Email Address" error={errors.email?.[0]} name="email">
              <input type="email" className="input-field" value={form.email}
                     onChange={set('email')} placeholder="e.g. kofi@email.com"/>
            </FIELD>
          </div>
          <FIELD label="Home Address" error={errors.address?.[0]} name="address">
            <textarea className="input-field" value={form.address} onChange={set('address')}
                      rows={2} placeholder="Street, area, city"/>
          </FIELD>
          <FIELD label="Occupation" error={errors.occupation?.[0]} name="occupation">
            <input type="text" className="input-field" value={form.occupation}
                   onChange={set('occupation')} placeholder="e.g. Teacher, Trader, Engineer"/>
          </FIELD>
        </div>

        {/* Church Information */}
        <div className="card space-y-4">
          <h3 className="font-semibold text-sm uppercase tracking-wider" style={{color:NAVY}}>
            Church Information
          </h3>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <FIELD label="Date Joined" error={errors.join_date?.[0]} name="join_date">
              <input type="date" className="input-field" value={form.join_date}
                     onChange={set('join_date')}/>
            </FIELD>
            <FIELD label="Member Status" error={errors.status?.[0]} name="status">
              <select className="input-field" value={form.status} onChange={set('status')}>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="transferred">Transferred</option>
                <option value="deceased">Deceased</option>
              </select>
            </FIELD>
          </div>
          <div className="flex items-center gap-3">
            <input type="checkbox" id="is_baptised" checked={form.is_baptised}
                   onChange={set('is_baptised')}
                   className="w-4 h-4 rounded" style={{accentColor:NAVY}}/>
            <label htmlFor="is_baptised" className="text-sm font-medium" style={{color:'#374151'}}>
              Member has been baptised
            </label>
          </div>
          {form.is_baptised && (
            <FIELD label="Baptism Date" error={errors.baptism_date?.[0]} name="baptism_date">
              <input type="date" className="input-field" value={form.baptism_date}
                     onChange={set('baptism_date')}/>
            </FIELD>
          )}
          <FIELD label="Notes" error={errors.notes?.[0]} name="notes">
            <textarea className="input-field" value={form.notes} onChange={set('notes')}
                      rows={3} placeholder="Any additional notes about this member..."/>
          </FIELD>
        </div>

        {/* Actions */}
        <div className="flex items-center justify-end gap-3">
          <button type="button" onClick={() => navigate('/members')}
                  className="px-6 py-2.5 rounded-lg text-sm font-semibold transition-colors"
                  style={{backgroundColor:'white',border:BORDER,color:'#374151'}}>
            Cancel
          </button>
          <button type="submit" disabled={loading} className="btn-primary px-8 py-2.5">
            {loading
              ? <><svg className="animate-spin w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24">
                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                  </svg>Saving...</>
              : isEdit ? 'Update Member' : 'Register Member'
            }
          </button>
        </div>
      </form>
    </div>
  )
}
