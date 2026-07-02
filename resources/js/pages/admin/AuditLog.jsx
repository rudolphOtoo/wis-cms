import React, { useState, useEffect, useCallback } from 'react'
import { getAuditLog } from '../../api/audit'
import { useDebounce } from '../../hooks/useDebounce'

import { NAVY, MUTED, PLACEHOLDER, BORDER, FONT_DISPLAY } from '../../constants/styles'
const cardBase = {
  backgroundColor: '#fff',
  border: BORDER,
  borderRadius: '16px',
  boxShadow: '0 4px 12px rgba(13,31,60,0.05)',
}

const SUBJECT_COLORS = {
  Member:            { bg: '#dbeafe', text: '#1d4ed8', border: '#bfdbfe' },
  Visitor:           { bg: '#ede9fe', text: '#6d28d9', border: '#ddd6fe' },
  Children:          { bg: '#fce7f3', text: '#9d174d', border: '#fbcfe8' },
  Department:        { bg: '#fef3c7', text: '#92400e', border: '#fde68a' },
  AttendanceSession: { bg: '#fed7aa', text: '#9a3412', border: '#fdba74' },
  Transaction:       { bg: '#dcfce7', text: '#15803d', border: '#bbf7d0' },
  User:              { bg: '#e0e7ff', text: '#4338ca', border: '#c7d2fe' },
}

const FILTER_TYPES = ['', 'Member', 'Visitor', 'Children', 'Department', 'Transaction', 'AttendanceSession', 'User']

export default function AuditLog() {
  const [logs,     setLogs]     = useState([])
  const [loading,  setLoading]  = useState(true)
  const [search,   setSearch]   = useState('')
  const [from,     setFrom]     = useState('')
  const [to,       setTo]       = useState('')
  const [subType,  setSubType]  = useState('')
  const [page,     setPage]     = useState(1)
  const [meta,     setMeta]     = useState(null)

  const debouncedSearch = useDebounce(search, 400)

  const fetchData = useCallback(async () => {
    setLoading(true)
    try {
      const res = await getAuditLog({ search: debouncedSearch, from, to, subject_type: subType, page, per_page: 25 })
      setLogs(res.data.data)
      setMeta(res.data.meta)
    } catch (err) {
      console.error(err)
    } finally {
      setLoading(false)
    }
  }, [debouncedSearch, from, to, subType, page])

  useEffect(() => { fetchData() }, [fetchData])

  return (
    <div className="space-y-6" style={{maxWidth:'1440px'}}>

      {/* Header */}
      <div>
        <h2 className="font-bold" style={{fontFamily:FONT_DISPLAY,fontSize:'32px',lineHeight:'40px',color:NAVY}}>
          Audit Log
        </h2>
        <p style={{color:'#44474f'}}>
          Every system action is logged here for transparent stewardship.
          {meta && <span style={{color:MUTED}}> · {meta.total} recorded activities</span>}
        </p>
      </div>

      {/* Filter bar */}
      <div style={{...cardBase, padding:'24px'}} className="space-y-4">
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div className="relative">
            <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4" style={{color:MUTED}}
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <input type="text" placeholder="Search log descriptions..."
                   className="input-field" style={{paddingLeft:'2.5rem'}}
                   value={search} onChange={e => { setSearch(e.target.value); setPage(1) }}/>
          </div>
          <div className="flex items-center gap-2">
            <div className="flex-1 relative">
              <span className="absolute left-3 top-1/2 -translate-y-1/2" style={{fontSize:'10px',fontWeight:700,color:MUTED}}>FROM</span>
              <input type="date" className="input-field" style={{paddingLeft:'3rem'}}
                     value={from} onChange={e => { setFrom(e.target.value); setPage(1) }}/>
            </div>
            <div className="flex-1 relative">
              <span className="absolute left-3 top-1/2 -translate-y-1/2" style={{fontSize:'10px',fontWeight:700,color:MUTED}}>TO</span>
              <input type="date" className="input-field" style={{paddingLeft:'2.5rem'}}
                     value={to} onChange={e => { setTo(e.target.value); setPage(1) }}/>
            </div>
          </div>
          <div className="flex justify-end items-center">
            {(search || from || to || subType) && (
              <button onClick={() => { setSearch(''); setFrom(''); setTo(''); setSubType(''); setPage(1) }}
                      className="px-4 py-2 rounded-lg flex items-center gap-2" style={{border:BORDER,fontSize:'14px',color:NAVY}}>
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12"/></svg>
                Clear Filters
              </button>
            )}
          </div>
        </div>

        {/* Type pills */}
        <div className="flex flex-wrap gap-2 pt-1">
          {FILTER_TYPES.map(t => {
            const active = subType === t
            return (
              <button key={t || 'all'} onClick={() => { setSubType(t); setPage(1) }}
                      className="rounded-full transition-colors" style={{padding:'4px 16px',fontSize:'12px',fontWeight:600,
                              backgroundColor: active ? NAVY : '#edeef1',
                              color: active ? '#fff' : '#44474f',
                              border: active ? 'none' : BORDER}}>
                {t || 'All'}
              </button>
            )
          })}
        </div>
      </div>

      {/* Timeline */}
      {loading ? (
        <div className="flex items-center justify-center py-24">
          <svg className="animate-spin w-8 h-8" style={{color:NAVY}} fill="none" viewBox="0 0 24 24">
            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
          </svg>
        </div>
      ) : logs.length === 0 ? (
        <div className="text-center py-16" style={{...cardBase, padding:'40px'}}>
          <div className="text-4xl mb-3">📋</div>
          <div className="font-semibold" style={{color:NAVY}}>No activities found</div>
          <div className="text-sm mt-1" style={{color:PLACEHOLDER}}>Try adjusting your filters</div>
        </div>
      ) : (
        <div className="relative">
          {/* Timeline thread */}
          <div className="absolute" style={{left:'32px',top:0,bottom:0,width:'1px',backgroundColor:'var(--color-surface-border)'}}/>
          <div className="space-y-4 relative">
            {logs.map(log => {
              const sc = log.subject_type ? (SUBJECT_COLORS[log.subject_type] ?? { bg:'#f3f4f6', text:'#6b7280', border:'#e5e7eb' }) : null
              const initials = log.causer?.name
                ? log.causer.name.split(' ').map(w => w.charAt(0)).slice(0, 2).join('')
                : '⚙'
              return (
                <div key={log.id} className="flex items-start gap-6 group">
                  {/* Avatar node */}
                  <div className="relative z-10 flex-shrink-0">
                    <div className="rounded-full flex items-center justify-center font-bold text-white"
                         style={{width:'64px',height:'64px',backgroundColor: log.causer ? NAVY : PLACEHOLDER,
                                 fontFamily:FONT_DISPLAY,fontSize:'20px',boxShadow:'0 0 0 8px var(--color-surface, #f8f9fc)'}}>
                      {initials}
                    </div>
                  </div>
                  {/* Card */}
                  <div className="flex-1 transition-all group-hover:-translate-y-0.5" style={{...cardBase, padding:'16px 20px'}}>
                    <div className="flex flex-wrap justify-between items-center mb-1 gap-2">
                      <div className="flex items-center gap-2">
                        <span className="font-bold" style={{color:NAVY}}>{log.causer?.name ?? 'System'}</span>
                        {log.subject_type && sc && (
                          <span className="rounded-full" style={{padding:'2px 10px',fontSize:'12px',fontWeight:600,backgroundColor:sc.bg,color:sc.text,border:`1px solid ${sc.border}`}}>
                            {log.subject_type}
                          </span>
                        )}
                      </div>
                      <span style={{fontSize:'12px',color:MUTED}}>{log.when} · {log.created_at}</span>
                    </div>
                    <p style={{fontSize:'15px',color:'#191c1e'}}>{log.description}</p>
                  </div>
                </div>
              )
            })}
          </div>
        </div>
      )}

      {/* Pagination */}
      {meta && meta.last_page > 1 && (
        <div className="flex justify-between items-center pt-2" style={{borderTop:BORDER,paddingTop:'16px'}}>
          <span style={{fontSize:'14px',color:MUTED}}>Page {meta.current_page} of {meta.last_page} · {meta.total} actions</span>
          <div className="flex items-center gap-2">
            <button disabled={page === 1} onClick={() => setPage(p => p - 1)}
                    className="w-11 h-11 rounded-lg flex items-center justify-center disabled:opacity-40"
                    style={{border:BORDER,color:NAVY}}>
              <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7"/></svg>
            </button>
            <span className="w-10 h-10 rounded-lg flex items-center justify-center font-bold text-white" style={{backgroundColor:NAVY}}>{meta.current_page}</span>
            <button disabled={page === meta.last_page} onClick={() => setPage(p => p + 1)}
                    className="w-11 h-11 rounded-lg flex items-center justify-center disabled:opacity-40"
                    style={{border:BORDER,color:NAVY}}>
              <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7"/></svg>
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
