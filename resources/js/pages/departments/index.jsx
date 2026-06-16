import React, { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { getDepartments, deleteDepartment, getDepartmentStats } from '../../api/departments'
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
  tree:    <><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></>,
  verified:<><path d="M9 12l2 2 4-4"/><path d="M21 12c0 1.66-.9 3.1-2.24 3.87.46 1.47-.05 3.07-1.27 3.99-1.22.92-2.87.9-4.07-.05C12.65 21.13 11.35 21.13 10.58 19.81 9.38 20.76 7.73 20.78 6.51 19.86 5.29 18.94 4.78 17.34 5.24 15.87 3.9 15.1 3 13.66 3 12s.9-3.1 2.24-3.87C4.78 6.66 5.29 5.06 6.51 4.14 7.73 3.22 9.38 3.24 10.58 4.19 11.35 2.87 12.65 2.87 13.42 4.19c1.2-.95 2.85-.97 4.07-.05 1.22.92 1.73 2.52 1.27 3.99C20.1 8.9 21 10.34 21 12z"/></>,
  groups:  <><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></>,
}

export default function DepartmentsPage() {
  const navigate = useNavigate()
  const { can }  = usePermission()
  const [departments, setDepartments] = useState([])
  const [stats,       setStats]       = useState(null)
  const [loading,     setLoading]     = useState(true)
  const [deleting,    setDeleting]    = useState(null)

  const fetchData = useCallback(async () => {
    setLoading(true)
    try {
      const [dRes, sRes] = await Promise.all([getDepartments(), getDepartmentStats()])
      setDepartments(dRes.data.data)
      setStats(sRes.data.data)
    } catch (err) {
      console.error(err)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { fetchData() }, [fetchData])

  const handleDelete = async (dept) => {
    if (!confirm(`Delete "${dept.name}"? Members will be removed from this department.`)) return
    setDeleting(dept.id)
    try {
      await deleteDepartment(dept.id)
      fetchData()
    } catch {
      alert('Failed to delete department.')
    } finally {
      setDeleting(null)
    }
  }

  const total       = stats?.total ?? 0
  const active      = stats?.active ?? 0
  const assigned    = stats?.total_members_assigned ?? 0
  const activePct   = total > 0 ? (active / total) * 100 : 0

  const statCards = [
    { label:'Total Departments', value: total,    icon: ICONS.tree,     barPct: 100,       barColor:'var(--color-navy)' },
    { label:'Active',            value: active,   icon: ICONS.verified, barPct: activePct, barColor:'var(--color-gold)' },
    { label:'Members Assigned',  value: assigned, icon: ICONS.groups,   barPct: 75,        barColor:'var(--color-navy)' },
  ]

  return (
    <div className="space-y-6" style={{maxWidth:'1440px'}}>

      {/* Header */}
      <div className="flex justify-between items-end gap-4 flex-wrap">
        <div>
          <h2 className="font-bold" style={{fontFamily:'var(--font-display)',fontSize:'32px',lineHeight:'40px',color:'var(--color-navy)'}}>
            Departments &amp; Groups
          </h2>
          <p style={{color:'#44474f',marginTop:'4px'}}>Oversee church ministries, wings, and specialized groups.</p>
        </div>
        {can('create departments') && (
          <button onClick={() => navigate('/departments/new')} className="btn-primary gap-2" style={{padding:'10px 24px'}}>
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4"/>
            </svg>
            New Department
          </button>
        )}
      </div>

      {/* Stat cards */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        {statCards.map(s => (
          <div key={s.label} className="transition-transform hover:-translate-y-0.5" style={{...cardBase, padding:'24px'}}>
            <div className="flex items-start justify-between">
              <div>
                <p className="uppercase tracking-wider mb-1" style={{fontSize:'12px',fontWeight:700,color:'#747780'}}>{s.label}</p>
                <h3 style={{fontFamily:'var(--font-display)',fontSize:'32px',fontWeight:700,color:'var(--color-navy)'}}>{s.value}</h3>
              </div>
              <div className="rounded-lg flex items-center justify-center"
                   style={{padding:'10px',backgroundColor:'rgba(27,58,107,0.08)',color:'var(--color-navy)'}}>
                <Icon d={s.icon} />
              </div>
            </div>
            <div className="mt-6 rounded-full overflow-hidden" style={{height:'4px',backgroundColor:'#e7e8eb'}}>
              <div className="h-full rounded-full" style={{width:`${s.barPct}%`,backgroundColor:s.barColor}}/>
            </div>
          </div>
        ))}
      </div>

      {/* Department grid */}
      {loading ? (
        <div className="flex items-center justify-center py-24">
          <svg className="animate-spin w-8 h-8" style={{color:'var(--color-navy)'}} fill="none" viewBox="0 0 24 24">
            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
          </svg>
        </div>
      ) : departments.length === 0 ? (
        <div className="text-center py-16" style={{...cardBase, padding:'40px'}}>
          <div className="text-5xl mb-4">🏛️</div>
          <h3 className="font-bold text-lg mb-2" style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>No departments yet</h3>
          <p className="text-sm mb-6" style={{color:'#6b7280'}}>Create your first department to organise members into groups</p>
          {can('create departments') && (
            <button onClick={() => navigate('/departments/new')} className="btn-primary">Create First Department</button>
          )}
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
          {departments.map(dept => (
            <div key={dept.id} className="flex flex-col transition-all hover:-translate-y-1"
                 style={{...cardBase, padding:'24px'}}>
              <div className="flex gap-4 items-start">
                <div className="rounded-xl flex items-center justify-center shrink-0 font-bold"
                     style={{width:'56px',height:'56px',backgroundColor:'var(--color-navy)',color:'var(--color-gold-light)',
                             fontFamily:'var(--font-display)',fontSize:'24px'}}>
                  {dept.name.charAt(0)}
                </div>
                <div className="flex-1 min-w-0">
                  <div className="flex justify-between items-start mb-1 gap-2">
                    <h4 className="leading-tight" style={{fontFamily:'var(--font-display)',fontSize:'18px',fontWeight:600,color:'var(--color-navy)'}}>
                      {dept.name}
                    </h4>
                    <span className="uppercase shrink-0" style={{padding:'2px 8px',borderRadius:'9999px',fontSize:'10px',fontWeight:700,
                            backgroundColor: dept.is_active ? '#dcfce7' : '#edeef1', color: dept.is_active ? '#15803d' : '#6b7280'}}>
                      {dept.is_active ? 'Active' : 'Inactive'}
                    </span>
                  </div>
                  {dept.leader && <p style={{fontSize:'14px',fontWeight:600,color:'var(--color-gold)',marginBottom:'8px'}}>{dept.leader.name}</p>}
                  <p className="line-clamp-2" style={{fontSize:'14px',color:'#44474f'}}>
                    {dept.description || 'No description provided.'}
                  </p>
                </div>
              </div>

              <div className="flex items-center justify-between mt-6 pt-6" style={{borderTop:'1px solid var(--color-surface-border)'}}>
                <div className="flex items-center gap-1.5" style={{color:'#747780'}}>
                  <Icon d={ICONS.groups} size={18} />
                  <span style={{fontSize:'14px'}}><span className="font-semibold" style={{color:'var(--color-navy)'}}>{dept.members_count}</span> Members</span>
                </div>
                <div className="flex gap-2">
                  <button onClick={() => navigate(`/departments/${dept.id}`)}
                          className="rounded-full transition-colors" style={{padding:'6px 12px',fontSize:'12px',fontWeight:600,backgroundColor:'#edeef1',color:'var(--color-navy)'}}>
                    Manage
                  </button>
                  {can('edit departments') && (
                    <button onClick={() => navigate(`/departments/${dept.id}/edit`)}
                            className="rounded-full transition-colors" style={{padding:'6px 12px',fontSize:'12px',fontWeight:600,backgroundColor:'#edeef1',color:'#44474f'}}>
                      Edit
                    </button>
                  )}
                  {can('delete departments') && (
                    <button onClick={() => handleDelete(dept)} disabled={deleting === dept.id}
                            className="rounded-full transition-colors" style={{padding:'6px',color:'#ba1a1a'}}
                            title="Delete">
                      {deleting === dept.id ? '...' : (
                        <svg className="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                      )}
                    </button>
                  )}
                </div>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
