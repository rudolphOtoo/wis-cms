import React, { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { getServiceTypes, createSession } from '../../api/attendance'

export default function NewSession() {
  const navigate = useNavigate()
  const [serviceTypes, setServiceTypes] = useState([])
  const [form, setForm] = useState({
    service_type_id: '',
    service_date: new Date().toISOString().split('T')[0],
    notes: '',
  })
  const [loading,  setLoading]  = useState(false)
  const [fetching, setFetching] = useState(true)
  const [errors,   setErrors]   = useState({})

  useEffect(() => {
    getServiceTypes()
      .then(res => setServiceTypes(res.data.data))
      .finally(() => setFetching(false))
  }, [])

  const set = (field) => (e) => {
    setForm(f => ({ ...f, [field]: e.target.value }))
    setErrors(e => ({ ...e, [field]: null }))
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    setLoading(true)
    setErrors({})
    try {
      const res = await createSession(form)
      navigate(`/attendance/${res.data.data.id}`)
    } catch (err) {
      if (err.response?.status === 422) {
        if (err.response.data.session_id) {
          // Session already exists — go to it
          navigate(`/attendance/${err.response.data.session_id}`)
        } else {
          setErrors(err.response.data.errors ?? {})
        }
      } else {
        alert('Something went wrong.')
      }
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="max-w-lg mx-auto space-y-6">
      <div className="flex items-center gap-4">
        <button onClick={() => navigate('/attendance')}
                className="p-2 rounded-lg"
                style={{backgroundColor:'white',border:'1px solid var(--color-surface-border)'}}>
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7"/>
          </svg>
        </button>
        <div>
          <h2 className="text-xl font-bold"
              style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
            Take Attendance
          </h2>
          <p className="text-sm" style={{color:'#6b7280'}}>
            Select the service and date to begin
          </p>
        </div>
      </div>

      <form onSubmit={handleSubmit} className="card space-y-4">
        <div>
          <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>
            Service Type *
          </label>
          {fetching ? (
            <div className="input-field" style={{color:'#9ca3af'}}>Loading services...</div>
          ) : (
            <select className="input-field" value={form.service_type_id}
                    onChange={set('service_type_id')} required>
              <option value="">Select service...</option>
              {serviceTypes.map(st => (
                <option key={st.id} value={st.id}>{st.name}</option>
              ))}
            </select>
          )}
          {errors.service_type_id && (
            <p className="text-xs mt-1" style={{color:'#dc2626'}}>{errors.service_type_id[0]}</p>
          )}
        </div>

        <div>
          <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>
            Service Date *
          </label>
          <input type="date" className="input-field" value={form.service_date}
                 onChange={set('service_date')} required/>
          {errors.service_date && (
            <p className="text-xs mt-1" style={{color:'#dc2626'}}>{errors.service_date[0]}</p>
          )}
        </div>

        <div>
          <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>
            Notes (optional)
          </label>
          <textarea className="input-field" value={form.notes}
                    onChange={set('notes')} rows={2}
                    placeholder="e.g. Easter Sunday, Special service..."/>
        </div>

        <div className="flex gap-3 pt-2">
          <button type="button" onClick={() => navigate('/attendance')}
                  className="flex-1 py-2.5 rounded-lg text-sm font-semibold"
                  style={{backgroundColor:'white',
                          border:'1px solid var(--color-surface-border)',color:'#374151'}}>
            Cancel
          </button>
          <button type="submit" disabled={loading} className="flex-1 btn-primary py-2.5">
            {loading ? 'Starting...' : 'Start Taking Attendance →'}
          </button>
        </div>
      </form>
    </div>
  )
}
