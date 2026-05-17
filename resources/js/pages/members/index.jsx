import React, { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { getMembers, deleteMember, getMemberStats } from '../../api/members'

const STATUS_COLORS = {
  active:      { bg: '#dcfce7', text: '#16a34a' },
  inactive:    { bg: '#f3f4f6', text: '#6b7280' },
  transferred: { bg: '#dbeafe', text: '#2563eb' },
  deceased:    { bg: '#fce7f3', text: '#9d174d' },
}

export default function MembersPage() {
  const navigate = useNavigate()
  const [members,    setMembers]    = useState([])
  const [stats,      setStats]      = useState(null)
  const [loading,    setLoading]    = useState(true)
  const [search,     setSearch]     = useState('')
  const [statusFilter, setStatus]   = useState('')
  const [genderFilter, setGender]   = useState('')
  const [page,       setPage]       = useState(1)
  const [meta,       setMeta]       = useState(null)
  const [deleting,   setDeleting]   = useState(null)

  const fetchMembers = useCallback(async () => {
    setLoading(true)
    try {
      const [membersRes, statsRes] = await Promise.all([
        getMembers({ search, status: statusFilter, gender: genderFilter, page, per_page: 15 }),
        getMemberStats(),
      ])
      setMembers(membersRes.data.data)
      setMeta(membersRes.data.meta)
      setStats(statsRes.data.data)
    } catch (err) {
      console.error(err)
    } finally {
      setLoading(false)
    }
  }, [search, statusFilter, genderFilter, page])

  useEffect(() => { fetchMembers() }, [fetchMembers])

  // Debounce search
  useEffect(() => {
    const timer = setTimeout(() => fetchMembers(), 400)
    return () => clearTimeout(timer)
  }, [search])

  const handleDelete = async (member) => {
    if (!confirm(`Delete ${member.full_name}? This cannot be undone.`)) return
    setDeleting(member.id)
    try {
      await deleteMember(member.id)
      fetchMembers()
    } catch (err) {
      alert('Failed to delete member.')
    } finally {
      setDeleting(null)
    }
  }

  return (
    <div className="space-y-6">

      {/* Stats row */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        {[
          { label: 'Total Members',     value: stats?.total        ?? '—' },
          { label: 'Active',            value: stats?.active       ?? '—' },
          { label: 'New This Month',    value: stats?.new_this_month ?? '—' },
          { label: 'Male / Female',     value: stats ? `${stats.male} / ${stats.female}` : '—' },
        ].map(s => (
          <div key={s.label} className="card py-4">
            <div className="text-2xl font-bold" style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
              {s.value}
            </div>
            <div className="text-xs mt-1" style={{color:'#6b7280'}}>{s.label}</div>
          </div>
        ))}
      </div>

      {/* Header + actions */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h2 className="text-xl font-bold" style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
            Members
          </h2>
          <p className="text-sm" style={{color:'#6b7280'}}>
            {meta ? `${meta.total} registered members` : 'Loading...'}
          </p>
        </div>
        <button onClick={() => navigate('/members/new')} className="btn-primary gap-2">
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4"/>
          </svg>
          Add Member
        </button>
      </div>

      {/* Filters */}
      <div className="card py-4">
        <div className="flex flex-col sm:flex-row gap-3">
          <div className="flex-1 relative">
            <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4" style={{color:'#9ca3af'}}
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <input
              type="text"
              placeholder="Search by name, phone, or member number..."
              className="input-field"
              style={{paddingLeft:'2.5rem'}}
              value={search}
              onChange={e => { setSearch(e.target.value); setPage(1) }}
            />
          </div>
          <select className="input-field" style={{width:'auto'}}
                  value={statusFilter} onChange={e => { setStatus(e.target.value); setPage(1) }}>
            <option value="">All Statuses</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
            <option value="transferred">Transferred</option>
            <option value="deceased">Deceased</option>
          </select>
          <select className="input-field" style={{width:'auto'}}
                  value={genderFilter} onChange={e => { setGender(e.target.value); setPage(1) }}>
            <option value="">All Genders</option>
            <option value="male">Male</option>
            <option value="female">Female</option>
          </select>
        </div>
      </div>

      {/* Table */}
      <div className="card p-0 overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead>
              <tr style={{borderBottom:'1px solid var(--color-surface-border)',backgroundColor:'#f9fafb'}}>
                {['Member #', 'Name', 'Gender', 'Phone', 'Status', 'Join Date', 'Actions'].map(h => (
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
                  <td colSpan={7} className="text-center py-12" style={{color:'#9ca3af'}}>
                    <svg className="animate-spin w-6 h-6 mx-auto mb-2" fill="none" viewBox="0 0 24 24">
                      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
                      <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                    Loading members...
                  </td>
                </tr>
              ) : members.length === 0 ? (
                <tr>
                  <td colSpan={7} className="text-center py-12">
                    <div className="text-4xl mb-3">👥</div>
                    <div className="font-semibold" style={{color:'var(--color-navy)'}}>No members found</div>
                    <div className="text-sm mt-1" style={{color:'#9ca3af'}}>
                      {search ? 'Try a different search term' : 'Add your first member to get started'}
                    </div>
                  </td>
                </tr>
              ) : members.map((member, i) => (
                <tr key={member.id}
                    style={{borderBottom:'1px solid var(--color-surface-border)',
                            backgroundColor: i % 2 === 0 ? 'white' : '#fafafa'}}>
                  <td className="px-4 py-3 text-sm font-mono" style={{color:'#6b7280'}}>
                    {member.member_number}
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-3">
                      <div className="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 text-sm font-bold text-white"
                           style={{backgroundColor:'var(--color-navy)'}}>
                        {member.first_name.charAt(0)}{member.last_name.charAt(0)}
                      </div>
                      <div>
                        <div className="text-sm font-semibold" style={{color:'#111827'}}>{member.full_name}</div>
                        {member.email && <div className="text-xs" style={{color:'#9ca3af'}}>{member.email}</div>}
                      </div>
                    </div>
                  </td>
                  <td className="px-4 py-3 text-sm capitalize" style={{color:'#374151'}}>
                    {member.gender}
                  </td>
                  <td className="px-4 py-3 text-sm" style={{color:'#374151'}}>
                    {member.phone ?? '—'}
                  </td>
                  <td className="px-4 py-3">
                    <span className="px-2 py-1 rounded-full text-xs font-semibold capitalize"
                          style={{
                            backgroundColor: STATUS_COLORS[member.status]?.bg,
                            color:           STATUS_COLORS[member.status]?.text,
                          }}>
                      {member.status}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-sm" style={{color:'#6b7280'}}>
                    {member.join_date ?? '—'}
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-2">
                      <button onClick={() => navigate(`/members/${member.id}`)}
                              className="text-xs px-2 py-1 rounded font-medium transition-colors"
                              style={{color:'var(--color-navy)',backgroundColor:'rgba(27,58,107,0.08)'}}>
                        View
                      </button>
                      <button onClick={() => navigate(`/members/${member.id}/edit`)}
                              className="text-xs px-2 py-1 rounded font-medium transition-colors"
                              style={{color:'#d97706',backgroundColor:'rgba(217,119,6,0.08)'}}>
                        Edit
                      </button>
                      <button onClick={() => handleDelete(member)}
                              disabled={deleting === member.id}
                              className="text-xs px-2 py-1 rounded font-medium transition-colors"
                              style={{color:'#dc2626',backgroundColor:'rgba(220,38,38,0.08)'}}>
                        {deleting === member.id ? '...' : 'Delete'}
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {/* Pagination */}
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
