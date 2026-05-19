import React, { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { getTransactions, getFinanceStats, getFinanceCategories, deleteTransaction } from '../../api/finance'
import { usePermission } from '../../hooks/usePermission'

const fmt = (n) => `GHS ${Number(n).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

export default function FinancePage() {
  const navigate = useNavigate()
  const { can }  = usePermission()
  const [transactions, setTxns]    = useState([])
  const [categories,   setCats]    = useState([])
  const [stats,        setStats]   = useState(null)
  const [loading,      setLoading] = useState(true)
  const [search,       setSearch]  = useState('')
  const [typeFilter,   setType]    = useState('')
  const [catFilter,    setCat]     = useState('')
  const [page,         setPage]    = useState(1)
  const [meta,         setMeta]    = useState(null)
  const [deleting,     setDel]     = useState(null)

  const fetchData = useCallback(async () => {
    setLoading(true)
    try {
      const [tRes, sRes, cRes] = await Promise.all([
        getTransactions({ search, type: typeFilter, category_id: catFilter, page, per_page: 15 }),
        getFinanceStats(),
        getFinanceCategories(),
      ])
      setTxns(tRes.data.data)
      setMeta(tRes.data.meta)
      setStats(sRes.data.data)
      setCats(cRes.data.data)
    } catch (err) {
      console.error(err)
    } finally {
      setLoading(false)
    }
  }, [search, typeFilter, catFilter, page])

  useEffect(() => { fetchData() }, [fetchData])

  useEffect(() => {
    const t = setTimeout(() => fetchData(), 400)
    return () => clearTimeout(t)
  }, [search])

  const handleDelete = async (txn) => {
    if (!confirm(`Delete this ${txn.type} of ${fmt(txn.amount)}?`)) return
    setDel(txn.id)
    try {
      await deleteTransaction(txn.id)
      fetchData()
    } catch {
      alert('Failed to delete transaction.')
    } finally {
      setDel(null)
    }
  }

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div className="card py-5" style={{background:'linear-gradient(135deg,#dcfce7,#bbf7d0)',border:'none'}}>
          <div className="flex items-center justify-between mb-2">
            <span className="text-xs font-semibold uppercase tracking-wider" style={{color:'#15803d'}}>
              This Month — Income
            </span>
            <span className="text-2xl">💰</span>
          </div>
          <div className="text-2xl font-bold" style={{fontFamily:'var(--font-display)',color:'#14532d'}}>
            {stats ? fmt(stats.this_month_income) : '—'}
          </div>
        </div>

        <div className="card py-5" style={{background:'linear-gradient(135deg,#fee2e2,#fecaca)',border:'none'}}>
          <div className="flex items-center justify-between mb-2">
            <span className="text-xs font-semibold uppercase tracking-wider" style={{color:'#b91c1c'}}>
              This Month — Expenses
            </span>
            <span className="text-2xl">📤</span>
          </div>
          <div className="text-2xl font-bold" style={{fontFamily:'var(--font-display)',color:'#7f1d1d'}}>
            {stats ? fmt(stats.this_month_expenses) : '—'}
          </div>
        </div>

        <div className="card py-5"
             style={{
               background: stats && stats.this_month_balance >= 0
                 ? 'linear-gradient(135deg, var(--color-navy-deeper), var(--color-navy))'
                 : 'linear-gradient(135deg,#7c2d12,#9a3412)',
               border:'none',
             }}>
          <div className="flex items-center justify-between mb-2">
            <span className="text-xs font-semibold uppercase tracking-wider"
                  style={{color:'rgba(255,255,255,0.7)'}}>
              This Month — Balance
            </span>
            <span className="text-2xl">⚖️</span>
          </div>
          <div className="text-2xl font-bold"
               style={{fontFamily:'var(--font-display)',color:'var(--color-gold)'}}>
            {stats ? fmt(stats.this_month_balance) : '—'}
          </div>
        </div>
      </div>

      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-xl font-bold"
              style={{fontFamily:'var(--font-display)',color:'var(--color-navy)'}}>
            Transactions
          </h2>
          <p className="text-sm" style={{color:'#6b7280'}}>
            {meta ? `${meta.total} transactions recorded` : 'Loading...'}
          </p>
        </div>
        {can('create transactions') && (
          <button onClick={() => navigate('/finance/new')} className="btn-primary gap-2">
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4"/>
            </svg>
            Record Transaction
          </button>
        )}
      </div>

      <div className="card py-4">
        <div className="flex flex-col sm:flex-row gap-3">
          <div className="flex-1 relative">
            <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4"
                 style={{color:'#9ca3af'}} fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <input type="text" placeholder="Search by reference, notes, or member..."
                   className="input-field" style={{paddingLeft:'2.5rem'}}
                   value={search} onChange={e => { setSearch(e.target.value); setPage(1) }}/>
          </div>
          <select className="input-field" style={{width:'auto'}}
                  value={typeFilter}
                  onChange={e => { setType(e.target.value); setPage(1) }}>
            <option value="">All Types</option>
            <option value="income">Income</option>
            <option value="expense">Expense</option>
          </select>
          <select className="input-field" style={{width:'auto'}}
                  value={catFilter}
                  onChange={e => { setCat(e.target.value); setPage(1) }}>
            <option value="">All Categories</option>
            {categories.map(c => (
              <option key={c.id} value={c.id}>{c.name}</option>
            ))}
          </select>
        </div>
      </div>

      <div className="card p-0 overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead>
              <tr style={{borderBottom:'1px solid var(--color-surface-border)',backgroundColor:'#f9fafb'}}>
                {['Date','Category','Member','Amount','Reference','Action'].map(h => (
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
                  <td colSpan={6} className="text-center py-12" style={{color:'#9ca3af'}}>Loading...</td>
                </tr>
              ) : transactions.length === 0 ? (
                <tr>
                  <td colSpan={6} className="text-center py-12">
                    <div className="text-4xl mb-3">💰</div>
                    <div className="font-semibold" style={{color:'var(--color-navy)'}}>
                      No transactions yet
                    </div>
                    <div className="text-sm mt-1" style={{color:'#9ca3af'}}>
                      Record your first transaction to get started
                    </div>
                  </td>
                </tr>
              ) : transactions.map((txn, i) => (
                <tr key={txn.id}
                    style={{borderBottom:'1px solid var(--color-surface-border)',
                            backgroundColor: i % 2 === 0 ? 'white' : '#fafafa'}}>
                  <td className="px-4 py-3 text-sm" style={{color:'#111827'}}>{txn.transaction_date}</td>
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-2">
                      <span className="w-2 h-2 rounded-full"
                            style={{backgroundColor: txn.type === 'income' ? '#15803d' : '#dc2626'}}/>
                      <span className="text-sm font-semibold" style={{color:'#111827'}}>
                        {txn.category?.name ?? '—'}
                      </span>
                    </div>
                  </td>
                  <td className="px-4 py-3 text-sm" style={{color:'#6b7280'}}>
                    {txn.member ? txn.member.name : <em>Anonymous</em>}
                  </td>
                  <td className="px-4 py-3 text-sm font-bold"
                      style={{color: txn.type === 'income' ? '#15803d' : '#dc2626'}}>
                    {txn.type === 'income' ? '+' : '−'} {fmt(txn.amount)}
                  </td>
                  <td className="px-4 py-3 text-sm font-mono" style={{color:'#9ca3af'}}>
                    {txn.reference ?? '—'}
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-2">
                      {can('edit transactions') && (
                        <button onClick={() => navigate(`/finance/${txn.id}/edit`)}
                                className="text-xs px-2 py-1 rounded font-medium"
                                style={{color:'#d97706',backgroundColor:'rgba(217,119,6,0.08)'}}>
                          Edit
                        </button>
                      )}
                      {can('delete transactions') && (
                        <button onClick={() => handleDelete(txn)}
                                disabled={deleting === txn.id}
                                className="text-xs px-2 py-1 rounded font-medium"
                                style={{color:'#dc2626',backgroundColor:'rgba(220,38,38,0.08)'}}>
                          {deleting === txn.id ? '...' : 'Delete'}
                        </button>
                      )}
                    </div>
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
