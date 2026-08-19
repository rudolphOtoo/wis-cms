import { useState, useEffect } from 'react'
import { getPayments, getPaymentStats } from '../../api/payments'

const STATUS_STYLES = {
  pending: { bg: '#fef9c3', text: '#a16207', border: '#fde047' },
  success: { bg: '#dcfce7', text: '#15803d', border: '#86efac' },
  failed:  { bg: '#fee2e2', text: '#dc2626', border: '#fca5a5' },
  cancelled: { bg: '#f3f4f6', text: '#6b7280', border: '#d1d5db' },
}

const PAYMENT_TYPES = [
  { value: '', label: 'All Types' },
  { value: 'tithe', label: 'Tithe' },
  { value: 'offering', label: 'Offering' },
  { value: 'welfare', label: 'Welfare' },
  { value: 'building_fund', label: 'Building Fund' },
  { value: 'special_seed', label: 'Special Seed' },
  { value: 'other', label: 'Other' },
]

const STATUS_OPTIONS = [
  { value: '', label: 'All Statuses' },
  { value: 'pending', label: 'Pending' },
  { value: 'success', label: 'Successful' },
  { value: 'failed', label: 'Failed' },
]

function StatCard({ label, value, sub, color }) {
  return (
    <div className="card">
      <div className="text-xs font-semibold uppercase tracking-wider mb-1" style={{ color: '#6b7280' }}>{label}</div>
      <div className="text-2xl font-bold" style={{ color: color || 'var(--color-navy)', fontFamily: 'var(--font-display)' }}>
        {value}
      </div>
      {sub && <div className="text-xs mt-1" style={{ color: '#9ca3af' }}>{sub}</div>}
    </div>
  )
}

export default function Payments() {
  const [payments, setPayments] = useState([])
  const [stats, setStats]       = useState(null)
  const [meta, setMeta]         = useState({ total: 0, per_page: 20, current_page: 1, last_page: 1 })
  const [filters, setFilters]   = useState({ payment_type: '', status: '', from: '', to: '' })
  const [loading, setLoading]   = useState(true)
  const [page, setPage]         = useState(1)

  useEffect(() => {
    const ctrl = new AbortController()
    setLoading(true)

    const params = { per_page: 20, page, ...filters }
    Object.keys(params).forEach(k => { if (!params[k]) delete params[k] })

    Promise.all([
      getPayments(params, ctrl.signal),
      getPaymentStats(ctrl.signal),
    ]).then(([payRes, statsRes]) => {
      setPayments(payRes.data.data)
      setMeta(payRes.data.meta)
      setStats(statsRes.data.data)
    }).catch(() => {}).finally(() => setLoading(false))

    return () => ctrl.abort()
  }, [page, filters])

  const setFilter = (field) => (e) => {
    setFilters(f => ({ ...f, [field]: e.target.value }))
    setPage(1)
  }

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl font-bold" style={{ fontFamily: 'var(--font-display)', color: 'var(--color-navy)' }}>
          Online Payments
        </h2>
        <p className="text-sm" style={{ color: '#6b7280' }}>
          Mobile Money and digital payment transactions
        </p>
      </div>

      {/* KPI Cards */}
      {stats && (
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
          <StatCard
            label="Received This Month"
            value={`GHS ${Number(stats.this_month?.total_received || 0).toLocaleString('en-GH', { minimumFractionDigits: 2 })}`}
            sub={`${stats.this_month?.success_count || 0} successful`}
            color="#15803d"
          />
          <StatCard
            label="Pending"
            value={stats.this_month?.pending_count || 0}
            sub="Awaiting confirmation"
            color="#a16207"
          />
          <StatCard
            label="Failed"
            value={stats.this_month?.failed_count || 0}
            sub="Needs attention"
            color="#dc2626"
          />
          <StatCard
            label="Total Transactions"
            value={stats.this_month?.total_count || 0}
            sub="This month"
          />
        </div>
      )}

      {/* Filters */}
      <div className="card">
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
          <select className="input-field text-sm" value={filters.payment_type} onChange={setFilter('payment_type')}>
            {PAYMENT_TYPES.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
          </select>
          <select className="input-field text-sm" value={filters.status} onChange={setFilter('status')}>
            {STATUS_OPTIONS.map(s => <option key={s.value} value={s.value}>{s.label}</option>)}
          </select>
          <input type="date" className="input-field text-sm" value={filters.from} onChange={setFilter('from')}
                 placeholder="From date" />
          <input type="date" className="input-field text-sm" value={filters.to} onChange={setFilter('to')}
                 placeholder="To date" />
        </div>
      </div>

      {/* Table */}
      <div className="card overflow-hidden">
        {loading ? (
          <div className="flex items-center justify-center py-16">
            <svg className="animate-spin w-6 h-6" style={{ color: 'var(--color-navy)' }} fill="none" viewBox="0 0 24 24">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
          </div>
        ) : payments.length === 0 ? (
          <div className="text-center py-16 text-sm" style={{ color: '#9ca3af' }}>
            No online payments found.
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr style={{ borderBottom: '1px solid var(--color-surface-border)' }}>
                    {['Date', 'Member', 'Type', 'Amount', 'Network', 'Status', 'Reference'].map(h => (
                      <th key={h} className="text-left px-4 py-3 font-semibold text-xs uppercase tracking-wider"
                          style={{ color: '#6b7280' }}>{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {payments.map(p => {
                    const style = STATUS_STYLES[p.status] || STATUS_STYLES.pending
                    return (
                      <tr key={p.id} style={{ borderBottom: '1px solid #f3f4f6' }}
                          className="hover:bg-gray-50/50 transition-colors">
                        <td className="px-4 py-3 whitespace-nowrap" style={{ color: '#374151' }}>
                          {p.created_at ? new Date(p.created_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—'}
                        </td>
                        <td className="px-4 py-3" style={{ color: '#374151' }}>
                          {p.member?.full_name || <span style={{ color: '#9ca3af' }}>Anonymous</span>}
                        </td>
                        <td className="px-4 py-3 capitalize" style={{ color: '#374151' }}>
                          {p.payment_type_label || p.payment_type}
                        </td>
                        <td className="px-4 py-3 font-semibold whitespace-nowrap" style={{ color: 'var(--color-navy)' }}>
                          GHS {Number(p.amount).toLocaleString('en-GH', { minimumFractionDigits: 2 })}
                        </td>
                        <td className="px-4 py-3" style={{ color: '#374151' }}>
                          {p.momo_network ? p.momo_network.toUpperCase() : '—'}
                        </td>
                        <td className="px-4 py-3">
                          <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold"
                                style={{ backgroundColor: style.bg, color: style.text, border: `1px solid ${style.border}` }}>
                            {p.status_label || p.status}
                          </span>
                        </td>
                        <td className="px-4 py-3 font-mono text-xs" style={{ color: '#6b7280' }}>
                          {p.reference}
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>

            {/* Pagination */}
            {meta.last_page > 1 && (
              <div className="flex items-center justify-between px-4 py-3" style={{ borderTop: '1px solid #f3f4f6' }}>
                <span className="text-xs" style={{ color: '#6b7280' }}>
                  Showing {((meta.current_page - 1) * meta.per_page) + 1} to {Math.min(meta.current_page * meta.per_page, meta.total)} of {meta.total}
                </span>
                <div className="flex gap-2">
                  <button disabled={page <= 1} onClick={() => setPage(p => p - 1)}
                          className="px-3 py-1 text-xs rounded-lg border disabled:opacity-40"
                          style={{ borderColor: 'var(--color-surface-border)' }}>Prev</button>
                  <button disabled={page >= meta.last_page} onClick={() => setPage(p => p + 1)}
                          className="px-3 py-1 text-xs rounded-lg border disabled:opacity-40"
                          style={{ borderColor: 'var(--color-surface-border)' }}>Next</button>
                </div>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  )
}
