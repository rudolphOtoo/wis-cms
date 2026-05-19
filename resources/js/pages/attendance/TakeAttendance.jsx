import React, { useState, useEffect, useCallback } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { getSession, markAttendance } from '../../api/attendance'

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
      alert('Failed to save attendance. Please try again.')
    } finally {
      setSaving(false)
    }
  }

  const presentCount = people.filter(p => p.is_present).length
  const absentCount  = people.filter(p => !p.is_present).length

  const filtered = people.filter(p =>
    p.name.toLowerCase().includes(search.toLowerCase()) ||
    p.member_number?.toLowerCase().includes(search.toLowerCase())
  )

  if (loading) return (
    <div className="flex items-center justify-center py-24">
      <svg className="animate-spin w-8 h-8" style={{color:'var(--color-navy)'}}
           fill="none" viewBox="0 0 24 24">
        <circle className="opacity-25" cx="12" cy="12" r="10"
                stroke="currentColor" strokeWidth="4"/>
        <path className="opacity-75" fill="currentColor"
              d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
      </svg>
    </div>
  )

  return (
    <div className="space-y-4">

      {/* Header */}
      <div className="flex items-center gap-4">
        <button onClick={() => navigate('/attendance')}
                className="p-2 rounded-lg"
                style={{backgroundColor:'white',border:'1px solid var(--color-surface-border)'}}>
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7"/>
          </svg>
        </button>
        <div className="flex-1">
          <h2 className="text-xl font-bold"
              style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
            {session?.service_type?.name}
          </h2>
          <p className="text-sm" style={{color:'#6b7280'}}>
            {session?.service_date} · {people.length} people
          </p>
        </div>
        <button onClick={handleSave} disabled={saving}
                className="btn-primary px-6 py-2.5 gap-2">
          {saving ? (
            <><svg className="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                <circle className="opacity-25" cx="12" cy="12" r="10"
                        stroke="currentColor" strokeWidth="4"/>
                <path className="opacity-75" fill="currentColor"
                      d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
              </svg>Saving...</>
          ) : saved ? (
            <><svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                      d="M5 13l4 4L19 7"/>
              </svg>Saved!</>
          ) : 'Save Attendance'}
        </button>
      </div>

      {/* Summary bar */}
      <div className="grid grid-cols-3 gap-4">
        <div className="card py-3 text-center">
          <div className="text-2xl font-bold" style={{color:'#15803d'}}>{presentCount}</div>
          <div className="text-xs" style={{color:'#6b7280'}}>Present</div>
        </div>
        <div className="card py-3 text-center">
          <div className="text-2xl font-bold" style={{color:'#dc2626'}}>{absentCount}</div>
          <div className="text-xs" style={{color:'#6b7280'}}>Absent</div>
        </div>
        <div className="card py-3 text-center">
          <div className="text-2xl font-bold" style={{color:'var(--color-navy)'}}>
            {people.length}
          </div>
          <div className="text-xs" style={{color:'#6b7280'}}>Total</div>
        </div>
      </div>

      {/* Controls */}
      <div className="card py-3">
        <div className="flex flex-col sm:flex-row gap-3">
          <div className="flex-1 relative">
            <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4"
                 style={{color:'#9ca3af'}} fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <input type="text" placeholder="Search by name or member number..."
                   className="input-field" style={{paddingLeft:'2.5rem'}}
                   value={search} onChange={e => setSearch(e.target.value)}/>
          </div>
          <div className="flex gap-2">
            <button onClick={() => markAll(true)}
                    className="px-4 py-2 rounded-lg text-sm font-semibold"
                    style={{backgroundColor:'#dcfce7',color:'#15803d'}}>
              Mark All Present
            </button>
            <button onClick={() => markAll(false)}
                    className="px-4 py-2 rounded-lg text-sm font-semibold"
                    style={{backgroundColor:'#fee2e2',color:'#dc2626'}}>
              Clear All
            </button>
          </div>
        </div>
      </div>

      {/* People list */}
      <div className="card p-0 overflow-hidden">
        {filtered.length === 0 ? (
          <div className="text-center py-12" style={{color:'#9ca3af'}}>
            No results for "{search}"
          </div>
        ) : (
          <div className="divide-y" style={{borderColor:'var(--color-surface-border)'}}>
            {filtered.map(person => (
              <div key={person.id}
                   onClick={() => togglePerson(person.id)}
                   className="flex items-center gap-4 px-4 py-3 cursor-pointer transition-colors"
                   style={{backgroundColor: person.is_present ? '#f0fdf4' : 'white'}}>

                {/* Checkbox */}
                <div className="w-6 h-6 rounded-full border-2 flex items-center justify-center flex-shrink-0"
                     style={{
                       backgroundColor: person.is_present ? '#15803d' : 'white',
                       borderColor:     person.is_present ? '#15803d' : '#d1d5db',
                     }}>
                  {person.is_present && (
                    <svg className="w-3.5 h-3.5 text-white" fill="none"
                         stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round"
                            strokeWidth={3} d="M5 13l4 4L19 7"/>
                    </svg>
                  )}
                </div>

                {/* Avatar */}
                <div className="w-9 h-9 rounded-full flex items-center justify-center
                                text-sm font-bold text-white flex-shrink-0"
                     style={{backgroundColor: person.is_present ? '#15803d' : '#9ca3af'}}>
                  {person.name.charAt(0)}
                </div>

                {/* Name */}
                <div className="flex-1 min-w-0">
                  <div className="text-sm font-semibold truncate"
                       style={{color: person.is_present ? '#15803d' : '#111827'}}>
                    {person.name}
                  </div>
                  <div className="text-xs" style={{color:'#9ca3af'}}>
                    {person.member_number ?? person.class ?? ''}
                  </div>
                </div>

                {/* Status badge */}
                <span className="text-xs font-semibold px-2 py-0.5 rounded-full flex-shrink-0"
                      style={{
                        backgroundColor: person.is_present ? '#dcfce7' : '#f3f4f6',
                        color:           person.is_present ? '#15803d' : '#6b7280',
                      }}>
                  {person.is_present ? 'Present' : 'Absent'}
                </span>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Floating save */}
      {!saved && people.length > 0 && (
        <div className="fixed bottom-6 right-6">
          <button onClick={handleSave} disabled={saving}
                  className="btn-primary px-6 py-3 shadow-lg gap-2 text-base">
            {saving ? 'Saving...' : `Save — ${presentCount} Present`}
          </button>
        </div>
      )}
    </div>
  )
}
