import { useEffect, useState, useCallback } from 'react'
import { toast } from 'sonner'
import {
  getSubmissions,
  getSubmission,
  approveSubmission,
  rejectSubmission,
} from '../../api/submissions'
import { useConfirm } from '../../hooks/useConfirm'

function StatusBadge({ status }) {
  const colors = {
    pending: { bg: '#fef3c7', fg: '#92400e' },
    approved: { bg: '#d1fae5', fg: '#065f46' },
    rejected: { bg: '#fee2e2', fg: '#991b1b' },
  }
  const c = colors[status] ?? { bg: '#f3f4f6', fg: '#4b5563' }
  return (
    <span className="text-xs px-2 py-0.5 rounded-full"
      style={{ backgroundColor: c.bg, color: c.fg, fontWeight: 600 }}>
      {status}
    </span>
  )
}

function FilterTabs({ status, setStatus, pendingCount }) {
  const tabs = [
    { key: 'pending', label: 'Pending', count: pendingCount },
    { key: 'approved', label: 'Approved' },
    { key: 'rejected', label: 'Rejected' },
    { key: 'all', label: 'All' },
  ]
  return (
    <div className="flex gap-2 mb-4">
      {tabs.map(t => (
        <button key={t.key}
          type="button"
          onClick={() => setStatus(t.key)}
          className="px-4 py-2 rounded-lg text-sm font-medium inline-flex items-center gap-2"
          style={{
            border: '1px solid var(--color-surface-border)',
            backgroundColor: status === t.key ? 'var(--color-navy)' : 'white',
            color: status === t.key ? 'white' : 'var(--color-navy)',
          }}>
          {t.label}
          {t.count > 0 && t.key === 'pending' && status !== t.key && (
            <span className="px-1.5 py-0.5 rounded-full text-xs"
              style={{ backgroundColor: '#dc2626', color: 'white' }}>
              {t.count}
            </span>
          )}
        </button>
      ))}
    </div>
  )
}

function SubmissionRow({ submission, onClick }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="w-full text-left p-4 rounded-lg transition-colors"
      style={{
        backgroundColor: 'white',
        border: '1px solid var(--color-surface-border)',
        marginBottom: '8px',
        cursor: 'pointer',
      }}
      onMouseEnter={e => e.currentTarget.style.backgroundColor = '#f8f9fa'}
      onMouseLeave={e => e.currentTarget.style.backgroundColor = 'white'}>
      <div className="flex items-start justify-between gap-3">
        <div className="flex-1">
          <div className="font-bold" style={{ color: 'var(--color-navy)' }}>
            {submission.full_name}
          </div>
          <div className="text-sm mt-1" style={{ color: '#6b7280' }}>
            {submission.phone}
            {submission.cell_name_submitted && (
              <span> • requested {submission.cell_name_submitted}</span>
            )}
          </div>
          <div className="text-xs mt-1" style={{ color: '#9ca3af' }}>
            Submitted {new Date(submission.submitted_at).toLocaleString()}
          </div>
          {submission.duplicate_member && (
            <div className="text-xs mt-2 px-2 py-1 rounded inline-block"
              style={{ backgroundColor: '#fef3c7', color: '#92400e' }}>
              ⚠ Phone already exists: {submission.duplicate_member.name}
            </div>
          )}
        </div>
        <StatusBadge status={submission.status} />
      </div>
    </button>
  )
}

function DetailDrawer({ submissionId, onClose, onActionComplete }) {
  const { confirm, dialog } = useConfirm()
  const [detail, setDetail] = useState(null)
  const [cells, setCells] = useState([])
  const [loading, setLoading] = useState(true)
  const [cellId, setCellId] = useState('')
  const [notes, setNotes] = useState('')
  const [acting, setActing] = useState(false)
  const [showRaw, setShowRaw] = useState(false)

  useEffect(() => {
    if (!submissionId) return
    setLoading(true)
    getSubmission(submissionId)
      .then(res => {
        setDetail(res.data.data)
        setCells(res.data.cells ?? [])

        // Pre-fill cell selection if we can match the submitted cell_name
        const submittedName = res.data.data.cell_name_submitted
        if (submittedName) {
          const match = (res.data.cells ?? []).find(c =>
            c.name.toLowerCase().includes(submittedName.toLowerCase()) ||
            submittedName.toLowerCase().includes(c.name.toLowerCase())
          )
          if (match) setCellId(match.id)
        }
      })
      .catch(err => console.error(err))
      .finally(() => setLoading(false))
  }, [submissionId])

  const handleApprove = async () => {
    setActing(true)
    try {
      await approveSubmission(submissionId, {
        cell_id: cellId || null,
        notes: notes || null,
      })
      onActionComplete?.()
      onClose()
    } catch (err) {
      toast.error(err.response?.data?.message ?? 'Could not approve.')
    } finally {
      setActing(false)
    }
  }

  const handleReject = async () => {
    if (!(await confirm('Reject this submission? This cannot be undone.'))) return
    setActing(true)
    try {
      await rejectSubmission(submissionId, { notes: notes || null })
      onActionComplete?.()
      onClose()
    } catch (err) {
      toast.error(err.response?.data?.message ?? 'Could not reject.')
    } finally {
      setActing(false)
    }
  }

  if (!submissionId) return null

  const fieldStyle = {
    display: 'flex',
    justifyContent: 'space-between',
    padding: '8px 0',
    borderBottom: '1px solid #f3f4f6',
    fontSize: '14px',
  }

  return (
    <>
      {/* Backdrop */}
      <div onClick={onClose}
        style={{
          position: 'fixed', top: 0, left: 0, right: 0, bottom: 0,
          backgroundColor: 'rgba(0,0,0,0.4)', zIndex: 40,
        }} />

      {/* Drawer */}
      <div style={{
        position: 'fixed', top: 0, right: 0, bottom: 0,
        width: '480px', maxWidth: '90vw',
        backgroundColor: 'white',
        boxShadow: '-4px 0 16px rgba(0,0,0,0.1)',
        zIndex: 50,
        overflowY: 'auto',
        padding: '24px',
      }}>
        <div className="flex items-center justify-between mb-4">
          <h2 className="font-bold text-xl" style={{ color: 'var(--color-navy)', fontFamily: 'var(--font-display)' }}>
            Submission Detail
          </h2>
          <button type="button" onClick={onClose}
            className="text-2xl px-2" style={{ color: '#6b7280', cursor: 'pointer' }}>
            ×
          </button>
        </div>

        {loading && <div className="text-sm" style={{ color: '#6b7280' }}>Loading...</div>}

        {!loading && detail && (
          <>
            <div className="mb-4">
              <StatusBadge status={detail.status} />
              {detail.duplicate_member && (
                <div className="mt-2 p-2 rounded text-sm"
                  style={{ backgroundColor: '#fef3c7', color: '#92400e' }}>
                  ⚠ A member with this phone already exists: <strong>{detail.duplicate_member.name}</strong>. 
                  Approving will UPDATE that member with the submitted info.
                </div>
              )}
            </div>

            <div className="mb-4">
              <h3 className="font-bold text-sm mb-2" style={{ color: 'var(--color-navy)' }}>
                Personal Info
              </h3>
              <div style={fieldStyle}><span style={{ color: '#6b7280' }}>Name</span><span>{detail.full_name}</span></div>
              <div style={fieldStyle}><span style={{ color: '#6b7280' }}>Phone</span><span>{detail.phone}</span></div>
              {detail.email && <div style={fieldStyle}><span style={{ color: '#6b7280' }}>Email</span><span>{detail.email}</span></div>}
              {detail.gender && <div style={fieldStyle}><span style={{ color: '#6b7280' }}>Gender</span><span>{detail.gender}</span></div>}
              {detail.date_of_birth && <div style={fieldStyle}><span style={{ color: '#6b7280' }}>DOB</span><span>{detail.date_of_birth}</span></div>}
              {detail.marital_status && <div style={fieldStyle}><span style={{ color: '#6b7280' }}>Marital</span><span>{detail.marital_status}</span></div>}
              {detail.occupation && <div style={fieldStyle}><span style={{ color: '#6b7280' }}>Occupation</span><span>{detail.occupation}</span></div>}
              {detail.address && <div style={fieldStyle}><span style={{ color: '#6b7280' }}>Address</span><span>{detail.address}</span></div>}
              {detail.cell_name_submitted && <div style={fieldStyle}><span style={{ color: '#6b7280' }}>Requested cell</span><span>{detail.cell_name_submitted}</span></div>}
            </div>

            {detail.status === 'pending' && (
              <div className="mb-4 p-4 rounded-lg" style={{ backgroundColor: '#f8f9fa', border: '1px solid var(--color-surface-border)' }}>
                <h3 className="font-bold text-sm mb-2" style={{ color: 'var(--color-navy)' }}>
                  Approve into Member
                </h3>

                <label className="text-xs font-bold uppercase tracking-wider" style={{ color: '#44474f' }}>
                  Assign cell (optional)
                </label>
                <select value={cellId} onChange={e => setCellId(e.target.value)}
                  className="w-full mt-1 mb-3 px-3 py-2 rounded-lg"
                  style={{ border: '1px solid var(--color-surface-border)' }}>
                  <option value="">— No cell assignment yet —</option>
                  {cells.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>

                <label className="text-xs font-bold uppercase tracking-wider" style={{ color: '#44474f' }}>
                  Notes (optional)
                </label>
                <textarea value={notes} onChange={e => setNotes(e.target.value)}
                  rows={2}
                  placeholder="e.g. 'Confirmed by Pastor John'"
                  className="w-full mt-1 px-3 py-2 rounded-lg text-sm"
                  style={{ border: '1px solid var(--color-surface-border)' }} />

                <div className="flex gap-2 mt-4">
                  <button type="button" onClick={handleApprove} disabled={acting}
                    className="btn-primary px-4 py-2 flex-1">
                    {acting ? 'Working...' : 'Approve → Create Member'}
                  </button>
                  <button type="button" onClick={handleReject} disabled={acting}
                    className="px-4 py-2 rounded-lg"
                    style={{
                      border: '1px solid #dc2626',
                      color: '#dc2626',
                      backgroundColor: 'white',
                    }}>
                    Reject
                  </button>
                </div>
              </div>
            )}

            {detail.status !== 'pending' && (
              <div className="mb-4 p-3 rounded-lg" style={{ backgroundColor: '#f8f9fa', border: '1px solid var(--color-surface-border)' }}>
                <div className="text-sm" style={{ color: '#6b7280' }}>
                  {detail.status === 'approved' ? 'Approved' : 'Rejected'} on{' '}
                  {detail.reviewed_at && new Date(detail.reviewed_at).toLocaleString()}
                  {detail.reviewed_by && <> by <strong>{detail.reviewed_by.name}</strong></>}
                </div>
                {detail.review_notes && (
                  <div className="text-sm mt-2 italic" style={{ color: '#6b7280' }}>
                    "{detail.review_notes}"
                  </div>
                )}
                {detail.approved_member && (
                  <div className="text-sm mt-2" style={{ color: 'var(--color-navy)' }}>
                    → Member: <strong>{detail.approved_member.name}</strong>
                  </div>
                )}
              </div>
            )}

            <div className="mt-4">
              <button type="button" onClick={() => setShowRaw(!showRaw)}
                className="text-xs" style={{ color: '#6b7280', cursor: 'pointer' }}>
                {showRaw ? '▼' : '▶'} View raw form payload + metadata
              </button>
              {showRaw && (
                <pre className="mt-2 p-3 rounded text-xs overflow-x-auto"
                  style={{ backgroundColor: '#f3f4f6', color: '#374151' }}>
{JSON.stringify({
  source_ip: detail.source_ip,
  submitted_at: detail.submitted_at,
  raw_payload: detail.raw_payload,
}, null, 2)}
                </pre>
              )}
            </div>
          </>
        )}
      </div>
      {dialog}
    </>
  )
}

export default function Submissions() {
  const [submissions, setSubmissions] = useState([])
  const [meta, setMeta] = useState({ pending_count: 0, total: 0 })
  const [status, setStatus] = useState('pending')
  const [loading, setLoading] = useState(true)
  const [selectedId, setSelectedId] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await getSubmissions({ status })
      setSubmissions(res.data.data)
      setMeta(res.data.meta)
    } catch (err) {
      console.error(err)
    } finally {
      setLoading(false)
    }
  }, [status])

  useEffect(() => { load() }, [load])

  return (
    <div className="max-w-5xl mx-auto">
      <div className="mb-4">
        <h1 className="font-bold" style={{ fontFamily: 'var(--font-display)', fontSize: '32px', color: 'var(--color-navy)' }}>
          Member Submissions
        </h1>
        <p className="mt-1" style={{ color: '#44474f' }}>
          Review new sign-ups from the Google Form. Approved submissions become real Members; rejected ones are dismissed.
        </p>
      </div>

      <FilterTabs status={status} setStatus={setStatus} pendingCount={meta.pending_count ?? 0} />

      {loading && (
        <div className="flex items-center justify-center py-12">
          <svg className="animate-spin w-8 h-8" style={{ color: 'var(--color-navy)' }} fill="none" viewBox="0 0 24 24">
            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
          </svg>
        </div>
      )}

      {!loading && submissions.length === 0 && (
        <div className="text-center py-12" style={{ color: '#6b7280' }}>
          No {status === 'all' ? '' : status} submissions yet.
        </div>
      )}

      {!loading && submissions.length > 0 && (
        <div>
          {submissions.map(s => (
            <SubmissionRow key={s.id} submission={s}
              onClick={() => setSelectedId(s.id)} />
          ))}
          <div className="text-sm mt-4 text-center" style={{ color: '#6b7280' }}>
            Showing {submissions.length} of {meta.total}
          </div>
        </div>
      )}

      <DetailDrawer submissionId={selectedId}
        onClose={() => setSelectedId(null)}
        onActionComplete={load} />
    </div>
  )
}
