import { useState, useEffect, useCallback } from 'react'
import { toast } from 'sonner'
import { useNavigate } from 'react-router-dom'
import { BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid, Legend } from 'recharts'
import {
  TrendingUp, TrendingDown, Wallet, Search, Download, Plus,
  FileDown, ArrowUpCircle, ArrowDownCircle, ChevronLeft, ChevronRight,
} from 'lucide-react'
import { getTransactions, getFinanceStats, getFinanceCategories, deleteTransaction, exportTransactions, downloadLedgerPdf } from '../../api/finance'
import { usePermission } from '../../hooks/usePermission'
import { useDebounce } from '../../hooks/useDebounce'
import { TableSkeleton } from '../../components/ui/Skeletons'

const fmt = (n) => `GHS ${Number(n).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
const fmtShort = (n) => {
  if (n >= 1_000_000) return `${(n / 1_000_000).toFixed(1)}M`
  if (n >= 1_000)     return `${(n / 1_000).toFixed(1)}K`
  return n
}

export default function FinancePage() {
  const navigate = useNavigate()
  const { can }  = usePermission()

  const [transactions, setTxns]      = useState([])
  const [categories,   setCats]      = useState([])
  const [stats,        setStats]     = useState(null)
  const [loading,      setLoading]   = useState(true)
  const [search,       setSearch]    = useState('')
  const [typeFilter,   setType]      = useState('')
  const [catFilter,    setCat]       = useState('')
  const [page,         setPage]      = useState(1)
  const [meta,         setMeta]      = useState(null)
  const [deleting,     setDel]       = useState(null)
  // Fix #4 — inline confirm state replaces window.confirm()
  const [pendingDelete, setPendingDelete] = useState(null)
  const [exporting,    setExporting] = useState(false)
  const [generating,   setGenerating] = useState(false)

  const today        = () => new Date().toISOString().split('T')[0]
  const firstOfMonth = (d = new Date()) => new Date(d.getFullYear(), d.getMonth(), 1).toISOString().split('T')[0]
  const lastOfMonth  = (d) => new Date(d.getFullYear(), d.getMonth() + 1, 0).toISOString().split('T')[0]

  const [reportFrom, setReportFrom] = useState(firstOfMonth())
  const [reportTo,   setReportTo]   = useState(today())

  const applyPreset = (kind) => {
    const now = new Date()
    if (kind === 'this-month') {
      setReportFrom(firstOfMonth()); setReportTo(today())
    } else if (kind === 'last-month') {
      const last = new Date(now.getFullYear(), now.getMonth() - 1, 1)
      setReportFrom(firstOfMonth(last)); setReportTo(lastOfMonth(last))
    } else if (kind === 'this-quarter') {
      const q = Math.floor(now.getMonth() / 3)
      setReportFrom(new Date(now.getFullYear(), q * 3, 1).toISOString().split('T')[0])
      setReportTo(today())
    }
  }

  const handleDownloadLedger = async () => {
    if (!reportFrom || !reportTo) return
    setGenerating(true)
    try {
      const res = await downloadLedgerPdf({ from: reportFrom, to: reportTo })
      const url = URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }))
      const a = document.createElement('a')
      a.href = url
      a.download = `financial-ledger-${reportFrom}-to-${reportTo}.pdf`
      document.body.appendChild(a); a.click(); a.remove()
      URL.revokeObjectURL(url)
    } catch (err) {
      console.error(err)
      toast.error('Could not generate the report. Please check the date range and try again.')
    } finally {
      setGenerating(false)
    }
  }

  const handleExport = async () => {
    setExporting(true)
    try {
      const res = await exportTransactions({ search, type: typeFilter, category_id: catFilter })
      const url = URL.createObjectURL(new Blob([res.data], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }))
      const a = document.createElement('a')
      a.href = url
      a.download = `transactions-${new Date().toISOString().split('T')[0]}.xlsx`
      document.body.appendChild(a); a.click(); a.remove()
      URL.revokeObjectURL(url)
    } catch (err) {
      console.error(err)
      toast.error('Export failed. Please try again.')
    } finally {
      setExporting(false)
    }
  }

  const debouncedSearch = useDebounce(search, 400)

  const [refreshKey, setRefreshKey] = useState(0)
  const triggerRefresh = useCallback(() => setRefreshKey(k => k + 1), [])

  useEffect(() => {
    const controller = new AbortController()
    let mounted = true

    const load = async () => {
      setLoading(true)
      try {
        const [tRes, sRes, cRes] = await Promise.all([
          getTransactions(
            { search: debouncedSearch, type: typeFilter, category_id: catFilter, page, per_page: 15 },
            controller.signal,
          ),
          getFinanceStats(controller.signal),
          getFinanceCategories(undefined, controller.signal),
        ])
        if (!mounted) return
        setTxns(tRes.data.data)
        setMeta(tRes.data.meta)
        setStats(sRes.data.data)
        setCats(cRes.data.data)
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
  }, [debouncedSearch, typeFilter, catFilter, page, refreshKey])

  // Fix #4 — no window.confirm(); caller sets pendingDelete, this fires on Confirm click
  const handleDelete = async (txn) => {
    setDel(txn.id)
    try {
      await deleteTransaction(txn.id)
      triggerRefresh()
    } catch {
      toast.error('Failed to delete transaction.')
    } finally {
      setDel(null)
      setPendingDelete(null)
    }
  }

  const chart = stats?.chart ?? []

  // Fix #8 — Lucide icons replace the custom inline Icon/ICONS system
  const summaryCards = [
    {
      label: 'This Month — Income',
      value: stats?.this_month_income,
      icon: TrendingUp,
      gradient: 'linear-gradient(135deg,#059669,#065f46)',
      figureColor: 'white',
    },
    {
      label: 'This Month — Expenses',
      value: stats?.this_month_expenses,
      icon: TrendingDown,
      gradient: 'linear-gradient(135deg,#e11d48,#9f1239)',
      figureColor: 'white',
    },
    {
      label: 'This Month — Balance',
      value: stats?.this_month_balance,
      icon: Wallet,
      gradient: 'linear-gradient(135deg,#002452,#003c8f)',
      figureColor: 'var(--color-gold-light)',
      goldIcon: true,
    },
  ]

  return (
    <div className="space-y-6" style={{ maxWidth: '1440px' }}>

      {/* Summary cards */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        {summaryCards.map(c => {
          const CardIcon = c.icon
          return (
            <div
              key={c.label}
              className="relative overflow-hidden flex flex-col justify-between text-white"
              style={{ borderRadius: '16px', padding: '24px', minHeight: '160px', background: c.gradient, boxShadow: '0 4px 12px rgba(13,31,60,0.05)' }}
            >
              {/* Ghost icon decoration — Fix #8 Lucide at large size */}
              <div className="absolute pointer-events-none" style={{ right: '-16px', bottom: '-16px', opacity: 0.1 }}>
                <CardIcon size={120} strokeWidth={0.8} aria-hidden="true" />
              </div>
              <div className="flex justify-between items-start relative z-10">
                <span style={{ fontSize: '14px', fontWeight: 600, opacity: 0.85 }}>{c.label}</span>
                <div
                  className="rounded-lg p-2"
                  style={{ backgroundColor: c.goldIcon ? 'var(--color-gold)' : 'rgba(255,255,255,0.2)' }}
                >
                  <CardIcon size={20} strokeWidth={1.8} aria-hidden="true" />
                </div>
              </div>
              <div className="relative z-10">
                <h3 style={{ fontFamily: 'var(--font-display)', fontSize: '30px', fontWeight: 700, letterSpacing: '-0.01em', color: c.figureColor }}>
                  {stats ? fmt(c.value) : '—'}
                </h3>
              </div>
            </div>
          )
        })}
      </div>

      {/* Financial Report card — Fix #5 surface-card replaces inline cardBase */}
      {can('export finance') && (
        <div className="surface-card p-4 md:p-6">
          <div className="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
            <div>
              <h3 style={{ fontFamily: 'var(--font-display)', fontSize: '24px', fontWeight: 600, color: 'var(--color-navy)' }}>
                Financial Report
              </h3>
              <p style={{ fontSize: '14px', color: '#747780', marginTop: '4px' }}>
                Generate a PDF income &amp; expense ledger for any date range.
              </p>
            </div>
            <div className="flex flex-wrap items-end gap-3">
              <div>
                <label
                  htmlFor="report-from"
                  style={{ display: 'block', fontSize: '11px', fontWeight: 700, color: '#747780', textTransform: 'uppercase', letterSpacing: '0.04em', marginBottom: '4px' }}
                >
                  From
                </label>
                <input
                  id="report-from"
                  type="date"
                  value={reportFrom}
                  onChange={e => setReportFrom(e.target.value)}
                  className="input-field"
                  style={{ width: 'auto' }}
                />
              </div>
              <div>
                <label
                  htmlFor="report-to"
                  style={{ display: 'block', fontSize: '11px', fontWeight: 700, color: '#747780', textTransform: 'uppercase', letterSpacing: '0.04em', marginBottom: '4px' }}
                >
                  To
                </label>
                <input
                  id="report-to"
                  type="date"
                  value={reportTo}
                  onChange={e => setReportTo(e.target.value)}
                  className="input-field"
                  style={{ width: 'auto' }}
                />
              </div>
              <button
                onClick={handleDownloadLedger}
                disabled={generating || !reportFrom || !reportTo}
                className="btn-primary"
                style={{ padding: '10px 20px', opacity: generating ? 0.6 : 1 }}
                aria-label="Download financial ledger as PDF"
              >
                <FileDown size={16} strokeWidth={2} aria-hidden="true" />
                {generating ? 'Generating…' : 'Download PDF'}
              </button>
            </div>
          </div>
          <div
            className="flex flex-wrap gap-2 mt-4 pt-4"
            style={{ borderTop: '1px solid var(--color-surface-border)' }}
          >
            <span style={{ fontSize: '11px', fontWeight: 600, color: '#747780', textTransform: 'uppercase', letterSpacing: '0.04em', alignSelf: 'center' }}>
              Quick:
            </span>
            {[
              { label: 'This Month',   kind: 'this-month' },
              { label: 'Last Month',   kind: 'last-month' },
              { label: 'This Quarter', kind: 'this-quarter' },
            ].map(p => (
              <button
                key={p.kind}
                type="button"
                onClick={() => applyPreset(p.kind)}
                className="transition-colors hover:bg-slate-200"
                style={{ padding: '6px 14px', backgroundColor: '#f2f3f6', color: 'var(--color-navy)', border: '1px solid var(--color-surface-border)', borderRadius: '999px', fontSize: '12px', fontWeight: 600 }}
              >
                {p.label}
              </button>
            ))}
          </div>
        </div>
      )}

      {/* Transactions card — Fix #5 surface-card */}
      <div className="surface-card overflow-hidden">

        {/* Header — Fix #6 fluid responsive padding */}
        <div
          className="flex flex-col md:flex-row md:items-center justify-between gap-4 p-4 md:p-6"
          style={{ borderBottom: '1px solid var(--color-surface-border)' }}
        >
          <div>
            <h3 style={{ fontFamily: 'var(--font-display)', fontSize: '24px', fontWeight: 600, color: 'var(--color-navy)' }}>
              Transactions
            </h3>
            <p style={{ fontSize: '14px', color: '#747780' }}>
              {meta ? `${meta.total} total recorded` : 'Loading…'}
            </p>
          </div>
          <div className="flex items-center gap-3">
            {can('export finance') && (
              <button
                onClick={handleExport}
                disabled={exporting}
                className="btn-secondary"
                style={{ padding: '10px 20px', opacity: exporting ? 0.6 : 1 }}
                aria-label="Export transactions as Excel"
              >
                <Download size={16} strokeWidth={2} aria-hidden="true" />
                {exporting ? 'Exporting…' : 'Export Excel'}
              </button>
            )}
            {can('create transactions') && (
              <button
                onClick={() => navigate('/finance/new')}
                className="btn-primary"
                style={{ padding: '10px 24px' }}
              >
                <Plus size={16} strokeWidth={2.5} aria-hidden="true" />
                Record Transaction
              </button>
            )}
          </div>
        </div>

        {/* Filter bar — Fix #6 fluid padding */}
        <div
          className="grid grid-cols-1 md:grid-cols-3 gap-4 p-4 md:p-6"
          style={{ borderBottom: '1px solid var(--color-surface-border)', backgroundColor: '#fafbfc' }}
        >
          <div className="relative">
            <Search size={16} strokeWidth={2} className="absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" style={{ color: '#747780' }} aria-hidden="true" />
            <input
              type="text"
              placeholder="Search reference, notes, or member"
              className="input-field"
              style={{ paddingLeft: '2.5rem' }}
              value={search}
              onChange={e => { setSearch(e.target.value); setPage(1) }}
              aria-label="Search transactions"
            />
          </div>
          <select
            className="input-field"
            value={typeFilter}
            onChange={e => { setType(e.target.value); setPage(1) }}
            aria-label="Filter by transaction type"
          >
            <option value="">All Types</option>
            <option value="income">Income</option>
            <option value="expense">Expense</option>
          </select>
          <select
            className="input-field"
            value={catFilter}
            onChange={e => { setCat(e.target.value); setPage(1) }}
            aria-label="Filter by category"
          >
            <option value="">All Categories</option>
            {categories.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        </div>

        {/* Fix #3 — non-essential columns hidden on mobile */}
        <div className="overflow-x-auto">
          <table className="w-full text-left">
            <thead>
              <tr style={{ backgroundColor: '#f2f3f6' }}>
                <th className="uppercase tracking-wider" style={{ padding: '12px 24px', fontSize: '12px', fontWeight: 700, color: '#747780' }}>Date</th>
                <th className="uppercase tracking-wider" style={{ padding: '12px 24px', fontSize: '12px', fontWeight: 700, color: '#747780' }}>Category</th>
                <th className="hidden sm:table-cell uppercase tracking-wider" style={{ padding: '12px 24px', fontSize: '12px', fontWeight: 700, color: '#747780' }}>Member</th>
                <th className="uppercase tracking-wider" style={{ padding: '12px 24px', fontSize: '12px', fontWeight: 700, color: '#747780' }}>Amount</th>
                <th className="hidden md:table-cell uppercase tracking-wider" style={{ padding: '12px 24px', fontSize: '12px', fontWeight: 700, color: '#747780' }}>Reference</th>
                <th className="uppercase tracking-wider" style={{ padding: '12px 24px', fontSize: '12px', fontWeight: 700, color: '#747780', textAlign: 'right' }}>Actions</th>
              </tr>
            </thead>
            <tbody>
              {/* Fix #2 — TableSkeleton replaces the plain "Loading…" text cell */}
              {loading ? (
                <TableSkeleton rows={8} cols={6} />
              ) : transactions.length === 0 ? (
                <tr>
                  <td colSpan={6}>
                    <div className="flex flex-col items-center justify-center py-16 px-6 text-center">
                      <div
                        className="w-14 h-14 rounded-2xl flex items-center justify-center mb-4"
                        style={{ backgroundColor: 'rgba(27,58,107,0.08)' }}
                      >
                        <Wallet size={26} strokeWidth={1.5} style={{ color: 'var(--color-navy)' }} aria-hidden="true" />
                      </div>
                      <p className="font-semibold text-base mb-1" style={{ color: 'var(--color-navy)' }}>
                        No transactions yet
                      </p>
                      <p className="text-sm mb-5 max-w-xs" style={{ color: '#747780' }}>
                        Record your first transaction to get started.
                      </p>
                      {can('create transactions') && (
                        <button
                          onClick={() => navigate('/finance/new')}
                          className="btn-primary"
                          style={{ padding: '10px 24px' }}
                        >
                          Record First Transaction
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              ) : transactions.map((txn) => (
                /* Replace onMouseEnter/Leave with Tailwind hover utility */
                <tr
                  key={txn.id}
                  className="hover:bg-slate-50 transition-colors duration-150"
                  style={{ borderTop: '1px solid var(--color-surface-border)' }}
                >
                  <td style={{ padding: '16px 24px', fontSize: '15px', color: '#191c1e', whiteSpace: 'nowrap' }}>
                    {txn.transaction_date}
                  </td>
                  <td style={{ padding: '16px 24px', whiteSpace: 'nowrap' }}>
                    {/* Fix #7 — icon + text pill; not color-dot alone */}
                    <div className="flex items-center gap-2.5">
                      <span
                        className="inline-flex items-center gap-1 text-[11px] font-bold uppercase tracking-wide rounded-md"
                        style={{
                          padding: '3px 7px',
                          backgroundColor: txn.type === 'income' ? '#dcfce7' : '#ffe4e6',
                          color: txn.type === 'income' ? '#15803d' : '#be123c',
                        }}
                        aria-label={txn.type === 'income' ? 'Income' : 'Expense'}
                      >
                        {txn.type === 'income'
                          ? <ArrowUpCircle size={10} strokeWidth={2.5} aria-hidden="true" />
                          : <ArrowDownCircle size={10} strokeWidth={2.5} aria-hidden="true" />
                        }
                        {txn.type}
                      </span>
                      <span style={{ fontSize: '14px', fontWeight: 600, color: '#191c1e' }}>
                        {txn.category?.name ?? '—'}
                      </span>
                    </div>
                  </td>
                  <td className="hidden sm:table-cell" style={{ padding: '16px 24px', fontSize: '15px', color: '#44474f', whiteSpace: 'nowrap' }}>
                    {txn.member ? txn.member.name : <em style={{ color: '#747780' }}>Anonymous</em>}
                  </td>
                  <td style={{ padding: '16px 24px', fontSize: '15px', fontWeight: 700, whiteSpace: 'nowrap', color: txn.type === 'income' ? '#059669' : '#e11d48' }}>
                    {txn.type === 'income' ? '+ ' : '− '}{fmt(txn.amount)}
                  </td>
                  <td className="hidden md:table-cell" style={{ padding: '16px 24px', fontSize: '13px', fontFamily: 'monospace', color: '#747780', whiteSpace: 'nowrap' }}>
                    {txn.reference ?? '—'}
                  </td>
                  {/* Fix #4 — inline confirm; Fix #4a — h-9 touch targets */}
                  <td style={{ padding: '12px 24px', textAlign: 'right', whiteSpace: 'nowrap' }}>
                    <div className="flex justify-end items-center gap-1">
                      {can('edit transactions') && (
                        <button
                          onClick={() => navigate(`/finance/${txn.id}/edit`)}
                          className="inline-flex items-center justify-center h-9 px-3 rounded-lg text-sm font-semibold transition-colors hover:bg-amber-50"
                          style={{ color: '#92400e' }}
                          aria-label={`Edit transaction ${txn.reference ?? txn.id}`}
                        >
                          Edit
                        </button>
                      )}
                      {can('delete transactions') && (
                        pendingDelete === txn.id ? (
                          <div className="flex items-center gap-1">
                            <button
                              onClick={() => handleDelete(txn)}
                              disabled={deleting === txn.id}
                              className="inline-flex items-center justify-center h-9 px-3 rounded-lg text-xs font-bold transition-colors bg-red-50 hover:bg-red-100 disabled:opacity-40"
                              style={{ color: '#be123c' }}
                              aria-label="Confirm deletion of this transaction"
                            >
                              {deleting === txn.id ? '…' : 'Confirm'}
                            </button>
                            <button
                              onClick={() => setPendingDelete(null)}
                              className="inline-flex items-center justify-center h-9 px-2 rounded-lg text-xs font-semibold transition-colors hover:bg-slate-100"
                              style={{ color: '#747780' }}
                              aria-label="Cancel deletion"
                            >
                              Cancel
                            </button>
                          </div>
                        ) : (
                          <button
                            onClick={() => setPendingDelete(txn.id)}
                            className="inline-flex items-center justify-center h-9 px-3 rounded-lg text-sm font-semibold transition-colors hover:bg-red-50"
                            style={{ color: '#be123c' }}
                            aria-label={`Delete transaction ${txn.reference ?? txn.id}`}
                          >
                            Delete
                          </button>
                        )
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {/* Pagination */}
        {meta && meta.last_page > 1 && (
          <div
            className="flex items-center justify-between p-4 md:p-6"
            style={{ borderTop: '1px solid var(--color-surface-border)' }}
          >
            <span style={{ fontSize: '14px', color: '#747780' }}>
              Page {meta.current_page} of {meta.last_page} · {meta.total} transactions
            </span>
            <div className="flex gap-2" role="navigation" aria-label="Pagination">
              <button
                disabled={page === 1}
                onClick={() => setPage(p => p - 1)}
                className="inline-flex items-center gap-1.5 h-10 px-4 rounded-lg text-sm font-semibold transition-colors disabled:opacity-40 disabled:cursor-not-allowed hover:bg-slate-100"
                style={{ border: '1px solid var(--color-surface-border)', color: 'var(--color-navy)' }}
                aria-label="Go to previous page"
              >
                <ChevronLeft size={16} strokeWidth={2} aria-hidden="true" />
                Previous
              </button>
              <button
                disabled={page === meta.last_page}
                onClick={() => setPage(p => p + 1)}
                className="inline-flex items-center gap-1.5 h-10 px-4 rounded-lg text-sm font-semibold transition-colors text-white disabled:opacity-40 disabled:cursor-not-allowed hover:opacity-90"
                style={{ backgroundColor: 'var(--color-navy)' }}
                aria-label="Go to next page"
              >
                Next
                <ChevronRight size={16} strokeWidth={2} aria-hidden="true" />
              </button>
            </div>
          </div>
        )}
      </div>

      {/* Monthly Trend chart — Fix #5 surface-card */}
      <div className="surface-card p-4 md:p-6">
        <div className="flex items-center justify-between mb-6">
          <h4 style={{ fontFamily: 'var(--font-display)', fontSize: '24px', fontWeight: 600, color: 'var(--color-navy)' }}>
            Monthly Trend
          </h4>
          {/* Fix #7 — chart legend uses icon + text, not color alone */}
          <div className="flex gap-4">
            <span className="flex items-center gap-1.5" style={{ fontSize: '12px', color: '#44474f' }}>
              <TrendingUp size={12} strokeWidth={2} style={{ color: '#059669' }} aria-hidden="true" />
              Income
            </span>
            <span className="flex items-center gap-1.5" style={{ fontSize: '12px', color: '#44474f' }}>
              <TrendingDown size={12} strokeWidth={2} style={{ color: '#e11d48' }} aria-hidden="true" />
              Expense
            </span>
          </div>
        </div>
        {chart.length === 0 ? (
          <div className="text-center py-12 text-sm" style={{ color: '#9ca3af' }}>No data yet</div>
        ) : (
          <ResponsiveContainer width="100%" height={260}>
            <BarChart data={chart}>
              <CartesianGrid strokeDasharray="3 3" stroke="#E5E9F2" />
              <XAxis dataKey="month" stroke="#9ca3af" style={{ fontSize: '12px' }} />
              <YAxis stroke="#9ca3af" style={{ fontSize: '12px' }} tickFormatter={fmtShort} />
              <Tooltip
                contentStyle={{ backgroundColor: 'white', border: '1px solid var(--color-surface-border)', borderRadius: '8px', fontSize: '12px' }}
                formatter={(v) => fmt(v)}
              />
              <Legend wrapperStyle={{ fontSize: '12px' }} />
              <Bar dataKey="income"   fill="#059669" name="Income"  radius={[4, 4, 0, 0]} />
              <Bar dataKey="expenses" fill="#e11d48" name="Expense" radius={[4, 4, 0, 0]} />
            </BarChart>
          </ResponsiveContainer>
        )}
      </div>
    </div>
  )
}
