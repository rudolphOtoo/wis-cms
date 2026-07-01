import React, { useState, useEffect, useCallback } from 'react'
import { toast } from 'sonner'
import { useNavigate } from 'react-router-dom'
import {
  Users, UserCheck, UserPlus, Scale, Search, Download,
  CheckCircle2, MinusCircle, ArrowRightCircle, XCircle,
  ChevronLeft, ChevronRight,
} from 'lucide-react'
import { getMembers, deleteMember, getMemberStats, exportMembers } from '../../api/members'
import { usePermission } from '../../hooks/usePermission'
import { useDebounce } from '../../hooks/useDebounce'
import { TableSkeleton } from '../../components/ui/Skeletons'

const STATUS_CONFIG = {
  active:      { bg: '#dcfce7', text: '#166534', icon: CheckCircle2,      label: 'Active' },
  inactive:    { bg: '#e2e8f0', text: '#475569', icon: MinusCircle,        label: 'Inactive' },
  transferred: { bg: '#dbeafe', text: '#1d4ed8', icon: ArrowRightCircle,   label: 'Transferred' },
  deceased:    { bg: '#fce7f3', text: '#9d174d', icon: XCircle,            label: 'Deceased' },
}

function StatIcon({ children }) {
  return (
    <span
      className="flex items-center justify-center rounded-lg p-1.5"
      style={{ color: 'var(--color-navy)', backgroundColor: 'rgba(27,58,107,0.1)' }}
    >
      {children}
    </span>
  )
}

export default function MembersPage() {
  const navigate = useNavigate()
  const { can }  = usePermission()

  const [members,      setMembers]      = useState([])
  const [stats,        setStats]        = useState(null)
  const [loading,      setLoading]      = useState(true)
  const [search,       setSearch]       = useState('')
  const [statusFilter, setStatus]       = useState('')
  const [genderFilter, setGender]       = useState('')
  const [page,         setPage]         = useState(1)
  const [meta,         setMeta]         = useState(null)
  const [deleting,     setDeleting]     = useState(null)
  const [pendingDelete, setPendingDelete] = useState(null)
  const [exporting,    setExporting]    = useState(false)

  // Debounce search: settled value drives the API call dep array to prevent
  // two concurrent requests firing on every keystroke.
  const debouncedSearch = useDebounce(search, 400)

  const handleExport = async () => {
    setExporting(true)
    try {
      const res = await exportMembers({ search, status: statusFilter, gender: genderFilter })
      const url = URL.createObjectURL(new Blob([res.data], { type: 'text/csv' }))
      const a = document.createElement('a')
      a.href = url
      a.download = `members-${new Date().toISOString().split('T')[0]}.csv`
      document.body.appendChild(a)
      a.click()
      a.remove()
      URL.revokeObjectURL(url)
    } catch (err) {
      console.error(err)
      toast.error('Export failed. Please try again.')
    } finally {
      setExporting(false)
    }
  }

  const [refreshKey, setRefreshKey] = useState(0)
  const triggerRefresh = useCallback(() => setRefreshKey(k => k + 1), [])

  // AbortController cleanup prevents stale state updates on unmount / fast navigation.
  useEffect(() => {
    const controller = new AbortController()
    let mounted = true

    const load = async () => {
      setLoading(true)
      try {
        const [membersRes, statsRes] = await Promise.all([
          getMembers(
            { search: debouncedSearch, status: statusFilter, gender: genderFilter, page, per_page: 15 },
            controller.signal,
          ),
          getMemberStats(controller.signal),
        ])
        if (!mounted) return
        setMembers(membersRes.data.data)
        setMeta(membersRes.data.meta)
        setStats(statsRes.data.data)
      } catch (err) {
        if (!mounted || err?.code === 'ERR_CANCELED') return
        console.error(err)
      } finally {
        if (mounted) setLoading(false)
      }
    }

    load()

    return () => {
      mounted = false
      controller.abort()
    }
  }, [debouncedSearch, statusFilter, genderFilter, page, refreshKey])

  const handleDelete = async (member) => {
    setDeleting(member.id)
    try {
      await deleteMember(member.id)
      triggerRefresh()
    } catch {
      toast.error('Failed to delete member.')
    } finally {
      setDeleting(null)
      setPendingDelete(null)
    }
  }

  const total       = stats?.total ?? 0
  const active      = stats?.active ?? 0
  const activePct   = total > 0 ? Math.round((active / total) * 100) : 0
  const totalGender = (stats?.male ?? 0) + (stats?.female ?? 0)
  const malePct     = totalGender > 0 ? Math.round(((stats?.male ?? 0) / totalGender) * 100) : 0
  const femalePct   = 100 - malePct

  return (
    <div className="space-y-6" style={{ maxWidth: '1440px' }}>

      {/* Page header */}
      <div className="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
        <div>
          <h2
            className="font-bold"
            style={{ fontFamily: 'var(--font-display)', fontSize: '32px', lineHeight: '40px', color: 'var(--color-navy)' }}
          >
            Members
          </h2>
          <p style={{ color: '#44474f' }}>
            {meta ? `${meta.total} Total Registered Members` : 'Loading…'}
          </p>
        </div>
        <div className="flex items-center gap-3">
          {can('export members') && (
            <button
              onClick={handleExport}
              disabled={exporting}
              className="btn-secondary"
              style={{ padding: '10px 20px' }}
              aria-label="Export members list as CSV"
            >
              <Download size={16} strokeWidth={2} aria-hidden="true" />
              {exporting ? 'Exporting…' : 'Export CSV'}
            </button>
          )}
          {can('create members') && (
            <button
              onClick={() => navigate('/members/new')}
              className="btn-primary"
              style={{ padding: '10px 24px' }}
            >
              Add Member
              <UserPlus size={16} strokeWidth={2} aria-hidden="true" />
            </button>
          )}
        </div>
      </div>

      {/* Stat cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <div className="surface-card p-6">
          <div className="flex justify-between items-start mb-2">
            <p style={{ fontSize: '14px', fontWeight: 600, color: '#747780' }}>Total Members</p>
            <StatIcon><Users size={18} strokeWidth={1.8} aria-hidden="true" /></StatIcon>
          </div>
          <p style={{ fontFamily: 'var(--font-display)', fontSize: '24px', fontWeight: 600, color: 'var(--color-navy)' }}>
            {total}
          </p>
        </div>

        <div className="surface-card p-6">
          <div className="flex justify-between items-start mb-2">
            <p style={{ fontSize: '14px', fontWeight: 600, color: '#747780' }}>Active</p>
            <StatIcon><UserCheck size={18} strokeWidth={1.8} aria-hidden="true" /></StatIcon>
          </div>
          <p style={{ fontFamily: 'var(--font-display)', fontSize: '24px', fontWeight: 600, color: 'var(--color-navy)' }}>
            {active}
          </p>
          <div className="w-full rounded-full mt-2" style={{ height: '6px', backgroundColor: '#e7e8eb' }}>
            <div className="rounded-full" style={{ height: '6px', width: `${activePct}%`, backgroundColor: 'var(--color-navy)' }} />
          </div>
        </div>

        <div className="surface-card p-6">
          <div className="flex justify-between items-start mb-2">
            <p style={{ fontSize: '14px', fontWeight: 600, color: '#747780' }}>New This Month</p>
            <StatIcon><UserPlus size={18} strokeWidth={1.8} aria-hidden="true" /></StatIcon>
          </div>
          <p style={{ fontFamily: 'var(--font-display)', fontSize: '24px', fontWeight: 600, color: 'var(--color-navy)' }}>
            {stats?.new_this_month ?? '—'}
          </p>
        </div>

        <div className="surface-card p-6">
          <div className="flex justify-between items-start mb-2">
            <p style={{ fontSize: '14px', fontWeight: 600, color: '#747780' }}>Male / Female Split</p>
            <StatIcon><Scale size={18} strokeWidth={1.8} aria-hidden="true" /></StatIcon>
          </div>
          <p style={{ fontFamily: 'var(--font-display)', fontSize: '24px', fontWeight: 600, color: 'var(--color-navy)' }}>
            {malePct}% / {femalePct}%
          </p>
          <div className="flex gap-1 mt-2">
            <div className="rounded-full" style={{ height: '6px', width: `${malePct}%`, backgroundColor: '#1B3A6B' }} />
            <div className="rounded-full" style={{ height: '6px', width: `${femalePct}%`, backgroundColor: '#d7e2ff' }} />
          </div>
        </div>
      </div>

      {/* Filters + Table card */}
      <div className="surface-card overflow-hidden">

        {/* Fluid responsive padding */}
        <div
          className="flex flex-col lg:flex-row gap-4 p-4 md:p-6"
          style={{ borderBottom: '1px solid var(--color-surface-border)' }}
        >
          {/* Lucide Search icon */}
          <div className="relative flex-1">
            <Search
              size={16}
              strokeWidth={2}
              className="absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none"
              style={{ color: '#747780' }}
              aria-hidden="true"
            />
            <input
              type="text"
              placeholder="Search by name, phone, or member number"
              className="input-field"
              style={{ paddingLeft: '2.5rem' }}
              value={search}
              onChange={e => { setSearch(e.target.value); setPage(1) }}
              aria-label="Search members"
            />
          </div>
          <div className="flex gap-2">
            <select
              className="input-field"
              style={{ width: 'auto' }}
              value={statusFilter}
              onChange={e => { setStatus(e.target.value); setPage(1) }}
              aria-label="Filter by membership status"
            >
              <option value="">All Statuses</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
              <option value="transferred">Transferred</option>
              <option value="deceased">Deceased</option>
            </select>
            <select
              className="input-field"
              style={{ width: 'auto' }}
              value={genderFilter}
              onChange={e => { setGender(e.target.value); setPage(1) }}
              aria-label="Filter by gender"
            >
              <option value="">Gender: All</option>
              <option value="male">Male</option>
              <option value="female">Female</option>
            </select>
          </div>
        </div>

        {/* Non-essential columns hidden on small viewports */}
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr style={{ backgroundColor: '#f8f9fc' }}>
                <th
                  className="hidden md:table-cell uppercase tracking-wider"
                  style={{ padding: '16px 24px', fontSize: '12px', fontWeight: 700, color: '#747780' }}
                >
                  Member #
                </th>
                <th
                  className="uppercase tracking-wider"
                  style={{ padding: '16px 24px', fontSize: '12px', fontWeight: 700, color: '#747780' }}
                >
                  Name
                </th>
                <th
                  className="hidden sm:table-cell uppercase tracking-wider"
                  style={{ padding: '16px 24px', fontSize: '12px', fontWeight: 700, color: '#747780' }}
                >
                  Gender
                </th>
                <th
                  className="hidden sm:table-cell uppercase tracking-wider"
                  style={{ padding: '16px 24px', fontSize: '12px', fontWeight: 700, color: '#747780' }}
                >
                  Phone
                </th>
                <th
                  className="uppercase tracking-wider"
                  style={{ padding: '16px 24px', fontSize: '12px', fontWeight: 700, color: '#747780' }}
                >
                  Status
                </th>
                <th
                  className="hidden lg:table-cell uppercase tracking-wider"
                  style={{ padding: '16px 24px', fontSize: '12px', fontWeight: 700, color: '#747780', textAlign: 'center' }}
                >
                  Join Date
                </th>
                <th
                  className="uppercase tracking-wider"
                  style={{ padding: '16px 24px', fontSize: '12px', fontWeight: 700, color: '#747780', textAlign: 'right' }}
                >
                  Actions
                </th>
              </tr>
            </thead>
            <tbody>
              {/* TableSkeleton for loading state */}
              {loading ? (
                <TableSkeleton rows={8} cols={7} hasAvatar={true} />
              ) : members.length === 0 ? (
                <tr>
                  <td colSpan={7}>
                    <div className="flex flex-col items-center justify-center py-16 px-6 text-center">
                      <div
                        className="w-14 h-14 rounded-2xl flex items-center justify-center mb-4"
                        style={{ backgroundColor: 'rgba(27,58,107,0.08)' }}
                      >
                        <Users size={26} strokeWidth={1.5} style={{ color: 'var(--color-navy)' }} aria-hidden="true" />
                      </div>
                      <p className="font-semibold text-base mb-1" style={{ color: 'var(--color-navy)' }}>
                        {search ? 'No members found' : 'No members yet'}
                      </p>
                      <p className="text-sm mb-5 max-w-xs" style={{ color: '#747780' }}>
                        {search
                          ? `No results for "${search}". Try a different name, phone, or member number.`
                          : 'Add your first member to start building your congregation register.'}
                      </p>
                      {!search && can('create members') && (
                        <button
                          onClick={() => navigate('/members/new')}
                          className="btn-primary"
                          style={{ padding: '10px 24px' }}
                        >
                          Add First Member
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              ) : members.map((member) => {
                const cfg = STATUS_CONFIG[member.status] ?? STATUS_CONFIG.inactive
                const StatusIcon = cfg.icon
                return (
                  /* Replace onMouseEnter/Leave with Tailwind hover utility */
                  <tr
                    key={member.id}
                    className="hover:bg-slate-50 transition-colors duration-150"
                    style={{ borderTop: '1px solid var(--color-surface-border)' }}
                  >
                    <td
                      className="hidden md:table-cell"
                      style={{ padding: '16px 24px', fontSize: '13px', fontFamily: 'monospace', color: '#44474f' }}
                    >
                      {member.member_number}
                    </td>
                    <td style={{ padding: '16px 24px' }}>
                      <div className="flex items-center gap-3">
                        <div
                          className="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0 text-sm font-bold text-white"
                          style={{ backgroundColor: 'var(--color-navy)' }}
                          aria-hidden="true"
                        >
                          {member.first_name.charAt(0)}{member.last_name.charAt(0)}
                        </div>
                        <div>
                          <p className="font-bold" style={{ color: 'var(--color-navy)' }}>{member.full_name}</p>
                          {member.email && (
                            <p style={{ fontSize: '12px', color: '#747780' }}>{member.email}</p>
                          )}
                        </div>
                      </div>
                    </td>
                    <td
                      className="hidden sm:table-cell capitalize"
                      style={{ padding: '16px 24px', fontSize: '14px', color: '#44474f' }}
                    >
                      {member.gender}
                    </td>
                    <td
                      className="hidden sm:table-cell"
                      style={{ padding: '16px 24px', fontSize: '14px', color: '#44474f' }}
                    >
                      {member.phone ?? '—'}
                    </td>
                    <td style={{ padding: '16px 24px' }}>
                      {/* Icon + text badge */}
                      <span
                        className="inline-flex items-center gap-1.5 rounded-full text-xs font-bold"
                        style={{ padding: '4px 10px', backgroundColor: cfg.bg, color: cfg.text }}
                        aria-label={`Status: ${cfg.label}`}
                      >
                        <StatusIcon size={11} strokeWidth={2.5} aria-hidden="true" />
                        {cfg.label}
                      </span>
                    </td>
                    <td
                      className="hidden lg:table-cell"
                      style={{ padding: '16px 24px', fontSize: '14px', color: '#44474f', textAlign: 'center' }}
                    >
                      {member.join_date ?? '—'}
                    </td>
                    {/* Inline confirm with 44px touch targets */}
                    <td style={{ padding: '12px 24px' }}>
                      <div className="flex justify-end items-center gap-1">
                        <button
                          onClick={() => navigate(`/members/${member.id}`)}
                          className="inline-flex items-center justify-center min-h-[44px] min-w-[44px] px-3 rounded-lg text-sm font-semibold transition-colors hover:bg-slate-100"
                          style={{ color: 'var(--color-navy)' }}
                          aria-label={`View profile of ${member.full_name}`}
                        >
                          View
                        </button>
                        {can('edit members') && (
                          <button
                            onClick={() => navigate(`/members/${member.id}/edit`)}
                            className="inline-flex items-center justify-center min-h-[44px] min-w-[44px] px-3 rounded-lg text-sm font-semibold transition-colors hover:bg-amber-50"
                            style={{ color: '#92400e' }}
                            aria-label={`Edit ${member.full_name}`}
                          >
                            Edit
                          </button>
                        )}
                        {can('delete members') && (
                          pendingDelete === member.id ? (
                            <div className="flex items-center gap-1">
                              <button
                                onClick={() => handleDelete(member)}
                                disabled={deleting === member.id}
                              className="inline-flex items-center justify-center min-h-[44px] min-w-[44px] px-3 rounded-lg text-xs font-bold transition-colors bg-red-50 hover:bg-red-100 disabled:opacity-40"
                              style={{ color: '#be123c' }}
                              aria-label={`Confirm deletion of ${member.full_name}`}
                            >
                              {deleting === member.id ? '…' : 'Confirm'}
                            </button>
                            <button
                              onClick={() => setPendingDelete(null)}
                              className="inline-flex items-center justify-center min-h-[44px] min-w-[44px] px-2 rounded-lg text-xs font-semibold transition-colors hover:bg-slate-100"
                                style={{ color: '#747780' }}
                                aria-label="Cancel deletion"
                              >
                                Cancel
                              </button>
                            </div>
                          ) : (
                            <button
                              onClick={() => setPendingDelete(member.id)}
                              className="inline-flex items-center justify-center min-h-[44px] min-w-[44px] px-3 rounded-lg text-sm font-semibold transition-colors hover:bg-red-50"
                              style={{ color: '#be123c' }}
                              aria-label={`Delete ${member.full_name}`}
                            >
                              Delete
                            </button>
                          )
                        )}
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>

        {/* Pagination */}
        {meta && meta.last_page > 1 && (
          <div
            className="flex flex-col md:flex-row justify-between items-center gap-4 p-4 md:p-6"
            style={{ backgroundColor: '#f8f9fc', borderTop: '1px solid var(--color-surface-border)' }}
          >
            <p style={{ fontSize: '14px', color: '#44474f' }}>
              Page{' '}
              <span className="font-bold" style={{ color: 'var(--color-navy)' }}>{meta.current_page}</span>
              {' '}of{' '}
              <span className="font-bold" style={{ color: 'var(--color-navy)' }}>{meta.last_page}</span>
              {' '}· {meta.total} members
            </p>
            <div className="flex items-center gap-2" role="navigation" aria-label="Pagination">
              <button
                disabled={page === 1}
                onClick={() => setPage(p => p - 1)}
                className="w-11 h-11 rounded-lg flex items-center justify-center transition-colors hover:bg-white disabled:opacity-40 disabled:cursor-not-allowed"
                style={{ border: '1px solid var(--color-surface-border)', color: 'var(--color-navy)' }}
                aria-label="Go to previous page"
              >
                <ChevronLeft size={18} strokeWidth={2} aria-hidden="true" />
              </button>
              <span
                className="w-10 h-10 rounded-lg flex items-center justify-center font-bold text-sm text-white"
                style={{ backgroundColor: 'var(--color-navy)' }}
                aria-current="page"
                aria-label={`Current page, page ${meta.current_page}`}
              >
                {meta.current_page}
              </span>
              <button
                disabled={page === meta.last_page}
                onClick={() => setPage(p => p + 1)}
                className="w-11 h-11 rounded-lg flex items-center justify-center transition-colors hover:bg-white disabled:opacity-40 disabled:cursor-not-allowed"
                style={{ border: '1px solid var(--color-surface-border)', color: 'var(--color-navy)' }}
                aria-label="Go to next page"
              >
                <ChevronRight size={18} strokeWidth={2} aria-hidden="true" />
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
