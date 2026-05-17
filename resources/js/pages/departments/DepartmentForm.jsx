import React, { useState, useEffect } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { createDepartment, updateDepartment, getDepartment } from '../../api/departments'

const FIELD = ({ label, error, children }) => (
  <div>
    <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>{label}</label>
    {children}
    {error && <p className="text-xs mt-1" style={{color:'#dc2626'}}>{error}</p>}
  </div>
)

const DEFAULT_DEPARTMENTS = [
  'Youth Ministry', "Women's Fellowship", "Men's Fellowship",
  'Choir', 'Ushers', 'Prayer Team', 'Sunday School', 'Outreach',
]

export default function DepartmentForm() {
  const navigate = useNavigate()
  const { id }   = useParams()
  const isEdit   = Boolean(id)

  const [form, setForm] = useState({
    name: '', description: '', is_active: true,
  })
  const [errors,   setErrors]   = useState({})
  const [loading,  setLoading]  = useState(false)
  const [fetching, setFetching] = useState(isEdit)

  useEffect(() => {
    if (!isEdit) return
    setFetching(true)
    getDepartment(id)
      .then(res => {
        const d = res.data.data
        setForm({
          name:        d.name        ?? '',
          description: d.description ?? '',
          is_active:   d.is_active   ?? true,
        })
      })
      .catch(() => navigate('/departments'))
      .finally(() => setFetching(false))
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
      if (isEdit) { await updateDepartment(id, form) }
      else        { await createDepartment(form) }
      navigate('/departments')
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
    <div className="max-w-xl mx-auto space-y-6">
      <div className="flex items-center gap-4">
        <button onClick={() => navigate('/departments')}
                className="p-2 rounded-lg"
                style={{backgroundColor:'white',border:'1px solid var(--color-surface-border)'}}>
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7"/>
          </svg>
        </button>
        <div>
          <h2 className="text-xl font-bold"
              style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
            {isEdit ? 'Edit Department' : 'New Department'}
          </h2>
          <p className="text-sm" style={{color:'#6b7280'}}>
            {isEdit ? 'Update department details' : 'Create a new church department or ministry group'}
          </p>
        </div>
      </div>

      {/* Quick select for new departments */}
      {!isEdit && (
        <div className="card py-4">
          <p className="text-xs font-semibold uppercase tracking-wider mb-3"
             style={{color:'#6b7280'}}>
            Quick Select Common Departments
          </p>
          <div className="flex flex-wrap gap-2">
            {DEFAULT_DEPARTMENTS.map(name => (
              <button key={name} type="button"
                      onClick={() => setForm(f => ({ ...f, name }))}
                      className="px-3 py-1 rounded-full text-xs font-medium transition-colors"
                      style={{
                        backgroundColor: form.name === name
                          ? 'var(--color-navy)' : 'rgba(27,58,107,0.08)',
                        color: form.name === name ? 'white' : 'var(--color-navy)',
                      }}>
                {name}
              </button>
            ))}
          </div>
        </div>
      )}

      <form onSubmit={handleSubmit} className="space-y-6">
        <div className="card space-y-4">
          <FIELD label="Department Name *" error={errors.name?.[0]}>
            <input type="text" className="input-field" value={form.name}
                   onChange={set('name')} required
                   placeholder="e.g. Youth Ministry"/>
          </FIELD>
          <FIELD label="Description" error={errors.description?.[0]}>
            <textarea className="input-field" value={form.description}
                      onChange={set('description')} rows={3}
                      placeholder="What is this department's purpose and activities?"/>
          </FIELD>
          <div className="flex items-center gap-3">
            <input type="checkbox" id="is_active" checked={form.is_active}
                   onChange={set('is_active')}
                   className="w-4 h-4" style={{accentColor:'var(--color-navy)'}}/>
            <label htmlFor="is_active" className="text-sm font-medium" style={{color:'#374151'}}>
              Department is active
            </label>
          </div>
        </div>

        <div className="flex items-center justify-end gap-3">
          <button type="button" onClick={() => navigate('/departments')}
                  className="px-6 py-2.5 rounded-lg text-sm font-semibold"
                  style={{backgroundColor:'white',
                          border:'1px solid var(--color-surface-border)',color:'#374151'}}>
            Cancel
          </button>
          <button type="submit" disabled={loading} className="btn-primary px-8 py-2.5">
            {loading ? 'Saving...' : isEdit ? 'Update Department' : 'Create Department'}
          </button>
        </div>
      </form>
    </div>
  )
}
