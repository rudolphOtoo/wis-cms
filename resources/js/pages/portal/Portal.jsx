import React, { useState, useEffect, useCallback } from 'react'
import { useAuth } from '../../context/AuthContext'
import { getPortalProfile, getPortalGiving, getPortalAttendance } from '../../api/portal'

const fmt = (n) => `GHS ${Number(n).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

export default function Portal() {
  const { user, logout } = useAuth()
  const [tab, setTab] = useState('giving')

  const [profile,    setProfile]    = useState(null)
  const [giving,     setGiving]     = useState(null)
  const [givingYear, setGivingYear] = useState(null)
  const [attendance, setAttendance] = useState(null)
  const [loading,    setLoading]    = useState(true)

  const loadGiving = useCallback(async (year) => {
    const res = await getPortalGiving(year)
    setGiving(res.data.data)
    setGivingYear(res.data.data.year)
  }, [])

  useEffect(() => {
    Promise.all([
      getPortalProfile().then(r => setProfile(r.data.data)),
      loadGiving(),
      getPortalAttendance().then(r => setAttendance(r.data.data)),
    ]).catch(console.error).finally(() => setLoading(false))
  }, [loadGiving])

  if (loading) return (
    <div className="min-h-screen flex items-center justify-center" style={{backgroundColor:'var(--color-surface)'}}>
      <svg className="animate-spin w-8 h-8" style={{color:'var(--color-navy)'}} fill="none" viewBox="0 0 24 24">
        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
      </svg>
    </div>
  )

  return (
    <div className="min-h-screen" style={{backgroundColor:'var(--color-surface)'}}>
      {/* Header */}
      <header className="px-6 py-4 flex items-center justify-between"
              style={{backgroundColor:'var(--color-navy-deeper)'}}>
        <div className="flex items-center gap-3">
          <div className="w-9 h-9 rounded-lg flex items-center justify-center" style={{backgroundColor:'var(--color-gold)'}}>
            <svg className="w-5 h-5" style={{color:'var(--color-navy-deeper)'}} fill="currentColor" viewBox="0 0 24 24">
              <path d="M12 2L2 7v10l10 5 10-5V7L12 2zm0 2.18L20 8.5v7L12 19.82 4 15.5v-7l8-4.32z"/>
            </svg>
          </div>
          <div>
            <div className="text-white text-sm font-bold" style={{fontFamily:'var(--font-display)'}}>Member Portal</div>
            <div className="text-xs" style={{color:'rgba(255,255,255,0.4)'}}>Wesleyan International Society</div>
          </div>
        </div>
        <button onClick={logout} className="text-sm font-medium px-3 py-1.5 rounded-lg"
                style={{color:'white',backgroundColor:'rgba(255,255,255,0.1)'}}>
          Sign out
        </button>
      </header>

      <div className="max-w-3xl mx-auto px-4 py-6 space-y-6">
        {/* Welcome */}
        <div>
          <h1 className="text-2xl font-bold" style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
            Welcome, {profile?.full_name?.split(' ')[0]}
          </h1>
          <p className="text-sm" style={{color:'#6b7280'}}>{profile?.member_number}</p>
        </div>

        {/* Tabs */}
        <div className="flex gap-2">
          {[
            { k: 'giving',     label: 'My Giving' },
            { k: 'attendance', label: 'My Attendance' },
            { k: 'profile',    label: 'My Profile' },
          ].map(t => (
            <button key={t.k} onClick={() => setTab(t.k)}
                    className="px-4 py-2 rounded-lg text-sm font-semibold transition-colors"
                    style={{
                      backgroundColor: tab === t.k ? 'var(--color-navy)' : 'white',
                      color:           tab === t.k ? 'white' : '#374151',
                      border:          tab === t.k ? 'none' : '1px solid var(--color-surface-border)',
                    }}>
              {t.label}
            </button>
          ))}
        </div>

        {/* GIVING TAB */}
        {tab === 'giving' && giving && (
          <div className="space-y-4">
            <div className="rounded-2xl p-5" style={{background:'linear-gradient(135deg, var(--color-navy-deeper), var(--color-navy))'}}>
              <div className="flex items-center justify-between">
                <div className="text-xs uppercase tracking-wider" style={{color:'rgba(255,255,255,0.6)'}}>
                  Total Given in {givingYear}
                </div>
                {giving.available_years?.length > 1 && (
                  <select value={givingYear ?? ''} onChange={e => loadGiving(e.target.value)}
                          className="text-xs rounded px-2 py-1"
                          style={{backgroundColor:'rgba(255,255,255,0.15)',color:'white',border:'none'}}>
                    {giving.available_years.map(y => <option key={y} value={y} style={{color:'#111'}}>{y}</option>)}
                  </select>
                )}
              </div>
              <div className="text-3xl font-bold mt-2" style={{fontFamily:'var(--font-display)',color:'var(--color-gold)'}}>
                {fmt(giving.total)}
              </div>
            </div>

            {giving.total === 0 ? (
              <div className="card text-center py-10">
                <div className="text-3xl mb-2">💝</div>
                <div className="text-sm font-semibold" style={{color:'var(--color-navy)'}}>
                  No giving recorded for {givingYear}
                </div>
              </div>
            ) : (
              <>
                <div className="grid grid-cols-2 gap-3">
                  {giving.by_category.map(cat => (
                    <div key={cat.category} className="card py-3">
                      <div className="text-xs" style={{color:'#9ca3af'}}>{cat.category} ({cat.count})</div>
                      <div className="text-lg font-bold" style={{color:'var(--color-navy)'}}>{fmt(cat.total)}</div>
                    </div>
                  ))}
                </div>
                <div className="card p-0 overflow-hidden">
                  <table className="w-full">
                    <thead>
                      <tr style={{backgroundColor:'#f9fafb'}}>
                        {['Date','Category','Amount'].map(h => (
                          <th key={h} className="text-left px-4 py-2 text-xs font-semibold uppercase" style={{color:'#6b7280'}}>{h}</th>
                        ))}
                      </tr>
                    </thead>
                    <tbody>
                      {giving.transactions.map((t, i) => (
                        <tr key={t.id} style={{backgroundColor: i % 2 ? '#fafafa' : 'white'}}>
                          <td className="px-4 py-2 text-sm" style={{color:'#374151'}}>{t.date}</td>
                          <td className="px-4 py-2 text-sm" style={{color:'#374151'}}>{t.category}</td>
                          <td className="px-4 py-2 text-sm font-bold text-right" style={{color:'#15803d'}}>{fmt(t.amount)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </>
            )}
          </div>
        )}

        {/* ATTENDANCE TAB */}
        {tab === 'attendance' && attendance && (
          <div className="space-y-4">
            <div className="card py-5 text-center">
              <div className="text-3xl font-bold" style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
                {attendance.total_present}
              </div>
              <div className="text-xs mt-1" style={{color:'#6b7280'}}>Services attended (all time)</div>
            </div>
            <div className="card p-0 overflow-hidden">
              <div className="px-4 py-3" style={{backgroundColor:'#f9fafb',borderBottom:'1px solid var(--color-surface-border)'}}>
                <h3 className="text-sm font-bold" style={{color:'var(--color-navy)'}}>Recent Attendance</h3>
              </div>
              {attendance.recent.length === 0 ? (
                <div className="text-center py-8 text-sm" style={{color:'#9ca3af'}}>No attendance recorded yet</div>
              ) : (
                <div className="divide-y" style={{borderColor:'var(--color-surface-border)'}}>
                  {attendance.recent.map(r => (
                    <div key={r.id} className="px-4 py-3 flex items-center justify-between">
                      <span className="text-sm font-medium" style={{color:'#374151'}}>{r.service}</span>
                      <span className="text-sm" style={{color:'#9ca3af'}}>{r.date}</span>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>
        )}

        {/* PROFILE TAB */}
        {tab === 'profile' && profile && (
          <div className="card">
            <div className="grid grid-cols-2 gap-4">
              {[
                ['Full Name', profile.full_name],
                ['Member Number', profile.member_number],
                ['Gender', profile.gender, true],
                ['Phone', profile.phone],
                ['Email', profile.email],
                ['Occupation', profile.occupation],
                ['Marital Status', profile.marital_status, true],
                ['Date of Birth', profile.date_of_birth],
                ['Join Date', profile.join_date],
                ['Baptised', profile.is_baptised ? 'Yes' : 'No'],
                ['Address', profile.address],
              ].map(([label, value, cap]) => (
                <div key={label}>
                  <div className="text-xs uppercase tracking-wider mb-0.5" style={{color:'#9ca3af'}}>{label}</div>
                  <div className={`text-sm font-medium ${cap ? 'capitalize' : ''}`} style={{color:'#374151'}}>
                    {value || '—'}
                  </div>
                </div>
              ))}
            </div>
            <p className="text-xs mt-4 pt-4" style={{color:'#9ca3af',borderTop:'1px solid var(--color-surface-border)'}}>
              To update your details, please contact the church office.
            </p>
          </div>
        )}
      </div>
    </div>
  )
}
