import { useState, useEffect, useCallback } from 'react'
import { useConfirm } from '../../hooks/useConfirm'
import { toast } from 'sonner'
import { useNavigate } from 'react-router-dom'
import { getChildren, deleteChild, getChildrenStats } from '../../api/children'
import { usePermission } from '../../hooks/usePermission'

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
  groups:   <><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/></>,
  check:    <><circle cx="12" cy="12" r="10"/><path d="M9 12l2 2 4-4"/></>,
  boy:      <><circle cx="12" cy="5" r="3"/><path d="M12 8v8m-4 4l4-4 4 4"/></>,
  girl:     <><circle cx="12" cy="5" r="3"/><path d="M9 21l3-9 3 9M8 14h8"/></>,
}

export default function ChildrenPage() {
  const navigate = useNavigate()
  const { can }  = usePermission()
  const { confirm, dialog } = useConfirm()
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
    if (!(await confirm(`Remove ${child.full_name} from the children's register?`))) return
    setDeleting(child.id)
    try {
      await deleteChild(child.id)
      fetchData()
    } catch {
      toast.error('Failed to remove child.')
    } finally {
      setDeleting(null)
    }
  }

  const classOptions = stats?.by_class ? Object.keys(stats.by_class) : []

  const statCards = [
    { label:'Total Children', value: stats?.total  ?? '—', icon: ICONS.groups, bg:'rgba(0,36,82,0.05)',  color:'var(--color-navy)' },
    { label:'Active',         value: stats?.active ?? '—', icon: ICONS.check,  bg:'#dcfce7',              color:'#16a34a' },
    { label:'Boys',           value: stats?.male   ?? '—', icon: ICONS.boy,    bg:'#dbeafe',              color:'#2563eb' },
    { label:'Girls',          value: stats?.female ?? '—', icon: ICONS.girl,   bg:'#fce7f3',              color:'#db2777' },
  ]

  return (
    <div className="space-y-6" style={{maxWidth:'1440px'}}>

      {/* Header */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <h1 className="font-bold" style={{fontFamily:'var(--font-display)',fontSize:'32px',lineHeight:'40px',color:'var(--color-navy)'}}>
            Children's Register
          </h1>
          <p style={{color:'#44474f'}}>Managing the future of the Methodist heritage.</p>
        </div>
        {can('create children') && (
          <button onClick={() => navigate('/children/new')} className="btn-primary gap-2" style={{padding:'12px 24px'}}>
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2M9 7a4 4 0 108 0 4 4 0 00-8 0M19 8v6M22 11h-6"/>
            </svg>
            Add Child
          </button>
        )}
      </div>

      {/* Stat cards */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-6">
        {statCards.map(s => (
          <div key={s.label} style={{...cardBase, padding:'24px'}}>
            <div className="rounded-lg flex items-center justify-center mb-3"
                 style={{width:'40px',height:'40px',backgroundColor:s.bg,color:s.color}}>
              <Icon d={s.icon} size={20} />
            </div>
            <p style={{fontSize:'14px',fontWeight:600,color:'#747780'}}>{s.label}</p>
            <p style={{fontFamily:'var(--font-display)',fontSize:'24px',fontWeight:600,color:'var(--color-navy)'}}>{s.value}</p>
          </div>
        ))}
      </div>

      {/* Filters */}
      <div style={{...cardBase, padding:'16px 24px'}}>
        <div className="flex flex-col md:flex-row gap-3">
          <div className="flex-1 relative">
            <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4" style={{color:'#747780'}}
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <input type="text" placeholder="Search by child name or class..."
                   className="input-field" style={{paddingLeft:'2.5rem'}}
                   value={search} onChange={e => { setSearch(e.target.value); setPage(1) }}/>
          </div>
          {classOptions.length > 0 && (
            <select className="input-field" style={{width:'auto'}}
                    value={classFilter} onChange={e => { setClassFilter(e.target.value); setPage(1) }}>
              <option value="">All Classes</option>
              {classOptions.map(c => <option key={c} value={c}>{c}</option>)}
            </select>
          )}
        </div>
      </div>

      {/* Table */}
      <div style={{...cardBase, overflow:'hidden'}}>
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr style={{backgroundColor:'#f2f3f6'}}>
                {[['Name'],['Age','center'],['Gender'],['Class'],['Guardian'],['Status'],['Actions','right']].map(([h, align]) => (
                  <th key={h} className="uppercase tracking-wider" style={{padding:'16px 24px',fontSize:'12px',fontWeight:700,color:'#747780',textAlign:align||'left'}}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr><td colSpan={7} className="text-center" style={{padding:'48px',color:'#9ca3af'}}>Loading...</td></tr>
              ) : children.length === 0 ? (
                <tr><td colSpan={7} className="text-center" style={{padding:'48px'}}>
                  <div className="text-4xl mb-3">👶</div>
                  <div className="font-semibold" style={{color:'var(--color-navy)'}}>No children registered</div>
                  <div className="text-sm mt-1" style={{color:'#9ca3af'}}>{search ? 'Try a different search' : 'Add the first child to get started'}</div>
                </td></tr>
              ) : children.map((child) => {
                const isBoy = child.gender === 'male'
                return (
                  <tr key={child.id} className="transition-colors" style={{borderTop:'1px solid var(--color-surface-border)'}}
                      onMouseEnter={e => e.currentTarget.style.backgroundColor='#f8f9fc'}
                      onMouseLeave={e => e.currentTarget.style.backgroundColor='transparent'}>
                    <td style={{padding:'16px 24px'}}>
                      <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0 font-bold"
                             style={{backgroundColor: isBoy ? '#dbeafe' : '#fce7f3', color: isBoy ? '#1d4ed8' : '#be185d',
                                     border:'2px solid white', boxShadow:'0 1px 3px rgba(0,0,0,0.1)'}}>
                          {child.first_name.charAt(0)}{child.last_name.charAt(0)}
                        </div>
                        <p className="font-bold" style={{color:'var(--color-navy)'}}>{child.full_name}</p>
                      </div>
                    </td>
                    <td style={{padding:'16px 24px',fontSize:'15px',color:'#44474f',textAlign:'center'}}>
                      {child.age != null ? `${child.age} yrs` : '—'}
                    </td>
                    <td style={{padding:'16px 24px'}}>
                      <span className="flex items-center gap-1" style={{fontSize:'14px',fontWeight:600,color: isBoy ? '#2563eb' : '#db2777'}}>
                        <Icon d={isBoy ? ICONS.boy : ICONS.girl} size={18} />
                        <span className="capitalize">{child.gender}</span>
                      </span>
                    </td>
                    <td style={{padding:'16px 24px',fontSize:'15px',color:'#191c1e'}}>{child.class_group ?? '—'}</td>
                    <td style={{padding:'16px 24px'}}>
                      {child.guardian ? (
                        <div>
                          <p style={{fontSize:'14px',fontWeight:600,color:'#191c1e'}}>{child.guardian.name}</p>
                          <p style={{fontSize:'12px',color:'#747780'}}>{child.guardian.phone ?? ''}</p>
                        </div>
                      ) : <em style={{color:'#9ca3af'}}>none</em>}
                    </td>
                    <td style={{padding:'16px 24px'}}>
                      <span style={{padding:'4px 12px',borderRadius:'9999px',fontSize:'12px',fontWeight:700,
                              backgroundColor: child.is_active ? '#dcfce7' : '#fef3c7', color: child.is_active ? '#15803d' : '#92400e'}}>
                        {child.is_active ? 'Active' : 'Inactive'}
                      </span>
                    </td>
                    <td style={{padding:'16px 24px'}}>
                      <div className="flex justify-end gap-3">
                        {can('view children') && (
                          <button onClick={() => navigate(`/children/${child.id}`)}
                                  className="hover:underline" style={{fontSize:'14px',fontWeight:600,color:'var(--color-navy)'}}>View</button>
                        )}
                        {can('edit children') && (
                          <button onClick={() => navigate(`/children/${child.id}/edit`)}
                                  className="hover:underline" style={{fontSize:'14px',fontWeight:600,color:'var(--color-navy)'}}>Edit</button>
                        )}
                        {can('delete children') && (
                          <button onClick={() => handleDelete(child)} disabled={deleting === child.id}
                                  className="hover:underline" style={{fontSize:'14px',fontWeight:600,color:'#ba1a1a'}}>
                            {deleting === child.id ? '...' : 'Remove'}
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
          <div className="flex items-center justify-between" style={{padding:'16px 24px',backgroundColor:'#f8f9fc',borderTop:'1px solid var(--color-surface-border)'}}>
            <span style={{fontSize:'14px',color:'#747780'}}>Page {meta.current_page} of {meta.last_page} · {meta.total} children</span>
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
      {dialog}
    </div>
  )
}
