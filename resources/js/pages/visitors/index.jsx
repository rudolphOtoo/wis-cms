import React, { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { getVisitors, deleteVisitor, getVisitorStats, convertVisitor } from '../../api/visitors'
import { usePermission } from '../../hooks/usePermission'

const STATUS_COLORS = {
  pending:        { bg: '#fef9c3', text: '#854d0e' },
  contacted:      { bg: '#dbeafe', text: '#1d4ed8' },
  not_interested: { bg: '#f3f4f6', text: '#6b7280' },
  joined:         { bg: '#dcfce7', text: '#15803d' },
}

const STATUS_LABELS = {
  pending: 'Pending', contacted: 'Contacted',
  not_interested: 'Not Interested', joined: 'Joined',
}

export default function VisitorsPage() {
  const navigate = useNavigate()
  const { can }  = usePermission()
  const [visitors,    setVisitors]    = useState([])
  const [stats,       setStats]       = useState(null)
  const [loading,     setLoading]     = useState(true)
  const [search,      setSearch]      = useState('')
  const [statusFilter,setStatus]      = useState('')
  const [page,        setPage]        = useState(1)
  const [meta,        setMeta]        = useState(null)
  const [deleting,    setDeleting]    = useState(null)
  const [converting,  setConverting]  = useState(null) // visitor object being converted

  const fetchData = useCallback(async () => {
    setLoading(true)
    try {
      const [vRes, sRes] = await Promise.all([
        getVisitors({ search, follow_up_status: statusFilter, page, per_page: 15 }),
        getVisitorStats(),
      ])
      setVisitors(vRes.data.data)
      setMeta(vRes.data.meta)
      setStats(sRes.data.data)
    } catch (err) {
      console.error(err)
    } finally {
      setLoading(false)
    }
  }, [search, statusFilter, page])

  useEffect(() => { fetchData() }, [fetchData])
  useEffect(() => {
    const t = setTimeout(() => fetchData(), 400)
    return () => clearTimeout(t)
  }, [search])

  const handleDelete = async (visitor) => {
    if (!confirm(`Delete ${visitor.full_name}?`)) return
    setDeleting(visitor.id)
    try {
      await deleteVisitor(visitor.id)
      fetchData()
    } catch {
      alert('Failed to delete visitor.')
    } finally {
      setDeleting(null)
    }
  }

  return (
    <div className="space-y-6">
      {/* Stats */}
      <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
        {[
          { label: 'Total Visitors',  value: stats?.total      ?? '—' },
          { label: 'This Month',      value: stats?.this_month ?? '—' },
          { label: 'Pending',         value: stats?.pending    ?? '—' },
          { label: 'Contacted',       value: stats?.contacted  ?? '—' },
          { label: 'Joined Church',   value: stats?.joined     ?? '—' },
        ].map(s => (
          <div key={s.label} className="card py-4">
            <div className="text-2xl font-bold" style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
              {s.value}
            </div>
            <div className="text-xs mt-1" style={{color:'#6b7280'}}>{s.label}</div>
          </div>
        ))}
      </div>

      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h2 className="text-xl font-bold" style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
            Visitors & First-Timers
          </h2>
          <p className="text-sm" style={{color:'#6b7280'}}>
            {meta ? `${meta.total} total visitors` : 'Loading...'}
          </p>
        </div>
        {can('create visitors') && (
          <button onClick={() => navigate('/visitors/new')} className="btn-primary gap-2">
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4"/>
            </svg>
            Record Visitor
          </button>
        )}
      </div>

      {/* Filters */}
      <div className="card py-4">
        <div className="flex flex-col sm:flex-row gap-3">
          <div className="flex-1 relative">
            <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4" style={{color:'#9ca3af'}}
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <input type="text" placeholder="Search by name or phone..."
                   className="input-field" style={{paddingLeft:'2.5rem'}}
                   value={search} onChange={e => { setSearch(e.target.value); setPage(1) }}/>
          </div>
          <select className="input-field" style={{width:'auto'}}
                  value={statusFilter} onChange={e => { setStatus(e.target.value); setPage(1) }}>
            <option value="">All Statuses</option>
            <option value="pending">Pending</option>
            <option value="contacted">Contacted</option>
            <option value="not_interested">Not Interested</option>
            <option value="joined">Joined</option>
          </select>
        </div>
      </div>

      {/* Table */}
      <div className="card p-0 overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead>
              <tr style={{borderBottom:'1px solid var(--color-surface-border)',backgroundColor:'#f9fafb'}}>
                {['Name','Phone','Visit Date','How They Heard','Follow-up','Actions'].map(h => (
                  <th key={h} className="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider"
                      style={{color:'#6b7280'}}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr><td colSpan={6} className="text-center py-12" style={{color:'#9ca3af'}}>Loading...</td></tr>
              ) : visitors.length === 0 ? (
                <tr>
                  <td colSpan={6} className="text-center py-12">
                    <div className="text-4xl mb-3">🙏</div>
                    <div className="font-semibold" style={{color:'var(--color-navy)'}}>No visitors found</div>
                    <div className="text-sm mt-1" style={{color:'#9ca3af'}}>
                      {search ? 'Try a different search' : 'Record your first visitor'}
                    </div>
                  </td>
                </tr>
              ) : visitors.map((visitor, i) => {
                const isConverted = Boolean(visitor.converted_member_id)
                return (
                  <tr key={visitor.id}
                      style={{borderBottom:'1px solid var(--color-surface-border)',
                              backgroundColor: i % 2 === 0 ? 'white' : '#fafafa'}}>
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-3">
                        <div className="w-8 h-8 rounded-full flex items-center justify-center
                                        flex-shrink-0 text-sm font-bold text-white"
                             style={{backgroundColor: isConverted ? '#15803d' : '#7c3aed'}}>
                          {visitor.first_name.charAt(0)}{visitor.last_name.charAt(0)}
                        </div>
                        <div>
                          <div className="text-sm font-semibold flex items-center gap-2" style={{color:'#111827'}}>
                            {visitor.full_name}
                            {isConverted && (
                              <span className="text-xs px-1.5 py-0.5 rounded font-medium"
                                    style={{backgroundColor:'#dcfce7',color:'#15803d'}}>
                                ✓ Member
                              </span>
                            )}
                          </div>
                          {visitor.email && <div className="text-xs" style={{color:'#9ca3af'}}>{visitor.email}</div>}
                        </div>
                      </div>
                    </td>
                    <td className="px-4 py-3 text-sm" style={{color:'#374151'}}>{visitor.phone ?? '—'}</td>
                    <td className="px-4 py-3 text-sm" style={{color:'#374151'}}>{visitor.visit_date}</td>
                    <td className="px-4 py-3 text-sm" style={{color:'#374151'}}>{visitor.how_they_heard ?? '—'}</td>
                    <td className="px-4 py-3">
                      <span className="px-2 py-1 rounded-full text-xs font-semibold"
                            style={{
                              backgroundColor: STATUS_COLORS[visitor.follow_up_status]?.bg,
                              color:           STATUS_COLORS[visitor.follow_up_status]?.text,
                            }}>
                        {STATUS_LABELS[visitor.follow_up_status]}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-2">
                        {!isConverted && can('create members') && can('create visitors') && (
                          <button onClick={() => setConverting(visitor)}
                                  className="text-xs px-2 py-1 rounded font-medium"
                                  style={{color:'#15803d',backgroundColor:'rgba(22,163,74,0.1)'}}>
                            Convert
                          </button>
                        )}
                        {can('edit visitors') && (
                          <button onClick={() => navigate(`/visitors/${visitor.id}/edit`)}
                                  className="text-xs px-2 py-1 rounded font-medium"
                                  style={{color:'#d97706',backgroundColor:'rgba(217,119,6,0.08)'}}>
                            Edit
                          </button>
                        )}
                        {can('delete visitors') && (
                          <button onClick={() => handleDelete(visitor)}
                                  disabled={deleting === visitor.id}
                                  className="text-xs px-2 py-1 rounded font-medium"
                                  style={{color:'#dc2626',backgroundColor:'rgba(220,38,38,0.08)'}}>
                            {deleting === visitor.id ? '...' : 'Delete'}
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>

        {meta && meta.last_page > 1 && (
          <div className="px-4 py-3 flex items-center justify-between"
               style={{borderTop:'1px solid var(--color-surface-border)'}}>
            <span className="text-sm" style={{color:'#6b7280'}}>
              Page {meta.current_page} of {meta.last_page}
            </span>
            <div className="flex gap-2">
              <button disabled={page === 1} onClick={() => setPage(p => p - 1)}
                      className="px-3 py-1 text-sm rounded border disabled:opacity-50"
                      style={{borderColor:'var(--color-surface-border)'}}>
                Previous
              </button>
              <button disabled={page === meta.last_page} onClick={() => setPage(p => p + 1)}
                      className="px-3 py-1 text-sm rounded border disabled:opacity-50"
                      style={{borderColor:'var(--color-surface-border)'}}>
                Next
              </button>
            </div>
          </div>
        )}
      </div>

      {/* Convert Modal */}
      {converting && (
        <ConvertModal
          visitor={converting}
          onClose={() => setConverting(null)}
          onSuccess={() => { setConverting(null); fetchData() }}
        />
      )}
    </div>
  )
}

/**
 * Conversion modal — collects the few fields a Member has that a Visitor doesn't.
 */
function ConvertModal({ visitor, onClose, onSuccess }) {
  const [form, setForm] = useState({
    gender: '', date_of_birth: '', occupation: '',
    marital_status: '', is_baptised: false, baptism_date: '', notes: '',
  })
  const [errors,  setErrors]  = useState({})
  const [loading, setLoading] = useState(false)

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
      const res = await convertVisitor(visitor.id, form)
      alert(res.data.message)
      onSuccess()
    } catch (err) {
      if (err.response?.status === 422) {
        setErrors(err.response.data.errors ?? {})
        if (err.response.data.message) alert(err.response.data.message)
      } else {
        alert('Conversion failed. Please try again.')
      }
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="fixed inset-0 flex items-center justify-center z-50 p-4"
         style={{backgroundColor:'rgba(0,0,0,0.5)'}}>
      <div className="bg-white rounded-2xl max-w-lg w-full max-h-[90vh] overflow-y-auto shadow-2xl">

        <div className="px-6 py-4 flex items-center justify-between"
             style={{borderBottom:'1px solid var(--color-surface-border)'}}>
          <div>
            <h3 className="text-lg font-bold" style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
              Convert to Member
            </h3>
            <p className="text-xs mt-0.5" style={{color:'#6b7280'}}>
              {visitor.full_name} → New church member
            </p>
          </div>
          <button onClick={onClose} className="p-1 rounded hover:bg-gray-100">
            <svg className="w-5 h-5" style={{color:'#6b7280'}}
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                    d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>

        <form onSubmit={handleSubmit} className="p-6 space-y-4">

          <div className="rounded-lg p-3" style={{backgroundColor:'#f0fdf4',border:'1px solid #bbf7d0'}}>
            <p className="text-sm" style={{color:'#15803d'}}>
              <strong>{visitor.full_name}</strong>'s visitor details (name, phone, email, address)
              will be carried over. Add the extra information below to complete their membership.
            </p>
          </div>

          <div>
            <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>
              Gender *
            </label>
            <select className="input-field" value={form.gender} onChange={set('gender')} required>
              <option value="">Select gender</option>
              <option value="male">Male</option>
              <option value="female">Female</option>
            </select>
            {errors.gender && <p className="text-xs mt-1" style={{color:'#dc2626'}}>{errors.gender[0]}</p>}
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>
                Date of Birth
              </label>
              <input type="date" className="input-field" value={form.date_of_birth} onChange={set('date_of_birth')}/>
            </div>
            <div>
              <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>
                Marital Status
              </label>
              <select className="input-field" value={form.marital_status} onChange={set('marital_status')}>
                <option value="">Select status</option>
                <option value="single">Single</option>
                <option value="married">Married</option>
                <option value="widowed">Widowed</option>
                <option value="divorced">Divorced</option>
              </select>
            </div>
          </div>

          <div>
            <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>
              Occupation
            </label>
            <input type="text" className="input-field" value={form.occupation}
                   onChange={set('occupation')} placeholder="e.g. Teacher, Engineer"/>
          </div>

          <div className="flex items-center gap-3 pt-2">
            <input type="checkbox" id="modal_is_baptised" checked={form.is_baptised}
                   onChange={set('is_baptised')}
                   className="w-4 h-4" style={{accentColor:'var(--color-navy)'}}/>
            <label htmlFor="modal_is_baptised" className="text-sm font-medium" style={{color:'#374151'}}>
              Has been baptised
            </label>
          </div>
          {form.is_baptised && (
            <div>
              <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>
                Baptism Date
              </label>
              <input type="date" className="input-field" value={form.baptism_date} onChange={set('baptism_date')}/>
            </div>
          )}

          <div className="flex items-center justify-end gap-3 pt-4"
               style={{borderTop:'1px solid var(--color-surface-border)'}}>
            <button type="button" onClick={onClose}
                    className="px-5 py-2 rounded-lg text-sm font-semibold"
                    style={{backgroundColor:'white',border:'1px solid var(--color-surface-border)',color:'#374151'}}>
              Cancel
            </button>
            <button type="submit" disabled={loading} className="btn-primary px-6 py-2">
              {loading ? 'Converting...' : 'Convert to Member →'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
