import React, { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { getVisitors, deleteVisitor, getVisitorStats, convertVisitor } from '../../api/visitors'
import { usePermission } from '../../hooks/usePermission'
import { useDebounce } from '../../hooks/useDebounce'

const STATUS_COLORS = {
  pending:        { bg: '#fef9c3', text: '#854d0e' },
  contacted:      { bg: '#dbeafe', text: '#1d4ed8' },
  not_interested: { bg: '#edeef1', text: '#6b7280' },
  joined:         { bg: '#dcfce7', text: '#15803d' },
}
const STATUS_LABELS = {
  pending: 'Pending', contacted: 'Contacted',
  not_interested: 'Not Interested', joined: 'Joined',
}

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
  users:    <><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/></>,
  calendar: <><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></>,
  pending:  <><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></>,
  call:     <><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></>,
  verified: <><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></>,
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
  const [converting,  setConverting]  = useState(null)

  const debouncedSearch = useDebounce(search, 400)

  const fetchData = useCallback(async () => {
    setLoading(true)
    try {
      const [vRes, sRes] = await Promise.all([
        getVisitors({ search: debouncedSearch, follow_up_status: statusFilter, page, per_page: 15 }),
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
  }, [debouncedSearch, statusFilter, page])

  useEffect(() => { fetchData() }, [fetchData])

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

  const statCards = [
    { label:'Total Visitors', value: stats?.total      ?? '—', icon: ICONS.users,    color:'var(--color-navy)' },
    { label:'This Month',     value: stats?.this_month ?? '—', icon: ICONS.calendar, color:'var(--color-navy)' },
    { label:'Pending',        value: stats?.pending    ?? '—', icon: ICONS.pending,  color:'#854d0e' },
    { label:'Contacted',      value: stats?.contacted  ?? '—', icon: ICONS.call,     color:'#1d4ed8' },
    { label:'Joined Church',  value: stats?.joined     ?? '—', icon: ICONS.verified, color:'#15803d' },
  ]

  return (
    <div className="space-y-6" style={{maxWidth:'1440px'}}>

      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
        <div>
          <h2 className="font-bold" style={{fontFamily:'var(--font-display)',fontSize:'32px',lineHeight:'40px',color:'var(--color-navy)'}}>
            Visitor Management
          </h2>
          <p style={{color:'#44474f'}}>Oversee stewardship of newcomers and first-time attendees.</p>
        </div>
        {can('create visitors') && (
          <button onClick={() => navigate('/visitors/new')} className="btn-primary gap-2" style={{padding:'12px 24px'}}>
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2M9 7a4 4 0 108 0 4 4 0 00-8 0M19 8v6M22 11h-6"/>
            </svg>
            Record Visitor
          </button>
        )}
      </div>

      {/* Stat cards */}
      <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
        {statCards.map(s => (
          <div key={s.label} style={{...cardBase, padding:'24px'}}>
            <p className="uppercase tracking-wider mb-1" style={{fontSize:'12px',fontWeight:700,color:'#747780'}}>{s.label}</p>
            <div className="flex items-center justify-between">
              <span style={{fontFamily:'var(--font-display)',fontSize:'24px',fontWeight:600,color:s.color}}>{s.value}</span>
              <span style={{color: s.color, opacity:0.6}}><Icon d={s.icon} size={20} /></span>
            </div>
          </div>
        ))}
      </div>

      {/* Filters */}
      <div style={{...cardBase, padding:'16px 24px'}}>
        <div className="flex flex-col sm:flex-row gap-3">
          <div className="flex-1 relative">
            <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4" style={{color:'#747780'}}
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <input type="text" placeholder="Filter by name or phone..."
                   className="input-field" style={{paddingLeft:'2.5rem'}}
                   value={search} onChange={e => { setSearch(e.target.value); setPage(1) }}/>
          </div>
          <select className="input-field" style={{width:'auto'}}
                  value={statusFilter} onChange={e => { setStatus(e.target.value); setPage(1) }}>
            <option value="">Status: All</option>
            <option value="pending">Pending</option>
            <option value="contacted">Contacted</option>
            <option value="not_interested">Not Interested</option>
            <option value="joined">Joined</option>
          </select>
        </div>
      </div>

      {/* Table */}
      <div style={{...cardBase, overflow:'hidden'}}>
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr style={{backgroundColor:'#f2f3f6'}}>
                {[['Name'],['Phone'],['Visit Date'],['How They Heard'],['Status'],['Actions','right']].map(([h, align]) => (
                  <th key={h} className="uppercase" style={{padding:'16px 24px',fontSize:'12px',fontWeight:700,color:'#747780',textAlign:align||'left'}}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr><td colSpan={6} className="text-center" style={{padding:'48px',color:'#9ca3af'}}>Loading...</td></tr>
              ) : visitors.length === 0 ? (
                <tr><td colSpan={6} className="text-center" style={{padding:'48px'}}>
                  <div className="text-4xl mb-3">🙏</div>
                  <div className="font-semibold" style={{color:'var(--color-navy)'}}>No visitors found</div>
                  <div className="text-sm mt-1" style={{color:'#9ca3af'}}>{search ? 'Try a different search' : 'Record your first visitor'}</div>
                </td></tr>
              ) : visitors.map((visitor) => {
                const isConverted = Boolean(visitor.converted_member_id)
                const sc = STATUS_COLORS[visitor.follow_up_status] ?? STATUS_COLORS.pending
                return (
                  <tr key={visitor.id} className="transition-colors" style={{borderTop:'1px solid var(--color-surface-border)'}}
                      onMouseEnter={e => e.currentTarget.style.backgroundColor='#f8f9fc'}
                      onMouseLeave={e => e.currentTarget.style.backgroundColor='transparent'}>
                    <td style={{padding:'16px 24px'}}>
                      <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0 text-sm font-bold text-white"
                             style={{backgroundColor: isConverted ? '#15803d' : '#7c3aed'}}>
                          {visitor.first_name.charAt(0)}{visitor.last_name.charAt(0)}
                        </div>
                        <div>
                          <div className="font-bold flex items-center gap-2" style={{color:'var(--color-navy)'}}>
                            {visitor.full_name}
                            {isConverted && (
                              <span className="uppercase flex items-center gap-0.5" style={{padding:'2px 6px',borderRadius:'4px',fontSize:'10px',fontWeight:700,backgroundColor:'#dcfce7',color:'#15803d'}}>
                                ✓ Member
                              </span>
                            )}
                          </div>
                          {visitor.email && <div style={{fontSize:'12px',color:'#747780'}}>{visitor.email}</div>}
                        </div>
                      </div>
                    </td>
                    <td style={{padding:'16px 24px',fontSize:'15px',color:'#44474f'}}>{visitor.phone ?? '—'}</td>
                    <td style={{padding:'16px 24px',fontSize:'15px',color:'#44474f'}}>{visitor.visit_date}</td>
                    <td style={{padding:'16px 24px',fontSize:'15px',color:'#44474f'}}>{visitor.how_they_heard ?? '—'}</td>
                    <td style={{padding:'16px 24px'}}>
                      <span className="uppercase" style={{padding:'4px 12px',borderRadius:'9999px',fontSize:'11px',fontWeight:700,backgroundColor:sc.bg,color:sc.text}}>
                        {STATUS_LABELS[visitor.follow_up_status]}
                      </span>
                    </td>
                    <td style={{padding:'16px 24px'}}>
                      <div className="flex justify-end items-center gap-2">
                        {!isConverted && can('create members') && can('create visitors') && (
                          <button onClick={() => setConverting(visitor)}
                                  className="text-white rounded transition-all active:scale-95"
                                  style={{padding:'6px 16px',fontSize:'12px',fontWeight:700,backgroundColor:'#16a34a'}}>
                            Convert
                          </button>
                        )}
                        {can('edit visitors') && (
                          <button onClick={() => navigate(`/visitors/${visitor.id}/edit`)}
                                  className="hover:underline" style={{fontSize:'14px',fontWeight:600,color:'var(--color-navy)'}}>Edit</button>
                        )}
                        {can('delete visitors') && (
                          <button onClick={() => handleDelete(visitor)} disabled={deleting === visitor.id}
                                  className="hover:underline" style={{fontSize:'14px',fontWeight:600,color:'#ba1a1a'}}>
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
          <div className="flex items-center justify-between" style={{padding:'16px 24px',borderTop:'1px solid var(--color-surface-border)'}}>
            <span style={{fontSize:'14px',color:'#747780'}}>Page {meta.current_page} of {meta.last_page} · {meta.total} entries</span>
            <div className="flex items-center gap-2">
              <button disabled={page === 1} onClick={() => setPage(p => p - 1)}
                      className="w-10 h-10 rounded-lg flex items-center justify-center disabled:opacity-40"
                      style={{border:'1px solid var(--color-surface-border)',color:'var(--color-navy)'}}>
                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7"/></svg>
              </button>
              <span className="w-10 h-10 rounded-lg flex items-center justify-center font-bold text-white" style={{backgroundColor:'var(--color-navy)'}}>{meta.current_page}</span>
              <button disabled={page === meta.last_page} onClick={() => setPage(p => p + 1)}
                      className="w-10 h-10 rounded-lg flex items-center justify-center disabled:opacity-40"
                      style={{border:'1px solid var(--color-surface-border)',color:'var(--color-navy)'}}>
                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7"/></svg>
              </button>
            </div>
          </div>
        )}
      </div>

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
    <div className="fixed inset-0 flex items-center justify-center z-50 p-4" style={{backgroundColor:'rgba(0,0,0,0.5)'}}>
      <div className="bg-white rounded-2xl max-w-lg w-full max-h-[90vh] overflow-y-auto shadow-2xl">
        <div className="px-6 py-4 flex items-center justify-between" style={{borderBottom:'1px solid var(--color-surface-border)'}}>
          <div>
            <h3 className="text-lg font-bold" style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>Convert to Member</h3>
            <p className="text-xs mt-0.5" style={{color:'#6b7280'}}>{visitor.full_name} → New church member</p>
          </div>
          <button onClick={onClose} className="p-1 rounded hover:bg-gray-100">
            <svg className="w-5 h-5" style={{color:'#6b7280'}} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12"/></svg>
          </button>
        </div>
        <form onSubmit={handleSubmit} className="p-6 space-y-4">
          <div className="rounded-lg p-3" style={{backgroundColor:'#f0fdf4',border:'1px solid #bbf7d0'}}>
            <p className="text-sm" style={{color:'#15803d'}}>
              <strong>{visitor.full_name}</strong>'s visitor details (name, phone, email, address) will be carried over. Add the extra information below to complete their membership.
            </p>
          </div>
          <div>
            <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>Gender *</label>
            <select className="input-field" value={form.gender} onChange={set('gender')} required>
              <option value="">Select gender</option>
              <option value="male">Male</option>
              <option value="female">Female</option>
            </select>
            {errors.gender && <p className="text-xs mt-1" style={{color:'#dc2626'}}>{errors.gender[0]}</p>}
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>Date of Birth</label>
              <input type="date" className="input-field" value={form.date_of_birth} onChange={set('date_of_birth')}/>
            </div>
            <div>
              <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>Marital Status</label>
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
            <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>Occupation</label>
            <input type="text" className="input-field" value={form.occupation} onChange={set('occupation')} placeholder="e.g. Teacher, Engineer"/>
          </div>
          <div className="flex items-center gap-3 pt-2">
            <input type="checkbox" id="modal_is_baptised" checked={form.is_baptised} onChange={set('is_baptised')} className="w-4 h-4" style={{accentColor:'var(--color-navy)'}}/>
            <label htmlFor="modal_is_baptised" className="text-sm font-medium" style={{color:'#374151'}}>Has been baptised</label>
          </div>
          {form.is_baptised && (
            <div>
              <label className="block text-sm font-semibold mb-1.5" style={{color:'#374151'}}>Baptism Date</label>
              <input type="date" className="input-field" value={form.baptism_date} onChange={set('baptism_date')}/>
            </div>
          )}
          <div className="flex items-center justify-end gap-3 pt-4" style={{borderTop:'1px solid var(--color-surface-border)'}}>
            <button type="button" onClick={onClose} className="px-5 py-2 rounded-lg text-sm font-semibold"
                    style={{backgroundColor:'white',border:'1px solid var(--color-surface-border)',color:'#374151'}}>Cancel</button>
            <button type="submit" disabled={loading} className="btn-primary px-6 py-2">
              {loading ? 'Converting...' : 'Convert to Member →'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
