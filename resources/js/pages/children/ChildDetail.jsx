import { useState, useEffect } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { getChild, deleteChild } from '../../api/children'
import { usePermission } from '../../hooks/usePermission'
import { useConfirm } from '../../hooks/useConfirm'
import { toast } from 'sonner'

const cardBase = {
  backgroundColor: '#fff',
  border: '1px solid var(--color-surface-border)',
  borderRadius: '16px',
  boxShadow: '0 4px 12px rgba(13,31,60,0.05)',
}

export default function ChildDetail() {
  const { id }   = useParams()
  const navigate = useNavigate()
  const { can }  = usePermission()
  const { confirm, dialog } = useConfirm()

  const [child,  setChild]  = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    getChild(id)
      .then(res => setChild(res.data.data))
      .catch(() => navigate('/children'))
      .finally(() => setLoading(false))
  }, [id])

  const handleDelete = async () => {
    if (!(await confirm(`Remove ${child.full_name} from the children's register?`))) return
    try {
      await deleteChild(child.id)
      toast.success('Child removed.')
      navigate('/children')
    } catch {
      toast.error('Failed to remove child.')
    }
  }

  if (loading) return (
    <div className="flex items-center justify-center py-24">
      <svg className="animate-spin w-8 h-8" style={{color:'var(--color-navy)'}} fill="none" viewBox="0 0 24 24">
        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
      </svg>
    </div>
  )

  if (!child) return null

  const isBoy = child.gender === 'male'

  return (
    <div className="space-y-6" style={{maxWidth:'1440px'}}>

      {/* Page header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <button onClick={() => navigate('/children')}
                  className="w-10 h-10 flex items-center justify-center rounded-full transition-colors"
                  style={{border:'1px solid var(--color-surface-border)',backgroundColor:'white',color:'var(--color-navy)'}}>
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7"/>
            </svg>
          </button>
          <div>
            <h1 className="font-bold" style={{fontFamily:'var(--font-display)',fontSize:'32px',lineHeight:'40px',color:'var(--color-navy)'}}>
              {child.full_name}
            </h1>
            <p style={{fontSize:'14px',color:'#44474f'}}>
              {child.age != null ? `${child.age} years old` : 'Age unknown'} · {child.class_group ?? 'No class assigned'}
            </p>
          </div>
        </div>
        <div className="flex items-center gap-3">
          {can('edit children') && (
            <button onClick={() => navigate(`/children/${id}/edit`)}
                    className="btn-primary gap-2" style={{padding:'10px 24px'}}>
              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                      d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
              </svg>
              Edit Profile
            </button>
          )}
          {can('delete children') && (
            <button onClick={handleDelete}
                    className="gap-2 inline-flex items-center"
                    style={{padding:'10px 24px', backgroundColor:'white', color:'#ba1a1a', border:'1px solid #ba1a1a', borderRadius:'8px', fontWeight:600}}>
              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                      d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
              </svg>
              Remove
            </button>
          )}
        </div>
      </div>

      {/* Two-column layout */}
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

        {/* LEFT — profile */}
        <div className="lg:col-span-5">
          <div style={{...cardBase, padding:'24px'}}>
            <div className="flex items-center gap-4 mb-8">
              <div className="flex items-center justify-center flex-shrink-0 text-white font-bold"
                   style={{width:'96px',height:'96px',borderRadius:'16px',fontSize:'36px',fontFamily:'var(--font-display)',
                           background: isBoy
                             ? 'linear-gradient(135deg,#1d4ed8 0%,#3b82f6 100%)'
                             : 'linear-gradient(135deg,#be185d 0%,#ec4899 100%)',
                           boxShadow: isBoy
                             ? '0 8px 16px rgba(29,78,216,0.12)'
                             : '0 8px 16px rgba(190,24,93,0.12)'}}>
                {child.first_name.charAt(0)}{child.last_name.charAt(0)}
              </div>
              <div>
                <div className="flex items-center gap-2 mb-1">
                  <h2 style={{fontFamily:'var(--font-display)',fontSize:'24px',fontWeight:600,color:'var(--color-navy)'}}>
                    {child.full_name}
                  </h2>
                  <span className="uppercase tracking-wider"
                        style={{padding:'2px 10px',borderRadius:'9999px',fontSize:'11px',fontWeight:700,
                                backgroundColor: child.is_active ? '#dcfce7' : '#fef3c7',
                                color: child.is_active ? '#15803d' : '#92400e'}}>
                    {child.is_active ? 'Active' : 'Inactive'}
                  </span>
                </div>
                <p style={{color:'#44474f',fontSize:'16px',textTransform:'capitalize'}}>
                  {child.gender} · {child.class_group ?? 'No class'}
                </p>
              </div>
            </div>

            <div className="grid grid-cols-2 gap-y-6 pt-8" style={{borderTop:'1px solid var(--color-surface-border)'}}>
              {[
                ['Gender', child.gender, true],
                ['Date of Birth', child.date_of_birth],
                ['Age', child.age != null ? `${child.age} years` : '—'],
                ['Class Group', child.class_group],
                ['Status', child.is_active ? 'Active' : 'Inactive'],
                ['Registered', child.created_at],
              ].map(([label, value, cap]) => (
                <div key={label}>
                  <p className="uppercase mb-1" style={{fontSize:'11px',fontWeight:700,letterSpacing:'0.03em',color:'#747780'}}>{label}</p>
                  <p className={cap ? 'capitalize' : ''} style={{fontSize:'14px',fontWeight:600,color:'#191c1e'}}>
                    {value || '—'}
                  </p>
                </div>
              ))}
            </div>

            {child.notes && (
              <div className="pt-6 mt-6" style={{borderTop:'1px solid var(--color-surface-border)'}}>
                <p className="uppercase mb-1" style={{fontSize:'11px',fontWeight:700,letterSpacing:'0.03em',color:'#747780'}}>Notes</p>
                <p style={{fontSize:'14px',color:'#191c1e',whiteSpace:'pre-wrap'}}>{child.notes}</p>
              </div>
            )}
          </div>
        </div>

        {/* RIGHT — guardian */}
        <div className="lg:col-span-7">
          <div style={{...cardBase, overflow:'hidden'}}>
            <div className="flex items-center gap-2"
                 style={{padding:'24px',borderBottom:'1px solid var(--color-surface-border)'}}>
              <svg className="w-6 h-6" style={{color:'var(--color-navy)'}} fill="none" stroke="currentColor" strokeWidth={1.8} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
              </svg>
              <h3 style={{fontFamily:'var(--font-display)',fontSize:'24px',fontWeight:600,color:'var(--color-navy)'}}>Guardian</h3>
            </div>

            <div style={{padding:'24px'}}>
              {child.guardian ? (
                <div className="flex items-center gap-4">
                  <div className="w-14 h-14 rounded-full flex items-center justify-center flex-shrink-0 font-bold text-white"
                       style={{background:'linear-gradient(135deg,#002452 0%,#1b3a6b 100%)',fontSize:'20px',fontFamily:'var(--font-display)'}}>
                    {child.guardian.name?.charAt(0) ?? '?'}
                  </div>
                  <div className="flex-1">
                    <p style={{fontSize:'16px',fontWeight:600,color:'var(--color-navy)'}}>{child.guardian.name}</p>
                    <p style={{fontSize:'14px',color:'#747780'}}>
                      {child.guardian.member_number}
                      {child.guardian.phone ? ` · ${child.guardian.phone}` : ''}
                    </p>
                  </div>
                  <button onClick={() => navigate(`/members/${child.guardian.id}`)}
                          className="flex items-center gap-1.5 transition-colors"
                          style={{padding:'8px 16px',borderRadius:'8px',fontSize:'14px',fontWeight:600,
                                  border:'1px solid var(--color-navy)',color:'var(--color-navy)',backgroundColor:'white'}}>
                    View Member
                    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7"/>
                    </svg>
                  </button>
                </div>
              ) : (
                <div className="text-center py-6">
                  <p style={{fontSize:'14px',color:'#9ca3af'}}>No guardian assigned</p>
                </div>
              )}
            </div>
          </div>
        </div>
      </div>
      {dialog}
    </div>
  )
}
