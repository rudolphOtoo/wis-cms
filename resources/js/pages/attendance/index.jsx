import React, { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { getAttendance, getAttendanceStats } from '../../api/attendance'
import { usePermission } from '../../hooks/usePermission'

export default function AttendancePage() {
  const navigate  = useNavigate()
  const { can }   = usePermission()
  const [sessions, setSessions] = useState([])
  const [stats,    setStats]    = useState(null)
  const [loading,  setLoading]  = useState(true)
  const [page,     setPage]     = useState(1)
  const [meta,     setMeta]     = useState(null)

  const fetchData = useCallback(async () => {
    setLoading(true)
    try {
      const [aRes, sRes] = await Promise.all([
        getAttendance({ page, per_page: 15 }),
        getAttendanceStats(),
      ])
      setSessions(aRes.data.data)
      setMeta(aRes.data.meta)
      setStats(sRes.data.data)
    } catch (err) {
      console.error(err)
    } finally {
      setLoading(false)
    }
  }, [page])

  useEffect(() => { fetchData() }, [fetchData])

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        {[
          { label: 'Last Sunday Attendance', value: stats?.last_sunday    ?? '—' },
          { label: 'Average Attendance',     value: stats?.average        ?? '—' },
          { label: 'Total Sessions Recorded',value: stats?.total_sessions ?? '—' },
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

      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-xl font-bold"
              style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
            Attendance
          </h2>
          <p className="text-sm" style={{color:'#6b7280'}}>
            Track Sunday and weekday service attendance
          </p>
        </div>
        {can('create attendance') && (
          <button onClick={() => navigate('/attendance/new')} className="btn-primary gap-2">
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4"/>
            </svg>
            Take Attendance
          </button>
        )}
      </div>

      <div className="card p-0 overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead>
              <tr style={{borderBottom:'1px solid var(--color-surface-border)',backgroundColor:'#f9fafb'}}>
                {['Date','Service','Adults','Children','Total','Recorded By','Action'].map(h => (
                  <th key={h} className="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider"
                      style={{color:'#6b7280'}}>
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr>
                  <td colSpan={7} className="text-center py-12" style={{color:'#9ca3af'}}>Loading...</td>
                </tr>
              ) : sessions.length === 0 ? (
                <tr>
                  <td colSpan={7} className="text-center py-12">
                    <div className="text-4xl mb-3">📋</div>
                    <div className="font-semibold" style={{color:'var(--color-navy)'}}>
                      No attendance sessions yet
                    </div>
                    <div className="text-sm mt-1" style={{color:'#9ca3af'}}>
                      Click "Take Attendance" to record your first session
                    </div>
                  </td>
                </tr>
              ) : sessions.map((session, i) => (
                <tr key={session.id}
                    style={{borderBottom:'1px solid var(--color-surface-border)',
                            backgroundColor: i % 2 === 0 ? 'white' : '#fafafa'}}>
                  <td className="px-4 py-3 text-sm font-semibold" style={{color:'#111827'}}>
                    {session.service_date}
                  </td>
                  <td className="px-4 py-3 text-sm" style={{color:'#374151'}}>
                    {session.service_type?.name ?? '—'}
                  </td>
                  <td className="px-4 py-3 text-sm font-semibold" style={{color:'var(--color-navy)'}}>
                    {session.adult_count}
                  </td>
                  <td className="px-4 py-3 text-sm font-semibold" style={{color:'#7c3aed'}}>
                    {session.children_count}
                  </td>
                  <td className="px-4 py-3 text-sm font-bold" style={{color:'#111827'}}>
                    {session.total_count}
                  </td>
                  <td className="px-4 py-3 text-sm" style={{color:'#6b7280'}}>
                    {session.recorded_by ?? '—'}
                  </td>
                  <td className="px-4 py-3">
                    <button onClick={() => navigate(`/attendance/${session.id}`)}
                            className="text-xs px-2 py-1 rounded font-medium"
                            style={{color:'var(--color-navy)',backgroundColor:'rgba(27,58,107,0.08)'}}>
                      View
                    </button>
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
