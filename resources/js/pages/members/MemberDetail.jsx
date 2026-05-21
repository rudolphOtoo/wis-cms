import React, { useState, useEffect, useCallback } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { getMember, getMemberGiving, downloadGivingStatement } from '../../api/members'
import { usePermission } from '../../hooks/usePermission'

const fmt = (n) => `GHS ${Number(n).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

const STATUS_COLORS = {
  active:      { bg: '#dcfce7', text: '#16a34a' },
  inactive:    { bg: '#f3f4f6', text: '#6b7280' },
  transferred: { bg: '#dbeafe', text: '#2563eb' },
  deceased:    { bg: '#fce7f3', text: '#9d174d' },
}

export default function MemberDetail() {
  const { id }   = useParams()
  const navigate = useNavigate()
  const { can }  = usePermission()

  const [member,  setMember]  = useState(null)
  const [loading, setLoading] = useState(true)

  // Giving state
  const [giving,      setGiving]      = useState(null)
  const [givingYear,  setGivingYear]  = useState(null)
  const [givingLoad,  setGivingLoad]  = useState(false)
  const [downloading, setDownloading] = useState(false)

  useEffect(() => {
    getMember(id)
      .then(res => setMember(res.data.data))
      .catch(() => navigate('/members'))
      .finally(() => setLoading(false))
  }, [id])

  const fetchGiving = useCallback(async (year) => {
    setGivingLoad(true)
    try {
      const res = await getMemberGiving(id, year)
      setGiving(res.data.data)
      setGivingYear(res.data.data.year)
    } catch (err) {
      console.error(err)
    } finally {
      setGivingLoad(false)
    }
  }, [id])

  useEffect(() => {
    if (can('view finance')) fetchGiving()
  }, [fetchGiving])

  const handleDownload = async () => {
    setDownloading(true)
    try {
      const res = await downloadGivingStatement(id, givingYear)
      const url = window.URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }))
      const a = document.createElement('a')
      a.href = url
      a.download = `giving-statement-${member.member_number}-${givingYear}.pdf`
      document.body.appendChild(a)
      a.click()
      a.remove()
      window.URL.revokeObjectURL(url)
    } catch {
      alert('Failed to download statement.')
    } finally {
      setDownloading(false)
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

  if (!member) return null

  const sc = STATUS_COLORS[member.status] ?? STATUS_COLORS.inactive

  return (
    <div className="max-w-4xl mx-auto space-y-6">

      {/* Header */}
      <div className="flex items-center gap-4">
        <button onClick={() => navigate('/members')}
                className="p-2 rounded-lg"
                style={{backgroundColor:'white',border:'1px solid var(--color-surface-border)'}}>
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7"/>
          </svg>
        </button>
        <div className="flex-1">
          <h2 className="text-xl font-bold" style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
            {member.full_name}
          </h2>
          <p className="text-sm" style={{color:'#6b7280'}}>{member.member_number}</p>
        </div>
        {can('edit members') && (
          <button onClick={() => navigate(`/members/${id}/edit`)} className="btn-primary px-5 py-2">
            Edit
          </button>
        )}
      </div>

      {/* Profile card */}
      <div className="card">
        <div className="flex items-start gap-4 mb-6">
          <div className="w-16 h-16 rounded-2xl flex items-center justify-center flex-shrink-0 text-2xl font-bold text-white"
               style={{backgroundColor:'var(--color-navy)'}}>
            {member.first_name.charAt(0)}{member.last_name.charAt(0)}
          </div>
          <div className="flex-1">
            <div className="flex items-center gap-3">
              <h3 className="text-lg font-bold" style={{color:'#111827'}}>{member.full_name}</h3>
              <span className="px-2 py-0.5 rounded-full text-xs font-semibold capitalize"
                    style={{backgroundColor: sc.bg, color: sc.text}}>
                {member.status}
              </span>
            </div>
            {member.occupation && <p className="text-sm" style={{color:'#6b7280'}}>{member.occupation}</p>}
          </div>
        </div>

        <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
          {[
            ['Gender', member.gender, true],
            ['Phone', member.phone],
            ['Email', member.email],
            ['Date of Birth', member.date_of_birth],
            ['Marital Status', member.marital_status, true],
            ['Join Date', member.join_date],
            ['Baptised', member.is_baptised ? 'Yes' : 'No'],
            ['Address', member.address],
          ].map(([label, value, cap]) => (
            <div key={label}>
              <div className="text-xs uppercase tracking-wider mb-0.5" style={{color:'#9ca3af'}}>{label}</div>
              <div className={`text-sm font-medium ${cap ? 'capitalize' : ''}`} style={{color:'#374151'}}>
                {value || '—'}
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* Giving section — only for users who can view finance */}
      {can('view finance') && (
        <div className="card">
          <div className="flex items-center justify-between mb-4">
            <div>
              <h3 className="font-bold text-sm uppercase tracking-wider" style={{color:'var(--color-navy)'}}>
                Giving History
              </h3>
              <p className="text-xs" style={{color:'#9ca3af'}}>Tithes and offerings on record</p>
            </div>
            <div className="flex items-center gap-2">
              {giving?.available_years?.length > 0 && (
                <select className="input-field" style={{width:'auto',padding:'0.4rem 0.75rem'}}
                        value={givingYear ?? ''}
                        onChange={e => fetchGiving(e.target.value)}>
                  {giving.available_years.map(y => (
                    <option key={y} value={y}>{y}</option>
                  ))}
                </select>
              )}
              {giving?.total > 0 && (
                <button onClick={handleDownload} disabled={downloading}
                        className="btn-primary px-4 py-2 text-xs gap-1.5">
                  <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                          d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                  </svg>
                  {downloading ? 'Generating...' : 'Statement PDF'}
                </button>
              )}
            </div>
          </div>

          {givingLoad ? (
            <div className="text-center py-8" style={{color:'#9ca3af'}}>Loading giving...</div>
          ) : !giving || giving.total === 0 ? (
            <div className="text-center py-8">
              <div className="text-3xl mb-2">💝</div>
              <div className="text-sm font-semibold" style={{color:'var(--color-navy)'}}>
                No giving recorded for {givingYear}
              </div>
            </div>
          ) : (
            <>
              {/* Total hero */}
              <div className="rounded-xl p-4 mb-4"
                   style={{background:'linear-gradient(135deg, var(--color-navy-deeper), var(--color-navy))'}}>
                <div className="text-xs uppercase tracking-wider" style={{color:'rgba(255,255,255,0.6)'}}>
                  Total Given in {givingYear}
                </div>
                <div className="text-3xl font-bold mt-1" style={{fontFamily:'var(--font-display)',color:'var(--color-gold)'}}>
                  {fmt(giving.total)}
                </div>
              </div>

              {/* By category */}
              <div className="grid grid-cols-2 md:grid-cols-3 gap-3 mb-4">
                {giving.by_category.map(cat => (
                  <div key={cat.category} className="rounded-lg p-3" style={{backgroundColor:'#f9fafb'}}>
                    <div className="text-xs" style={{color:'#9ca3af'}}>{cat.category} ({cat.count})</div>
                    <div className="text-base font-bold" style={{color:'var(--color-navy)'}}>{fmt(cat.total)}</div>
                  </div>
                ))}
              </div>

              {/* Transactions */}
              <div className="overflow-hidden rounded-lg" style={{border:'1px solid var(--color-surface-border)'}}>
                <table className="w-full">
                  <thead>
                    <tr style={{backgroundColor:'#f9fafb'}}>
                      {['Date','Category','Reference','Amount'].map(h => (
                        <th key={h} className="text-left px-3 py-2 text-xs font-semibold uppercase" style={{color:'#6b7280'}}>{h}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {giving.transactions.map((t, i) => (
                      <tr key={t.id} style={{backgroundColor: i % 2 ? '#fafafa' : 'white'}}>
                        <td className="px-3 py-2 text-sm" style={{color:'#374151'}}>{t.date}</td>
                        <td className="px-3 py-2 text-sm" style={{color:'#374151'}}>{t.category}</td>
                        <td className="px-3 py-2 text-sm font-mono" style={{color:'#9ca3af'}}>{t.reference ?? '—'}</td>
                        <td className="px-3 py-2 text-sm font-bold text-right" style={{color:'#15803d'}}>{fmt(t.amount)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </>
          )}
        </div>
      )}
    </div>
  )
}
