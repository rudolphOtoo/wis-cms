import React, { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { getChildren, deleteChild, getChildrenStats } from '../../api/children'
import { usePermission } from '../../hooks/usePermission'

export default function ChildrenPage() {
  const navigate = useNavigate()
  const { can }  = usePermission()
  const [children,    setChildren]    = useState([])
  const [stats,       setStats]       = useState(null)
  const [loading,     setLoading]     = useState(true)
  const [search,      setSearch]      = useState('')
  const [classFilter, setClassFilter] = useState('')
  const [page,        setPage]        = useState(1)
  const [meta,        setMeta]        = useState(null)
  const [deleting,    setDeleting]    = useState(null)

  const fetchData = useCallback(async () => {
    setLoading(true)
    try {
      const [cRes, sRes] = await Promise.all([
        getChildren({ search, class_group: classFilter, page, per_page: 15 }),
        getChildrenStats(),
      ])
      setChildren(cRes.data.data)
      setMeta(cRes.data.meta)
      setStats(sRes.data.data)
    } catch (err) {
      console.error(err)
    } finally {
      setLoading(false)
    }
  }, [search, classFilter, page])

  useEffect(() => { fetchData() }, [fetchData])
  useEffect(() => {
    const t = setTimeout(() => fetchData(), 400)
    return () => clearTimeout(t)
  }, [search])

  const handleDelete = async (child) => {
    if (!confirm(`Remove ${child.full_name} from the children's register?`)) return
    setDeleting(child.id)
    try {
      await deleteChild(child.id)
      fetchData()
    } catch {
      alert('Failed to remove child.')
    } finally {
      setDeleting(null)
    }
  }

  const classOptions = stats?.by_class ? Object.keys(stats.by_class) : []

  return (
    <div className="space-y-6">

      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        {[
          { label: 'Total Children', value: stats?.total  ?? '—' },
          { label: 'Active',         value: stats?.active ?? '—' },
          { label: 'Boys',           value: stats?.male   ?? '—' },
          { label: 'Girls',          value: stats?.female ?? '—' },
        ].map(s => (
          <div key={s.label} className="card py-4">
            <div className="text-2xl font-bold"
                 style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
              {s.value}
            </div>
            <div className="text-xs mt-1" style={{color:'#6b7280'}}>{s.label}</div>
          </div>
        ))}
      </div>

      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h2 className="text-xl font-bold"
              style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
            Children's Register
          </h2>
          <p className="text-sm" style={{color:'#6b7280'}}>
            {meta ? `${meta.total} children registered` : 'Loading...'}
          </p>
        </div>
        {can('create children') && (
          <button onClick={() => navigate('/children/new')} className="btn-primary gap-2">
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4"/>
            </svg>
            Add Child
          </button>
        )}
      </div>

      <div className="card py-4">
        <div className="flex flex-col sm:flex-row gap-3">
          <div className="flex-1 relative">
            <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4"
                 style={{color:'#9ca3af'}} fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <input type="text" placeholder="Search by name or class..."
                   className="input-field" style={{paddingLeft:'2.5rem'}}
                   value={search} onChange={e => { setSearch(e.target.value); setPage(1) }}/>
          </div>
          {classOptions.length > 0 && (
            <select className="input-field" style={{width:'auto'}}
                    value={classFilter} onChange={e => { setClassFilter(e.target.value); setPage(1) }}>
              <option value="">All Classes</option>
              {classOptions.map(c => (
                <option key={c} value={c}>{c}</option>
              ))}
            </select>
          )}
        </div>
      </div>

      <div className="card p-0 overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead>
              <tr style={{borderBottom:'1px solid var(--color-surface-border)',backgroundColor:'#f9fafb'}}>
                {['Name','Age','Gender','Class','Guardian','Status','Actions'].map(h => (
                  <th key={h} className="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider"
                      style={{color:'#6b7280'}}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr><td colSpan={7} className="text-center py-12" style={{color:'#9ca3af'}}>Loading...</td></tr>
              ) : children.length === 0 ? (
                <tr>
                  <td colSpan={7} className="text-center py-12">
                    <div className="text-4xl mb-3">👶</div>
                    <div className="font-semibold" style={{color:'var(--color-navy)'}}>No children registered</div>
                    <div className="text-sm mt-1" style={{color:'#9ca3af'}}>
                      {search ? 'Try a different search' : 'Add the first child to get started'}
                    </div>
                  </td>
                </tr>
              ) : children.map((child, i) => (
                <tr key={child.id}
                    style={{borderBottom:'1px solid var(--color-surface-border)',
                            backgroundColor: i % 2 === 0 ? 'white' : '#fafafa'}}>
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-3">
                      <div className="w-8 h-8 rounded-full flex items-center justify-center
                                      flex-shrink-0 text-sm font-bold text-white"
                           style={{backgroundColor: child.gender === 'male' ? '#3b82f6' : '#ec4899'}}>
                        {child.first_name.charAt(0)}{child.last_name.charAt(0)}
                      </div>
                      <div className="text-sm font-semibold" style={{color:'#111827'}}>
                        {child.full_name}
                      </div>
                    </div>
                  </td>
                  <td className="px-4 py-3 text-sm" style={{color:'#374151'}}>
                    {child.age != null ? `${child.age} yrs` : '—'}
                  </td>
                  <td className="px-4 py-3 text-sm capitalize" style={{color:'#374151'}}>
                    {child.gender}
                  </td>
                  <td className="px-4 py-3 text-sm" style={{color:'#374151'}}>
                    {child.class_group ?? '—'}
                  </td>
                  <td className="px-4 py-3 text-sm" style={{color:'#374151'}}>
                    {child.guardian ? (
                      <div>
                        <div className="font-semibold" style={{color:'#111827'}}>{child.guardian.name}</div>
                        <div className="text-xs" style={{color:'#9ca3af'}}>{child.guardian.phone ?? ''}</div>
                      </div>
                    ) : <em style={{color:'#9ca3af'}}>none</em>}
                  </td>
                  <td className="px-4 py-3">
                    <span className="px-2 py-1 rounded-full text-xs font-semibold"
                          style={{
                            backgroundColor: child.is_active ? '#dcfce7' : '#f3f4f6',
                            color:           child.is_active ? '#15803d' : '#6b7280',
                          }}>
                      {child.is_active ? 'Active' : 'Inactive'}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-2">
                      {can('edit children') && (
                        <button onClick={() => navigate(`/children/${child.id}/edit`)}
                                className="text-xs px-2 py-1 rounded font-medium"
                                style={{color:'#d97706',backgroundColor:'rgba(217,119,6,0.08)'}}>
                          Edit
                        </button>
                      )}
                      {can('delete children') && (
                        <button onClick={() => handleDelete(child)}
                                disabled={deleting === child.id}
                                className="text-xs px-2 py-1 rounded font-medium"
                                style={{color:'#dc2626',backgroundColor:'rgba(220,38,38,0.08)'}}>
                          {deleting === child.id ? '...' : 'Remove'}
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
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
    </div>
  )
}
