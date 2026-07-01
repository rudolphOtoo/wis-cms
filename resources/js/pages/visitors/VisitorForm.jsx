import React, { useState, useEffect } from 'react'
import { toast } from 'sonner'
import { useNavigate, useParams } from 'react-router-dom'
import { createVisitor, updateVisitor, getVisitor } from '../../api/visitors'

const FIELD = ({ label, error, children }) => (
  <div>
    <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>{label}</label>
    {children}
    {error && <p className="text-xs mt-1" style={{color:'#dc2626'}}>{error}</p>}
  </div>
)

const HOW_HEARD = [
  'Friend or Family', 'Social Media', 'Flyer/Poster', 'Radio',
  'Walked Past', 'Online Search', 'Church Event', 'Other',
]

export default function VisitorForm() {
  const navigate = useNavigate()
  const { id }   = useParams()
  const isEdit   = Boolean(id)

  const [form, setForm] = useState({
    first_name:'', last_name:'', phone:'', email:'',
    address:'', how_they_heard:'', visit_date: new Date().toISOString().split('T')[0],
    follow_up_status:'pending', notes:'',
  })
  const [errors,   setErrors]   = useState({})
  const [loading,  setLoading]  = useState(false)
  const [fetching, setFetching] = useState(isEdit)

  useEffect(() => {
    if (!isEdit) return
    setFetching(true)
    getVisitor(id)
      .then(res => {
        const v = res.data.data
        setForm({
          first_name:       v.first_name       ?? '',
          last_name:        v.last_name        ?? '',
          phone:            v.phone            ?? '',
          email:            v.email            ?? '',
          address:          v.address          ?? '',
          how_they_heard:   v.how_they_heard   ?? '',
          visit_date:       v.visit_date       ?? '',
          follow_up_status: v.follow_up_status ?? 'pending',
          notes:            v.notes            ?? '',
        })
      })
      .catch(() => navigate('/visitors'))
      .finally(() => setFetching(false))
  }, [id, isEdit])

  const set = (field) => (e) => {
    setForm(f => ({ ...f, [field]: e.target.value }))
    setErrors(e => ({ ...e, [field]: null }))
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    setLoading(true)
    setErrors({})
    try {
      if (isEdit) { await updateVisitor(id, form) }
      else        { await createVisitor(form) }
      navigate('/visitors')
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
        <button onClick={() => navigate('/visitors')}
                className="min-w-[44px] min-h-[44px] flex items-center justify-center p-2 rounded-lg transition-colors"
                style={{backgroundColor:'white',border:'1px solid var(--color-surface-border)'}}>
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7"/>
          </svg>
        </button>
        <div>
          <h2 className="text-xl font-bold"
              style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
            {isEdit ? 'Edit Visitor' : 'Record New Visitor'}
          </h2>
          <p className="text-sm" style={{color:'#6b7280'}}>
            {isEdit ? 'Update visitor information' : 'Record a first-timer or visitor'}
          </p>
        </div>
      </div>

      <form onSubmit={handleSubmit} className="space-y-6">

        {/* Personal Info */}
        <div className="card space-y-4">
          <h3 className="font-semibold text-sm uppercase tracking-wider"
              style={{color:'var(--color-navy)'}}>
            Visitor Information
          </h3>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <FIELD label="First Name *" error={errors.first_name?.[0]}>
              <input type="text" className="input-field" value={form.first_name}
                     onChange={set('first_name')} required placeholder="e.g. Ama"/>
            </FIELD>
            <FIELD label="Last Name *" error={errors.last_name?.[0]}>
              <input type="text" className="input-field" value={form.last_name}
                     onChange={set('last_name')} required placeholder="e.g. Asante"/>
            </FIELD>
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <FIELD label="Phone Number" error={errors.phone?.[0]}>
              <input type="tel" className="input-field" value={form.phone}
                     onChange={set('phone')} placeholder="e.g. 0244000000"/>
            </FIELD>
            <FIELD label="Email Address" error={errors.email?.[0]}>
              <input type="email" className="input-field" value={form.email}
                     onChange={set('email')} placeholder="e.g. ama@email.com"/>
            </FIELD>
          </div>
          <FIELD label="Home Address" error={errors.address?.[0]}>
            <input type="text" className="input-field" value={form.address}
                   onChange={set('address')} placeholder="Area, city"/>
          </FIELD>
        </div>

        {/* Visit Details */}
        <div className="card space-y-4">
          <h3 className="font-semibold text-sm uppercase tracking-wider"
              style={{color:'var(--color-navy)'}}>
            Visit Details
          </h3>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <FIELD label="Date of Visit *" error={errors.visit_date?.[0]}>
              <input type="date" className="input-field" value={form.visit_date}
                     onChange={set('visit_date')} required/>
            </FIELD>
            <FIELD label="How Did They Hear About Us?" error={errors.how_they_heard?.[0]}>
              <select className="input-field" value={form.how_they_heard}
                      onChange={set('how_they_heard')}>
                <option value="">Select option</option>
                {HOW_HEARD.map(h => (
                  <option key={h} value={h}>{h}</option>
                ))}
              </select>
            </FIELD>
          </div>
          <FIELD label="Follow-up Status" error={errors.follow_up_status?.[0]}>
            <select className="input-field" value={form.follow_up_status}
                    onChange={set('follow_up_status')}>
              <option value="pending">Pending — not yet contacted</option>
              <option value="contacted">Contacted — reached out</option>
              <option value="not_interested">Not Interested</option>
              <option value="joined">Joined — became a member</option>
            </select>
          </FIELD>
          <FIELD label="Notes" error={errors.notes?.[0]}>
            <textarea className="input-field" value={form.notes} onChange={set('notes')}
                      rows={3} placeholder="Any additional notes about this visitor..."/>
          </FIELD>
        </div>

        <div className="flex items-center justify-end gap-3">
          <button type="button" onClick={() => navigate('/visitors')}
                  className="px-6 py-2.5 rounded-lg text-sm font-semibold"
                  style={{backgroundColor:'white',border:'1px solid var(--color-surface-border)',
                          color:'#374151'}}>
            Cancel
          </button>
          <button type="submit" disabled={loading} className="btn-primary px-8 py-2.5">
            {loading
              ? <><svg className="animate-spin w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24">
                    <circle className="opacity-25" cx="12" cy="12" r="10"
                            stroke="currentColor" strokeWidth="4"/>
                    <path className="opacity-75" fill="currentColor"
                          d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                  </svg>Saving...</>
              : isEdit ? 'Update Visitor' : 'Record Visitor'
            }
          </button>
        </div>
      </form>
    </div>
  )
}
