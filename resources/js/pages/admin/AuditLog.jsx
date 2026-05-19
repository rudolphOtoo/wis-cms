import React, { useState, useEffect, useCallback } from 'react'
import { getAuditLog } from '../../api/audit'

const SUBJECT_COLORS = {
  Member:            { bg: '#dbeafe', text: '#1d4ed8' },
  Visitor:           { bg: '#ede9fe', text: '#6d28d9' },
  Children:          { bg: '#fce7f3', text: '#9d174d' },
  Department:        { bg: '#fef3c7', text: '#92400e' },
  AttendanceSession: { bg: '#dcfce7', text: '#15803d' },
  Transaction:       { bg: '#fee2e2', text: '#dc2626' },
  User:              { bg: '#e0e7ff', text: '#4338ca' },
}

export default function AuditLog() {
  const [logs,     setLogs]     = useState([])
  const [loading,  setLoading]  = useState(true)
  const [search,   setSearch]   = useState('')
  const [from,     setFrom]     = useState('')
  const [to,       setTo]       = useState('')
  const [subType,  setSubType]  = useState('')
  const [page,     setPage]     = useState(1)
  const [meta,     setMeta]     = useState(null)

  const fetchData = useCallback(async () => {
    setLoading(true)
    try {
      const res = await getAuditLog({ search, from, to, subject_type: subType, page, per_page: 25 })
      setLogs(res.data.data)
      setMeta(res.data.meta)
    } catch (err) {
      console.error(err)
    } finally {
      setLoading(false)
    }
  }, [search, from, to, subType, page])

  useEffect(() => { fetchData() }, [fetchData])
  useEffect(() => {
    const t = setTimeout(() => fetchData(), 400)
    return () => clearTimeout(t)
  }, [search])

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl font-bold"
            style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
          Audit Log
        </h2>
        <p className="text-sm" style={{color:'#6b7280'}}>
          {meta ? `${meta.total} recorded activities` : 'Loading...'} · Every system action is logged here
        </p>
      </div>

      <div className="card py-4">
        <div className="grid grid-cols-1 sm:grid-cols-4 gap-3">
          <div className="sm:col-span-2 relative">
            <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4"
                 style={{color:'#9ca3af'}} fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <input type="text" placeholder="Search activity description..."
                   className="input-field" style={{paddingLeft:'2.5rem'}}
                   value={search} onChange={e => { setSearch(e.target.value); setPage(1) }}/>
          </div>
          <input type="date" className="input-field" value={from}
                 onChange={e => { setFrom(e.target.value); setPage(1) }}
                 title="From date"/>
          <input type="date" className="input-field" value={to}
                 onChange={e => { setTo(e.target.value); setPage(1) }}
                 title="To date"/>
        </div>
        <div className="flex gap-2 mt-3 flex-wrap">
          {['', 'Member', 'Visitor', 'Children', 'Department', 'Transaction', 'AttendanceSession', 'User'].map(t => (
            <button key={t || 'all'} onClick={() => { setSubType(t); setPage(1) }}
                    className="px-3 py-1 rounded-full text-xs font-medium transition-colors"
                    style={{
                      backgroundColor: subType === t ? 'var(--color-navy)' : 'rgba(27,58,107,0.08)',
                      color:           subType === t ? 'white' : 'var(--color-navy)',
                    }}>
              {t || 'All'}
            </button>
          ))}
        </div>
      </div>

      <div className="card p-0 overflow-hidden">
        {loading ? (
          <div className="text-center py-12" style={{color:'#9ca3af'}}>Loading...</div>
        ) : logs.length === 0 ? (
          <div className="text-center py-12">
            <div className="text-4xl mb-3">📋</div>
            <div className="font-semibold" style={{color:'var(--color-navy)'}}>No activities found</div>
          </div>
        ) : (
          <div className="divide-y" style={{borderColor:'var(--color-surface-border)'}}>
            {logs.map(log => (
              <div key={log.id} className="px-4 py-3 flex items-start gap-4">
                <div className="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 text-sm font-bold text-white"
                     style={{backgroundColor: log.causer ? 'var(--color-navy)' : '#9ca3af'}}>
                  {log.causer?.name?.charAt(0) ?? '?'}
                </div>
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2 flex-wrap">
                    <span className="text-sm font-semibold" style={{color:'#111827'}}>
                      {log.causer?.name ?? <em>System</em>}
                    </span>
                    {log.subject_type && (
                      <span className="text-xs px-1.5 py-0.5 rounded font-medium"
                            style={{
                              backgroundColor: SUBJECT_COLORS[log.subject_type]?.bg ?? '#f3f4f6',
                              color:           SUBJECT_COLORS[log.subject_type]?.text ?? '#6b7280',
                            }}>
                        {log.subject_type}
                      </span>
                    )}
                  </div>
                  <div className="text-sm mt-0.5" style={{color:'#374151'}}>
                    {log.description}
                  </div>
                  <div className="text-xs mt-1" style={{color:'#9ca3af'}}>
                    {log.when} · {log.created_at}
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}

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
