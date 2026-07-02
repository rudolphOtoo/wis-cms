import React, { useState, useEffect, cloneElement, isValidElement } from 'react'
import { toast } from 'sonner'
import { useNavigate, useParams } from 'react-router-dom'
import { createCell, updateCell, getCell } from '../../api/cells'
import { getUsers } from '../../api/users'

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

export default function CellForm() {
  const navigate = useNavigate()
  const { id }   = useParams()
  const isEdit   = Boolean(id)

  const [form, setForm] = useState({
    name: '', description: '', leader_user_id: '', is_active: true,
  })
  const [leaders,  setLeaders]  = useState([])
  const [errors,   setErrors]   = useState({})
  const [loading,  setLoading]  = useState(false)
  const [fetching, setFetching] = useState(isEdit)

  // Cell leader can be any user (no dedicated cell_leader role for now).
  useEffect(() => {
    const controller = new AbortController()
    getUsers({ per_page: 100 }, controller.signal)
      .then(res => setLeaders(res.data.data ?? []))
      .catch(() => setLeaders([]))
    return () => controller.abort()
  }, [])

  useEffect(() => {
    if (!isEdit) return
    const controller = new AbortController()
    let mounted = true
    setFetching(true)
    getCell(id, controller.signal)
      .then(res => {
        if (!mounted) return
        const c = res.data.data
        setForm({
          name:           c.name           ?? '',
          description:    c.description    ?? '',
          leader_user_id: c.leader?.id     ?? '',
          is_active:      c.is_active      ?? true,
        })
      })
      .catch(() => { if (mounted) navigate('/cells') })
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
      const payload = { ...form, leader_user_id: form.leader_user_id || null }
      if (isEdit) { await updateCell(id, payload) }
      else        { await createCell(payload) }
      navigate('/cells')
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
      <svg className="animate-spin w-8 h-8" style={{color:NAVY}}
           fill="none" viewBox="0 0 24 24">
        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
      </svg>
    </div>
  )

  return (
    <div className="max-w-xl mx-auto space-y-6">
      <div className="flex items-center gap-4">
        <button onClick={() => navigate('/cells')}
                 aria-label="Back to cells"
                 className="min-w-[44px] min-h-[44px] flex items-center justify-center p-2 rounded-lg"
                 style={{backgroundColor:'white',border:BORDER}}>
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7"/>
          </svg>
        </button>
        <div>
          <h2 className="text-xl font-bold" style={{fontFamily:FONT_DISPLAY,color:NAVY}}>
            {isEdit ? 'Edit Cell' : 'New Cell'}
          </h2>
          <p className="text-sm" style={{color:'#6b7280'}}>
            {isEdit ? 'Update cell details' : 'Create a new cell, home group, or class'}
          </p>
        </div>
      </div>

      <form onSubmit={handleSubmit} className="space-y-6">
        <div className="card space-y-4">
          <FIELD label="Cell Name *" error={errors.name?.[0]} name="cell_name">
            <input type="text" className="input-field" value={form.name}
                   onChange={set('name')} required placeholder="e.g. Dansoman Cell, Young Adults, Men's Class"/>
          </FIELD>
          <FIELD label="Description" error={errors.description?.[0]} name="cell_description">
            <textarea className="input-field" value={form.description}
                      onChange={set('description')} rows={3}
                      placeholder="e.g. Geographic area, age group, or focus of this cell"/>
          </FIELD>

          <FIELD label="Cell Leader" error={errors.leader_user_id?.[0]} name="cell_leader">
            <select className="input-field" value={form.leader_user_id} onChange={set('leader_user_id')}>
              <option value="">— No leader assigned —</option>
              {leaders.map(u => (
                <option key={u.id} value={u.id}>{u.name} ({u.email})</option>
              ))}
            </select>
            <p className="text-xs mt-1" style={{color:PLACEHOLDER}}>
              The person who leads this cell. (Cell leaders do not yet have a separate login view.)
            </p>
          </FIELD>

          <div className="flex items-center gap-3">
            <input type="checkbox" id="is_active" checked={form.is_active}
                   onChange={set('is_active')} className="w-4 h-4" style={{accentColor:NAVY}}/>
            <label htmlFor="is_active" className="text-sm font-medium" style={{color:'#374151'}}>
              Cell is active
            </label>
          </div>
        </div>

        <div className="flex items-center justify-end gap-3">
          <button type="button" onClick={() => navigate('/cells')}
                  className="px-6 py-2.5 rounded-lg text-sm font-semibold"
                  style={{backgroundColor:'white',border:BORDER,color:'#374151'}}>
            Cancel
          </button>
          <button type="submit" disabled={loading} className="btn-primary px-8 py-2.5">
            {loading ? 'Saving...' : isEdit ? 'Update Cell' : 'Create Cell'}
          </button>
        </div>
      </form>
    </div>
  )
}
