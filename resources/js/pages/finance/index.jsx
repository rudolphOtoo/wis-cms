import React, { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid, Legend } from 'recharts'
import { getTransactions, getFinanceStats, getFinanceCategories, deleteTransaction, exportTransactions } from '../../api/finance'
import { usePermission } from '../../hooks/usePermission'

const fmt = (n) => `GHS ${Number(n).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
const fmtShort = (n) => {
  if (n >= 1_000_000) return `${(n / 1_000_000).toFixed(1)}M`
  if (n >= 1_000)     return `${(n / 1_000).toFixed(1)}K`
  return n
}

const cardBase = {
  backgroundColor: '#fff',
  border: '1px solid var(--color-surface-border)',
  borderRadius: '16px',
  boxShadow: '0 4px 12px rgba(13,31,60,0.05)',
}

const Icon = ({ d, size = 22 }) => (
  <svg width={size} height={size} fill="none" stroke="currentColor" strokeWidth={1.8}
       viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">{d}</svg>
)
const ICONS = {
  income:  <><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></>,
  expense: <><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/></>,
  wallet:  <><path d="M21 12V7H5a2 2 0 010-4h14v4M3 5v14a2 2 0 002 2h16v-5M18 12a2 2 0 000 4h4v-4h-4z"/></>,
}

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
  const [exporting,    setExporting] = useState(false)

  const handleExport = async () => {
    setExporting(true)
    try {
      const res = await exportTransactions({ search, type: typeFilter, category_id: catFilter })
      const url = URL.createObjectURL(new Blob([res.data], { type: 'text/csv' }))
      const a = document.createElement('a')
      a.href = url
      a.download = `transactions-${new Date().toISOString().split('T')[0]}.csv`
      document.body.appendChild(a)
      a.click()
      a.remove()
      URL.revokeObjectURL(url)
    } catch (err) {
      console.error(err)
      alert('Export failed. Please try again.')
    } finally {
      setExporting(false)
    }
  }

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

  const chart = stats?.chart ?? []

  const summaryCards = [
    {
      label:'This Month — Income', value: stats?.this_month_income, icon: ICONS.income,
      gradient:'linear-gradient(135deg,#059669,#065f46)', figureColor:'white',
    },
    {
      label:'This Month — Expenses', value: stats?.this_month_expenses, icon: ICONS.expense,
      gradient:'linear-gradient(135deg,#e11d48,#9f1239)', figureColor:'white',
    },
    {
      label:'This Month — Balance', value: stats?.this_month_balance, icon: ICONS.wallet,
      gradient:'linear-gradient(135deg,#002452,#003c8f)', figureColor:'var(--color-gold-light)',
      goldIcon:true,
    },
  ]

  return (
    <div className="space-y-6" style={{maxWidth:'1440px'}}>

      {/* Summary cards */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        {summaryCards.map(c => (
          <div key={c.label} className="relative overflow-hidden flex flex-col justify-between text-white"
               style={{borderRadius:'16px',padding:'24px',minHeight:'160px',background:c.gradient,
                       boxShadow:'0 4px 12px rgba(13,31,60,0.05)'}}>
            <div className="absolute" style={{right:'-16px',bottom:'-16px',opacity:0.1}}>
              <Icon d={c.icon} size={120} />
            </div>
            <div className="flex justify-between items-start relative z-10">
              <span style={{fontSize:'14px',fontWeight:600,opacity:0.85}}>{c.label}</span>
              <div className="rounded-lg" style={{padding:'8px',backgroundColor: c.goldIcon ? 'var(--color-gold)' : 'rgba(255,255,255,0.2)'}}>
                <Icon d={c.icon} size={20} />
              </div>
            </div>
            <div className="relative z-10">
              <h3 style={{fontFamily:'var(--font-display)',fontSize:'30px',fontWeight:700,letterSpacing:'-0.01em',color:c.figureColor}}>
                {stats ? fmt(c.value) : '—'}
              </h3>
            </div>
          </div>
        ))}
      </div>

      {/* Transactions card */}
      <div style={{...cardBase, overflow:'hidden'}}>
        {/* Header */}
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4"
             style={{padding:'24px',borderBottom:'1px solid var(--color-surface-border)'}}>
          <div>
            <h3 style={{fontFamily:'var(--font-display)',fontSize:'24px',fontWeight:600,color:'var(--color-navy)'}}>Transactions</h3>
            <p style={{fontSize:'14px',color:'#747780'}}>{meta ? `${meta.total} total recorded` : 'Loading...'}</p>
          </div>
          <div className="flex items-center gap-3">
            {can('export finance') && (
              <button onClick={handleExport} disabled={exporting}
                      className="gap-2 inline-flex items-center rounded-lg font-semibold"
                      style={{padding:'10px 20px',backgroundColor:'white',border:'1px solid var(--color-surface-border)',color:'var(--color-navy)',fontSize:'14px',opacity: exporting ? 0.6 : 1}}>
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1M12 12V3m0 9l-4-4m4 4l4-4"/>
                </svg>
                {exporting ? 'Exporting...' : 'Export CSV'}
              </button>
            )}
            {can('create transactions') && (
              <button onClick={() => navigate('/finance/new')} className="btn-primary gap-2" style={{padding:'10px 24px'}}>
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4"/>
                </svg>
                Record Transaction
              </button>
            )}
          </div>
        </div>

        {/* Filter bar */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4"
             style={{padding:'24px',borderBottom:'1px solid var(--color-surface-border)',backgroundColor:'#fafbfc'}}>
          <div className="relative">
            <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4" style={{color:'#747780'}}
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <input type="text" placeholder="Search reference, notes, or member"
                   className="input-field" style={{paddingLeft:'2.5rem'}}
                   value={search} onChange={e => { setSearch(e.target.value); setPage(1) }}/>
          </div>
          <select className="input-field" value={typeFilter} onChange={e => { setType(e.target.value); setPage(1) }}>
            <option value="">All Types</option>
            <option value="income">Income</option>
            <option value="expense">Expense</option>
          </select>
          <select className="input-field" value={catFilter} onChange={e => { setCat(e.target.value); setPage(1) }}>
            <option value="">All Categories</option>
            {categories.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        </div>

        {/* Table */}
        <div className="overflow-x-auto">
          <table className="w-full text-left">
            <thead>
              <tr style={{backgroundColor:'#f2f3f6'}}>
                {[['Date'],['Category'],['Member'],['Amount'],['Reference'],['Actions','right']].map(([h, align]) => (
                  <th key={h} className="uppercase tracking-wider"
                      style={{padding:'12px 24px',fontSize:'12px',fontWeight:700,color:'#747780',textAlign:align||'left'}}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr><td colSpan={6} className="text-center" style={{padding:'48px',color:'#9ca3af'}}>Loading...</td></tr>
              ) : transactions.length === 0 ? (
                <tr><td colSpan={6} className="text-center" style={{padding:'48px'}}>
                  <div className="text-4xl mb-3">💰</div>
                  <div className="font-semibold" style={{color:'var(--color-navy)'}}>No transactions yet</div>
                  <div className="text-sm mt-1" style={{color:'#9ca3af'}}>Record your first transaction to get started</div>
                </td></tr>
              ) : transactions.map((txn) => (
                <tr key={txn.id} className="group transition-colors" style={{borderTop:'1px solid var(--color-surface-border)'}}
                    onMouseEnter={e => e.currentTarget.style.backgroundColor='#f8f9fc'}
                    onMouseLeave={e => e.currentTarget.style.backgroundColor='transparent'}>
                  <td style={{padding:'16px 24px',fontSize:'15px',color:'#191c1e',whiteSpace:'nowrap'}}>{txn.transaction_date}</td>
                  <td style={{padding:'16px 24px',whiteSpace:'nowrap'}}>
                    <div className="flex items-center gap-2">
                      <span className="w-2 h-2 rounded-full" style={{backgroundColor: txn.type === 'income' ? '#10b981' : '#e11d48'}}/>
                      <span style={{fontSize:'14px',fontWeight:600,color:'#191c1e'}}>{txn.category?.name ?? '—'}</span>
                    </div>
                  </td>
                  <td style={{padding:'16px 24px',fontSize:'15px',color:'#44474f',whiteSpace:'nowrap'}}>
                    {txn.member ? txn.member.name : <em style={{color:'#747780'}}>Anonymous</em>}
                  </td>
                  <td style={{padding:'16px 24px',fontSize:'15px',fontWeight:700,whiteSpace:'nowrap',
                              color: txn.type === 'income' ? '#059669' : '#e11d48'}}>
                    {txn.type === 'income' ? '+ ' : '− '}{fmt(txn.amount)}
                  </td>
                  <td style={{padding:'16px 24px',fontSize:'13px',fontFamily:'monospace',color:'#747780',whiteSpace:'nowrap'}}>{txn.reference ?? '—'}</td>
                  <td style={{padding:'16px 24px',textAlign:'right',whiteSpace:'nowrap'}}>
                    <div className="flex justify-end gap-2">
                      {can('edit transactions') && (
                        <button onClick={() => navigate(`/finance/${txn.id}/edit`)}
                                className="px-2 py-1 rounded hover:underline" style={{fontSize:'14px',fontWeight:600,color:'var(--color-navy)'}}>Edit</button>
                      )}
                      {can('delete transactions') && (
                        <button onClick={() => handleDelete(txn)} disabled={deleting === txn.id}
                                className="px-2 py-1 rounded hover:underline" style={{fontSize:'14px',fontWeight:600,color:'#ba1a1a'}}>
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

        {/* Pagination */}
        {meta && meta.last_page > 1 && (
          <div className="flex items-center justify-between" style={{padding:'16px 24px',borderTop:'1px solid var(--color-surface-border)'}}>
            <span style={{fontSize:'14px',color:'#747780'}}>Page {meta.current_page} of {meta.last_page} · {meta.total} transactions</span>
            <div className="flex gap-2">
              <button disabled={page === 1} onClick={() => setPage(p => p - 1)}
                      className="px-4 py-2 rounded-lg disabled:opacity-50" style={{border:'1px solid var(--color-surface-border)',fontSize:'14px',color:'var(--color-navy)'}}>Previous</button>
              <button disabled={page === meta.last_page} onClick={() => setPage(p => p + 1)}
                      className="px-4 py-2 rounded-lg text-white" style={{backgroundColor:'var(--color-navy)',fontSize:'14px'}}>Next</button>
            </div>
          </div>
        )}
      </div>

      {/* Monthly Trend — REAL Recharts */}
      <div style={{...cardBase, padding:'24px'}}>
        <div className="flex items-center justify-between mb-6">
          <h4 style={{fontFamily:'var(--font-display)',fontSize:'24px',fontWeight:600,color:'var(--color-navy)'}}>Monthly Trend</h4>
          <div className="flex gap-3">
            <span className="flex items-center gap-1" style={{fontSize:'12px'}}><span className="w-3 h-3 rounded-full" style={{backgroundColor:'#10b981'}}/> Income</span>
            <span className="flex items-center gap-1" style={{fontSize:'12px'}}><span className="w-3 h-3 rounded-full" style={{backgroundColor:'#e11d48'}}/> Expense</span>
          </div>
        </div>
        {chart.length === 0 ? (
          <div className="text-center py-12 text-sm" style={{color:'#9ca3af'}}>No data yet</div>
        ) : (
          <ResponsiveContainer width="100%" height={260}>
            <BarChart data={chart}>
              <CartesianGrid strokeDasharray="3 3" stroke="#E5E9F2"/>
              <XAxis dataKey="month" stroke="#9ca3af" style={{fontSize:'12px'}}/>
              <YAxis stroke="#9ca3af" style={{fontSize:'12px'}} tickFormatter={fmtShort}/>
              <Tooltip contentStyle={{backgroundColor:'white',border:'1px solid var(--color-surface-border)',borderRadius:'8px',fontSize:'12px'}} formatter={(v) => fmt(v)}/>
              <Legend wrapperStyle={{ fontSize:'12px' }}/>
              <Bar dataKey="income"   fill="#10b981" name="Income"   radius={[4,4,0,0]}/>
              <Bar dataKey="expenses" fill="#e11d48" name="Expense"  radius={[4,4,0,0]}/>
            </BarChart>
          </ResponsiveContainer>
        )}
      </div>
    </div>
  )
}
