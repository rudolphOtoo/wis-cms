import React, { useState, useEffect, useCallback } from 'react'
import { toast } from 'sonner'
import { useNavigate } from 'react-router-dom'
import { getCells, deleteCell } from '../../api/cells'
import { usePermission } from '../../hooks/usePermission'
import { useConfirm } from '../../hooks/useConfirm'

import { NAVY, MUTED, PLACEHOLDER, BORDER, FONT_DISPLAY } from '../../constants/styles'
const cardBase = {
  backgroundColor: '#fff',
  border: BORDER,
  borderRadius: '16px',
  boxShadow: '0 4px 12px rgba(13,31,60,0.05)',
}

const Icon = ({ d, size = 22 }) => (
  <svg width={size} height={size} fill="none" stroke="currentColor" strokeWidth={1.8}
       viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">{d}</svg>
)
const ICONS = {
  home:    <><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></>,
  verified:<><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></>,
  groups:  <><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></>,
}

export default function CellsPage() {
  const navigate = useNavigate()
  const { can }  = usePermission()
  const { confirm, dialog } = useConfirm()
  const [cells,    setCells]    = useState([])
  const [loading,  setLoading]  = useState(true)
  const [deleting, setDeleting] = useState(null)

  const fetchData = useCallback(async () => {
    setLoading(true)
    try {
      const res = await getCells()
      setCells(res.data.data)
    } catch (err) {
      console.error(err)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { fetchData() }, [fetchData])

  const handleDelete = async (cell) => {
    if (!(await confirm(`Delete "${cell.name}"? Members will be unassigned from this cell.`))) return
    setDeleting(cell.id)
    try {
      await deleteCell(cell.id)
      fetchData()
    } catch {
      toast.error('Failed to delete cell.')
    } finally {
      setDeleting(null)
    }
  }

  const total    = cells.length
  const active   = cells.filter(c => c.is_active).length
  const assigned = cells.reduce((sum, c) => sum + (c.members_count || 0), 0)
  const activePct = total > 0 ? (active / total) * 100 : 0

  const statCards = [
    { label:'Total Cells',      value: total,    icon: ICONS.home,     barPct: 100,       barColor:NAVY },
    { label:'Active',           value: active,   icon: ICONS.verified, barPct: activePct, barColor:'var(--color-gold)' },
    { label:'Members Assigned', value: assigned, icon: ICONS.groups,   barPct: 75,        barColor:NAVY },
  ]

  return (
    <div className="space-y-6" style={{maxWidth:'1440px'}}>

      <div className="flex justify-between items-end gap-4 flex-wrap">
        <div>
          <h2 className="font-bold" style={{fontFamily:FONT_DISPLAY,fontSize:'32px',lineHeight:'40px',color:NAVY}}>
            Cells &amp; Classes
          </h2>
          <p style={{color:'#44474f',marginTop:'4px'}}>Home groups and classes. Each member belongs to one cell.</p>
        </div>
        {can('create cells') && (
          <button onClick={() => navigate('/cells/new')} className="btn-primary gap-2" style={{padding:'10px 24px'}}>
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4"/>
            </svg>
            New Cell
          </button>
        )}
      </div>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        {statCards.map(s => (
          <div key={s.label} className="transition-transform hover:-translate-y-0.5" style={{...cardBase, padding:'24px'}}>
            <div className="flex items-start justify-between">
              <div>
                <p className="uppercase tracking-wider mb-1" style={{fontSize:'12px',fontWeight:700,color:MUTED}}>{s.label}</p>
                <h3 style={{fontFamily:FONT_DISPLAY,fontSize:'32px',fontWeight:700,color:NAVY}}>{s.value}</h3>
              </div>
              <div className="rounded-lg flex items-center justify-center"
                   style={{padding:'10px',backgroundColor:'rgba(27,58,107,0.08)',color:NAVY}}>
                <Icon d={s.icon} />
              </div>
            </div>
            <div className="mt-6 rounded-full overflow-hidden" style={{height:'4px',backgroundColor:'#e7e8eb'}}>
              <div className="h-full rounded-full" style={{width:`${s.barPct}%`,backgroundColor:s.barColor}}/>
            </div>
          </div>
        ))}
      </div>

      {loading ? (
        <div className="flex items-center justify-center py-24">
          <svg className="animate-spin w-8 h-8" style={{color:NAVY}} fill="none" viewBox="0 0 24 24">
            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
          </svg>
        </div>
      ) : cells.length === 0 ? (
        <div className="text-center py-16" style={{...cardBase, padding:'40px'}}>
          <div className="text-5xl mb-4">🏠</div>
          <h3 className="font-bold text-lg mb-2" style={{fontFamily:FONT_DISPLAY,color:NAVY}}>No cells yet</h3>
          <p className="text-sm mb-6" style={{color:'#6b7280'}}>Create your first cell to group members into home groups or classes</p>
          {can('create cells') && (
            <button onClick={() => navigate('/cells/new')} className="btn-primary">Create First Cell</button>
          )}
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
          {cells.map(cell => (
            <div key={cell.id} className="flex flex-col transition-all hover:-translate-y-1"
                 style={{...cardBase, padding:'24px'}}>
              <div className="flex gap-4 items-start">
                <div className="rounded-xl flex items-center justify-center shrink-0 font-bold"
                     style={{width:'56px',height:'56px',backgroundColor:NAVY,color:'var(--color-gold-light)',
                             fontFamily:FONT_DISPLAY,fontSize:'24px'}}>
                  {cell.name.charAt(0)}
                </div>
                <div className="flex-1 min-w-0">
                  <div className="flex justify-between items-start mb-1 gap-2">
                    <h4 className="leading-tight" style={{fontFamily:FONT_DISPLAY,fontSize:'18px',fontWeight:600,color:NAVY}}>
                      {cell.name}
                    </h4>
                    <span className="uppercase shrink-0" style={{padding:'2px 8px',borderRadius:'9999px',fontSize:'10px',fontWeight:700,
                            backgroundColor: cell.is_active ? '#dcfce7' : '#edeef1', color: cell.is_active ? '#15803d' : '#6b7280'}}>
                      {cell.is_active ? 'Active' : 'Inactive'}
                    </span>
                  </div>
                  {cell.leader && <p style={{fontSize:'14px',fontWeight:600,color:'var(--color-gold)',marginBottom:'8px'}}>{cell.leader.name}</p>}
                  <p className="line-clamp-2" style={{fontSize:'14px',color:'#44474f'}}>
                    {cell.description || 'No description provided.'}
                  </p>
                </div>
              </div>

              <div className="flex items-center justify-between mt-6 pt-6" style={{borderTop:BORDER}}>
                <div className="flex items-center gap-1.5" style={{color:MUTED}}>
                  <Icon d={ICONS.groups} size={18} />
                  <span style={{fontSize:'14px'}}><span className="font-semibold" style={{color:NAVY}}>{cell.members_count}</span> Members</span>
                </div>
                <div className="flex gap-2">
                  <button onClick={() => navigate(`/cells/${cell.id}`)}
                          className="rounded-full transition-colors" style={{padding:'6px 12px',fontSize:'12px',fontWeight:600,backgroundColor:'#edeef1',color:NAVY}}>
                    Manage
                  </button>
                  {can('edit cells') && (
                    <button onClick={() => navigate(`/cells/${cell.id}/edit`)}
                            className="rounded-full transition-colors" style={{padding:'6px 12px',fontSize:'12px',fontWeight:600,backgroundColor:'#edeef1',color:'#44474f'}}>
                      Edit
                    </button>
                  )}
                  {can('delete cells') && (
                    <button onClick={() => handleDelete(cell)} disabled={deleting === cell.id}
                            className="rounded-full transition-colors" style={{padding:'6px',color:'#ba1a1a'}}
                            title="Delete">
                      {deleting === cell.id ? '...' : (
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
      {dialog}
    </div>
  )
}
