import React, { useState, useEffect, useCallback } from 'react'
import { toast } from 'sonner'
import { useNavigate, useParams } from 'react-router-dom'
import { getSession, markAttendance } from '../../api/attendance'

const cardBase = {
  backgroundColor: '#fff',
  border: '1px solid var(--color-surface-border)',
  borderRadius: '16px',
  boxShadow: '0 4px 12px rgba(13,31,60,0.05)',
}

export default function TakeAttendance() {
  const navigate    = useNavigate()
  const { id }      = useParams()
  const [session,   setSession]   = useState(null)
  const [people,    setPeople]    = useState([])
  const [loading,   setLoading]   = useState(true)
  const [saving,    setSaving]    = useState(false)
  const [search,    setSearch]    = useState('')
  const [saved,     setSaved]     = useState(false)

  const fetchData = useCallback(async () => {
    setLoading(true)
    try {
      const res = await getSession(id)
      setSession(res.data.data.session)
      setPeople(res.data.data.people.map(p => ({ ...p })))
    } catch {
      navigate('/attendance')
    } finally {
      setLoading(false)
    }
  }, [id])

  useEffect(() => { fetchData() }, [fetchData])

  const togglePerson = (personId) => {
    setPeople(prev => prev.map(p =>
      p.id === personId ? { ...p, is_present: !p.is_present } : p
    ))
    setSaved(false)
  }

  const markAll = (present) => {
    setPeople(prev => prev.map(p => ({ ...p, is_present: present })))
    setSaved(false)
  }

  const handleSave = async () => {
    setSaving(true)
    try {
      const records = people.map(p => ({
        person_id:  p.id,
        type:       p.type,
        is_present: p.is_present,
      }))
      await markAttendance(id, { records })
      setSaved(true)
    } catch {
      toast.error('Failed to save attendance. Please try again.')
    } finally {
      setSaving(false)
    }
  }

  const presentCount = people.filter(p => p.is_present).length
  const absentCount  = people.filter(p => !p.is_present).length
  const total        = people.length
  const completion   = total > 0 ? Math.round((presentCount / total) * 100) : 0

  const filtered = people.filter(p =>
    p.name.toLowerCase().includes(search.toLowerCase()) ||
    p.member_number?.toLowerCase().includes(search.toLowerCase())
  )

  if (loading) return (
    <div className="flex items-center justify-center py-24">
      <svg className="animate-spin w-8 h-8" style={{color:'var(--color-navy)'}} fill="none" viewBox="0 0 24 24">
        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
      </svg>
    </div>
  )

  return (
    <div className="space-y-6" style={{maxWidth:'1440px'}}>

      {/* Header + counter pill */}
      <div className="flex justify-between items-end gap-4 flex-wrap">
        <div className="flex items-center gap-4">
          <button onClick={() => navigate('/attendance')}
                  className="w-11 h-11 flex items-center justify-center rounded-full"
                  style={{border:'1px solid var(--color-surface-border)',backgroundColor:'white',color:'var(--color-navy)'}}>
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7"/>
            </svg>
          </button>
          <div>
            <h2 className="font-bold" style={{fontFamily:'var(--font-display)',fontSize:'28px',lineHeight:'36px',color:'var(--color-navy)'}}>
              Take Attendance: {session?.service_type?.name}
            </h2>
            <p style={{color:'#44474f'}}>{session?.service_date} · {total} people</p>

            {/* Follow-up SMS status — automated post-meeting message lifecycle.
                Only certain states surface a visible badge to the leader. */}
            {session?.follow_up_status === 'not_sent' && session?.follow_up_scheduled_for && (
              <div className="mt-2 inline-flex items-center gap-2"
                   style={{backgroundColor:'#dbeafe',border:'1px solid #93c5fd',borderRadius:'8px',padding:'6px 12px',fontSize:'13px',color:'#1e40af'}}>
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                Follow-up SMS will be sent {new Date(session.follow_up_scheduled_for).toLocaleString('en-GB',{weekday:'short',day:'numeric',month:'short',hour:'2-digit',minute:'2-digit',hour12:true})}
              </div>
            )}

            {session?.follow_up_status === 'sent' && session?.follow_up_sent_at && (
              <div className="mt-2 inline-flex items-center gap-2"
                   style={{backgroundColor:'#dcfce7',border:'1px solid #86efac',borderRadius:'8px',padding:'6px 12px',fontSize:'13px',color:'#15803d'}}>
                <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                  <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
                </svg>
                Follow-up sent {new Date(session.follow_up_sent_at).toLocaleString('en-GB',{weekday:'short',day:'numeric',month:'short',hour:'2-digit',minute:'2-digit',hour12:true})}
              </div>
            )}

            {session?.follow_up_status === 'failed' && (
              <div className="mt-2 inline-flex items-center gap-2"
                   style={{backgroundColor:'#fee2e2',border:'1px solid #fca5a5',borderRadius:'8px',padding:'6px 12px',fontSize:'13px',color:'#991b1b'}}>
                <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                  <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                </svg>
                Follow-up could not be sent. Contact admin.
              </div>
            )}
          </div>
        </div>
        <div className="flex items-center gap-3 rounded-full shadow-md"
             style={{backgroundColor:'#ffdcc1',color:'#693c0a',padding:'12px 24px',border:'1px solid #fcb87d'}}>
          <svg className="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
          </svg>
          <span style={{fontSize:'20px'}}><span className="font-bold">{presentCount}</span> Present</span>
        </div>
      </div>

      {/* Summary mini-cards */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-6">
        {[
          { label:'Present', value: presentCount, color:'#15803d' },
          { label:'Absent',  value: absentCount,  color:'#ba1a1a' },
          { label:'Total',   value: total,        color:'var(--color-navy)' },
          { label:'Completion', value:`${completion}%`, color:'var(--color-navy)' },
        ].map(s => (
          <div key={s.label} className="flex flex-col justify-between" style={{...cardBase, padding:'24px', minHeight:'96px'}}>
            <span className="uppercase tracking-wider" style={{fontSize:'12px',fontWeight:700,color:'#747780'}}>{s.label}</span>
            <span style={{fontFamily:'var(--font-display)',fontSize:'24px',fontWeight:700,color:s.color}}>{s.value}</span>
          </div>
        ))}
      </div>

      {/* Controls */}
      <div style={{...cardBase, padding:'16px 24px'}}>
        <div className="flex flex-col sm:flex-row gap-3">
          <div className="flex-1 relative">
            <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4" style={{color:'#747780'}}
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <input type="text" placeholder="Search by name or member number..."
                   className="input-field" style={{paddingLeft:'2.5rem'}}
                   value={search} onChange={e => setSearch(e.target.value)}/>
          </div>
          <div className="flex gap-2">
            <button onClick={() => markAll(true)} className="px-4 py-2 rounded-lg text-sm font-semibold"
                    style={{backgroundColor:'#dcfce7',color:'#15803d'}}>Mark All Present</button>
            <button onClick={() => markAll(false)} className="px-4 py-2 rounded-lg text-sm font-semibold"
                    style={{backgroundColor:'#ffdad6',color:'#ba1a1a'}}>Clear All</button>
          </div>
        </div>
      </div>

      {/* People list — big toggle buttons */}
      <div style={{...cardBase, overflow:'hidden'}}>
        <div className="flex justify-between items-center"
             style={{padding:'12px 24px',backgroundColor:'#f2f3f6',borderBottom:'1px solid var(--color-surface-border)'}}>
          <span className="uppercase tracking-widest" style={{fontSize:'12px',fontWeight:700,color:'#747780'}}>Congregant Details</span>
          <span className="uppercase tracking-widest" style={{fontSize:'12px',fontWeight:700,color:'#747780'}}>Status Toggle</span>
        </div>

        {filtered.length === 0 ? (
          <div className="text-center" style={{padding:'48px',color:'#9ca3af'}}>No results for "{search}"</div>
        ) : (
          <div>
            {filtered.map(person => (
              <div key={person.id}
                   className="flex items-center justify-between gap-3 transition-colors"
                   style={{padding:'12px 16px',borderTop:'1px solid var(--color-surface-border)'}}>
                <div className="flex items-center gap-3 flex-1 min-w-0">
                  <div className="w-12 h-12 rounded-full flex items-center justify-center font-bold text-lg flex-shrink-0"
                       style={{backgroundColor: person.is_present ? '#dcfce7' : '#e1e2e5',
                               color: person.is_present ? '#15803d' : '#44474f',
                               border:'1px solid var(--color-surface-border)'}}>
                    {person.name.charAt(0)}
                  </div>
                  <div className="flex-1 min-w-0">
                    <h3 className="font-bold truncate" style={{fontSize:'17px',color:'var(--color-navy)'}}>{person.name}</h3>
                    <p className="truncate" style={{fontSize:'12px',color:'#747780'}}>{person.member_number ?? person.class ?? ''}</p>
                  </div>
                </div>
                <button onClick={() => togglePerson(person.id)}
                        className="flex items-center justify-center gap-1.5 rounded-xl shadow-sm transition-all active:scale-95 flex-shrink-0 w-[100px] md:w-[160px]"
                        style={{padding:'12px',fontWeight:600,fontSize:'13px',
                                backgroundColor: person.is_present ? '#2e7d32' : '#e1e2e5',
                                color: person.is_present ? 'white' : '#44474f'}}>
                  {person.is_present ? (
                    <><svg className="w-4 h-4 md:w-5 md:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M5 13l4 4L19 7"/></svg>PRESENT</>
                  ) : (
                    <><svg className="w-4 h-4 md:w-5 md:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M6 18L18 6M6 6l12 12"/></svg>ABSENT</>
                  )}
                </button>
              </div>
            ))}
          </div>
        )}

        {/* Footer actions */}
        <div className="flex justify-between items-center flex-wrap gap-3"
             style={{padding:'16px 24px',backgroundColor:'#edeef1',borderTop:'1px solid var(--color-surface-border)'}}>
          <p style={{fontSize:'14px',color:'#44474f'}}>
            Showing {filtered.length} of {total} {search ? '(filtered)' : 'members'}
          </p>
          <button onClick={handleSave} disabled={saving}
                  className="btn-primary gap-2 hidden md:inline-flex" style={{padding:'10px 32px'}}>
            {saving ? (
              <><svg className="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                  <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
                  <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>Saving...</>
            ) : saved ? (
              <><svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7"/></svg>Saved!</>
            ) : 'Submit Attendance'}
          </button>
        </div>
      </div>

      {/* Mobile bottom spacer — gives the fixed save bar clearance
          so the last member rows aren't covered when scrolled to bottom.
          Desktop ignores; the save bar floats over empty area on the right. */}
      <div className="md:hidden" style={{height:'90px'}}></div>

      {/* Floating save — full-width pinned on mobile (easy thumb-tap),
          floating bottom-right card on desktop. */}
      {!saved && total > 0 && (
        <div className="fixed bottom-4 left-4 right-4 md:left-auto md:bottom-6 md:right-6 z-20">
          <button onClick={handleSave} disabled={saving}
                  className="btn-primary w-full md:w-auto px-6 py-3 shadow-lg gap-2 text-base">
            {saving ? 'Saving...' : `Save — ${presentCount} Present`}
          </button>
        </div>
      )}
    </div>
  )
}
