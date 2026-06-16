import React, { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { getMembers, deleteMember, getMemberStats, exportMembers } from '../../api/members'
import { usePermission } from '../../hooks/usePermission'
import { useDebounce } from '../../hooks/useDebounce'

const STATUS_COLORS = {
  active:      { bg: '#dcfce7', text: '#15803d' },
  inactive:    { bg: '#edeef1', text: '#6b7280' },
  transferred: { bg: '#dbeafe', text: '#2563eb' },
  deceased:    { bg: '#fce7f3', text: '#9d174d' },
}

const cardBase = {
  backgroundColor: '#fff',
  border: '1px solid var(--color-surface-border)',
  borderRadius: '16px',
  boxShadow: '0 4px 12px rgba(13,31,60,0.05)',
}

const Icon = ({ d, size = 18 }) => (
  <svg width={size} height={size} fill="none" stroke="currentColor" strokeWidth={1.8}
       viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">{d}</svg>
)
const ICONS = {
  group:  <><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></>,
  check:  <><path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M16 11l2 2 4-4"/></>,
  new:    <><path d="M12 2l2.4 7.4H22l-6 4.6 2.3 7.4-6.3-4.6L5.7 21.4 8 14 2 9.4h7.6z"/></>,
  balance:<><line x1="12" y1="3" x2="12" y2="21"/><path d="M5 7h14M5 7l-3 6a3 3 0 006 0zM19 7l-3 6a3 3 0 006 0z"/></>,
}

export default function MembersPage() {
  const navigate = useNavigate()
  const { can }  = usePermission()
  const [members,    setMembers]    = useState([])
  const [stats,      setStats]      = useState(null)
  const [loading,    setLoading]    = useState(true)
  const [search,     setSearch]     = useState('')
  const [statusFilter, setStatus]   = useState('')
  const [genderFilter, setGender]   = useState('')
  const [page,       setPage]       = useState(1)
  const [meta,       setMeta]       = useState(null)
  const [deleting,   setDeleting]   = useState(null)
  const [exporting,  setExporting]  = useState(false)

  // Debounce search: the settled value is what drives the API call dep array.
  // This replaces the dual-useEffect pattern (one immediate + one debounced)
  // that previously fired two concurrent requests on every keystroke.
  const debouncedSearch = useDebounce(search, 400)

  const handleExport = async () => {
    setExporting(true)
    try {
      const res = await exportMembers({ search, status: statusFilter, gender: genderFilter })
      const url = URL.createObjectURL(new Blob([res.data], { type: 'text/csv' }))
      const a = document.createElement('a')
      a.href = url
      a.download = `members-${new Date().toISOString().split('T')[0]}.csv`
      document.body.appendChild(a)
      a.click()
      a.remove()
      URL.revokeObjectURL(url)
    } catch (err) {
      console.error(err)
      alert('Export failed. Please try again.')
    } finally {
      setExporting(false)
    }
  }

  const fetchMembers = useCallback(async () => {
    setLoading(true)
    try {
      const [membersRes, statsRes] = await Promise.all([
        getMembers({ search: debouncedSearch, status: statusFilter, gender: genderFilter, page, per_page: 15 }),
        getMemberStats(),
      ])
      setMembers(membersRes.data.data)
      setMeta(membersRes.data.meta)
      setStats(statsRes.data.data)
    } catch (err) {
      console.error(err)
    } finally {
      setLoading(false)
    }
  }, [debouncedSearch, statusFilter, genderFilter, page])

  // Single effect: fires when debouncedSearch settles (after 400 ms of silence)
  // or when any other filter / page changes (immediately, because those are not
  // debounced). This replaces the broken dual-effect pattern.
  useEffect(() => { fetchMembers() }, [fetchMembers])

  const handleDelete = async (member) => {
    if (!confirm(`Delete ${member.full_name}? This cannot be undone.`)) return
    setDeleting(member.id)
    try {
      await deleteMember(member.id)
      fetchMembers()
    } catch (err) {
      alert('Failed to delete member.')
    } finally {
      setDeleting(null)
    }
  }

  const total       = stats?.total ?? 0
  const active       = stats?.active ?? 0
  const activePct   = total > 0 ? Math.round((active / total) * 100) : 0
  const totalGender = (stats?.male ?? 0) + (stats?.female ?? 0)
  const malePct     = totalGender > 0 ? Math.round(((stats?.male ?? 0) / totalGender) * 100) : 0
  const femalePct   = 100 - malePct

  const StatIcon = ({ children }) => (
    <span className="flex items-center justify-center rounded-lg"
          style={{color:'var(--color-navy)',backgroundColor:'rgba(27,58,107,0.1)',padding:'6px'}}>
      {children}
    </span>
  )

  return (
    <div className="space-y-6" style={{maxWidth:'1440px'}}>

      {/* Page header */}
      <div className="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
        <div>
          <h2 className="font-bold" style={{fontFamily:'var(--font-display)',fontSize:'32px',lineHeight:'40px',color:'var(--color-navy)'}}>
            Members
          </h2>
          <p style={{color:'#44474f'}}>{meta ? `${meta.total} Total Registered Members` : 'Loading...'}</p>
        </div>
        <div className="flex items-center gap-3">
          {can('export members') && (
            <button onClick={handleExport} disabled={exporting}
                    className="gap-2 inline-flex items-center rounded-lg font-semibold"
                    style={{padding:'10px 20px',backgroundColor:'white',border:'1px solid var(--color-surface-border)',color:'var(--color-navy)',fontSize:'14px',opacity: exporting ? 0.6 : 1}}>
              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1M12 12V3m0 9l-4-4m4 4l4-4"/>
              </svg>
              {exporting ? 'Exporting...' : 'Export CSV'}
            </button>
          )}
          {can('create members') && (
            <button onClick={() => navigate('/members/new')} className="btn-primary gap-2" style={{padding:'10px 24px'}}>
              Add Member
              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4"/>
              </svg>
            </button>
          )}
        </div>
      </div>

      {/* Stat cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <div style={{...cardBase, padding:'24px'}}>
          <div className="flex justify-between items-start mb-2">
            <p style={{fontSize:'14px',fontWeight:600,color:'#747780'}}>Total Members</p>
            <StatIcon><Icon d={ICONS.group} /></StatIcon>
          </div>
          <p style={{fontFamily:'var(--font-display)',fontSize:'24px',fontWeight:600,color:'var(--color-navy)'}}>{total}</p>
        </div>

        <div style={{...cardBase, padding:'24px'}}>
          <div className="flex justify-between items-start mb-2">
            <p style={{fontSize:'14px',fontWeight:600,color:'#747780'}}>Active</p>
            <StatIcon><Icon d={ICONS.check} /></StatIcon>
          </div>
          <p style={{fontFamily:'var(--font-display)',fontSize:'24px',fontWeight:600,color:'var(--color-navy)'}}>{active}</p>
          <div className="w-full rounded-full mt-2" style={{height:'6px',backgroundColor:'#e7e8eb'}}>
            <div className="rounded-full" style={{height:'6px',width:`${activePct}%`,backgroundColor:'var(--color-navy)'}}/>
          </div>
        </div>

        <div style={{...cardBase, padding:'24px'}}>
          <div className="flex justify-between items-start mb-2">
            <p style={{fontSize:'14px',fontWeight:600,color:'#747780'}}>New This Month</p>
            <StatIcon><Icon d={ICONS.new} /></StatIcon>
          </div>
          <p style={{fontFamily:'var(--font-display)',fontSize:'24px',fontWeight:600,color:'var(--color-navy)'}}>{stats?.new_this_month ?? '—'}</p>
        </div>

        <div style={{...cardBase, padding:'24px'}}>
          <div className="flex justify-between items-start mb-2">
            <p style={{fontSize:'14px',fontWeight:600,color:'#747780'}}>Male / Female Split</p>
            <StatIcon><Icon d={ICONS.balance} /></StatIcon>
          </div>
          <p style={{fontFamily:'var(--font-display)',fontSize:'24px',fontWeight:600,color:'var(--color-navy)'}}>{malePct}% / {femalePct}%</p>
          <div className="flex gap-1 mt-2">
            <div className="rounded-full" style={{height:'6px',width:`${malePct}%`,backgroundColor:'#1B3A6B'}}/>
            <div className="rounded-full" style={{height:'6px',width:`${femalePct}%`,backgroundColor:'#d7e2ff'}}/>
          </div>
        </div>
      </div>

      {/* Filters + Table card */}
      <div style={{...cardBase, overflow:'hidden'}}>
        {/* Filters bar */}
        <div className="flex flex-col lg:flex-row gap-4" style={{padding:'24px',borderBottom:'1px solid var(--color-surface-border)'}}>
          <div className="relative flex-1">
            <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4" style={{color:'#747780'}}
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <input type="text" placeholder="Search by name, phone, or member number"
                   className="input-field" style={{paddingLeft:'2.5rem'}}
                   value={search} onChange={e => { setSearch(e.target.value); setPage(1) }}/>
          </div>
          <div className="flex gap-2">
            <select className="input-field" style={{width:'auto'}}
                    value={statusFilter} onChange={e => { setStatus(e.target.value); setPage(1) }}>
              <option value="">All Statuses</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
              <option value="transferred">Transferred</option>
              <option value="deceased">Deceased</option>
            </select>
            <select className="input-field" style={{width:'auto'}}
                    value={genderFilter} onChange={e => { setGender(e.target.value); setPage(1) }}>
              <option value="">Gender: All</option>
              <option value="male">Male</option>
              <option value="female">Female</option>
            </select>
          </div>
        </div>

        {/* Table */}
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr style={{backgroundColor:'#f8f9fc'}}>
                {[['Member #'],['Name'],['Gender'],['Phone'],['Status'],['Join Date','center'],['Actions','right']].map(([h, align]) => (
                  <th key={h} className="uppercase tracking-wider"
                      style={{padding:'16px 24px',fontSize:'12px',fontWeight:700,color:'#747780',textAlign:align||'left'}}>
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr><td colSpan={7} className="text-center" style={{padding:'48px',color:'#9ca3af'}}>
                  <svg className="animate-spin w-6 h-6 mx-auto mb-2" fill="none" viewBox="0 0 24 24">
                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                  </svg>
                  Loading members...
                </td></tr>
              ) : members.length === 0 ? (
                <tr><td colSpan={7} className="text-center" style={{padding:'48px'}}>
                  <div className="text-4xl mb-3">👥</div>
                  <div className="font-semibold" style={{color:'var(--color-navy)'}}>No members found</div>
                  <div className="text-sm mt-1" style={{color:'#9ca3af'}}>
                    {search ? 'Try a different search term' : 'Add your first member to get started'}
                  </div>
                </td></tr>
              ) : members.map((member, i) => (
                <tr key={member.id} className="transition-colors"
                    style={{borderTop:'1px solid var(--color-surface-border)',
                            backgroundColor: i % 2 === 0 ? 'white' : '#ffffff'}}
                    onMouseEnter={e => e.currentTarget.style.backgroundColor='#f2f3f6'}
                    onMouseLeave={e => e.currentTarget.style.backgroundColor= i % 2 === 0 ? 'white' : '#ffffff'}>
                  <td style={{padding:'16px 24px',fontSize:'13px',fontFamily:'monospace',color:'#44474f'}}>
                    {member.member_number}
                  </td>
                  <td style={{padding:'16px 24px'}}>
                    <div className="flex items-center gap-3">
                      <div className="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0 text-sm font-bold text-white"
                           style={{backgroundColor:'var(--color-navy)'}}>
                        {member.first_name.charAt(0)}{member.last_name.charAt(0)}
                      </div>
                      <div>
                        <p className="font-bold" style={{color:'var(--color-navy)'}}>{member.full_name}</p>
                        {member.email && <p style={{fontSize:'12px',color:'#747780'}}>{member.email}</p>}
                      </div>
                    </div>
                  </td>
                  <td className="capitalize" style={{padding:'16px 24px',fontSize:'14px',color:'#44474f'}}>{member.gender}</td>
                  <td style={{padding:'16px 24px',fontSize:'14px',color:'#44474f'}}>{member.phone ?? '—'}</td>
                  <td style={{padding:'16px 24px'}}>
                    <span className="capitalize"
                          style={{padding:'4px 12px',borderRadius:'9999px',fontSize:'12px',fontWeight:700,
                                  backgroundColor:STATUS_COLORS[member.status]?.bg,color:STATUS_COLORS[member.status]?.text}}>
                      {member.status}
                    </span>
                  </td>
                  <td style={{padding:'16px 24px',fontSize:'14px',color:'#44474f',textAlign:'center'}}>{member.join_date ?? '—'}</td>
                  <td style={{padding:'16px 24px'}}>
                    <div className="flex justify-end gap-4">
                      <button onClick={() => navigate(`/members/${member.id}`)}
                              className="hover:underline" style={{fontSize:'14px',fontWeight:600,color:'var(--color-navy)'}}>
                        View
                      </button>
                      {can('edit members') && (
                        <button onClick={() => navigate(`/members/${member.id}/edit`)}
                                className="hover:underline" style={{fontSize:'14px',fontWeight:600,color:'#C9A84C'}}>
                          Edit
                        </button>
                      )}
                      {can('delete members') && (
                        <button onClick={() => handleDelete(member)} disabled={deleting === member.id}
                                className="hover:underline" style={{fontSize:'14px',fontWeight:600,color:'#ba1a1a'}}>
                          {deleting === member.id ? '...' : 'Delete'}
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {/* Pagination */}
        {meta && meta.last_page > 1 && (
          <div className="flex flex-col md:flex-row justify-between items-center gap-4"
               style={{padding:'24px',backgroundColor:'#f8f9fc',borderTop:'1px solid var(--color-surface-border)'}}>
            <p style={{fontSize:'14px',color:'#44474f'}}>
              Page <span className="font-bold" style={{color:'var(--color-navy)'}}>{meta.current_page}</span> of <span className="font-bold" style={{color:'var(--color-navy)'}}>{meta.last_page}</span> · {meta.total} members
            </p>
            <div className="flex items-center gap-2">
              <button disabled={page === 1} onClick={() => setPage(p => p - 1)}
                      className="w-10 h-10 rounded-lg flex items-center justify-center disabled:opacity-50"
                      style={{border:'1px solid var(--color-surface-border)',color:'var(--color-navy)'}}>
                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7"/>
                </svg>
              </button>
              <span className="w-10 h-10 rounded-lg flex items-center justify-center font-bold text-white"
                    style={{backgroundColor:'var(--color-navy)'}}>{meta.current_page}</span>
              <button disabled={page === meta.last_page} onClick={() => setPage(p => p + 1)}
                      className="w-10 h-10 rounded-lg flex items-center justify-center disabled:opacity-50"
                      style={{border:'1px solid var(--color-surface-border)',color:'var(--color-navy)'}}>
                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7"/>
                </svg>
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
