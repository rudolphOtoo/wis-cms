import { useState, useEffect, useRef } from 'react'
import { getExpenseByCategoryReport, downloadExpenseByCategoryPdf, downloadExpenseByCategoryXlsx } from '../../api/reports'
import DownloadReportMenu from '../../components/reports/DownloadReportMenu'
import { BarChart, Bar, XAxis, YAxis, Tooltip, Legend, ResponsiveContainer, CartesianGrid } from 'recharts'

// Color palette for expense category bars.
// Burgundy/rust-leaning to visually distinguish "money out" from
// the navy/gold "money in" palette on the Income report. Eye scans
// the sidebar and immediately knows which side of the ledger.
const COLORS = [
  '#8b1e3f', // burgundy
  '#c87533', // rust
  '#5d4e75', // muted purple
  '#a14d4d', // muted red
  '#7a5a2e', // brown
  '#4a7c7a', // teal
  '#5b6c8a', // slate
  '#6b8e23', // olive
  '#b85c3a', // terracotta
  '#8a4e9e', // purple
]

function formatGHS(n) {
  if (n === null || n === undefined) return '—'
  return new Intl.NumberFormat('en-GH', {
    style: 'currency',
    currency: 'GHS',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(n)
}

function formatGHSShort(n) {
  if (n === null || n === undefined) return '—'
  if (n >= 1000) return 'GHS ' + (n / 1000).toFixed(1) + 'k'
  return 'GHS ' + n.toFixed(0)
}

/**
 * Transform the API's row-per-(month,category) format into the
 * shape Recharts wants for a stacked bar chart:
 *   [{ month: '2025-12', Salaries: 1500, Utilities: 800, ... }, ...]
 */
function pivotForChart(rows) {
  const map = {}
  for (const r of rows) {
    if (!map[r.month]) map[r.month] = { month: r.month }
    map[r.month][r.category_name] = r.total
  }
  return Object.values(map).sort((a, b) => a.month.localeCompare(b.month))
}

function defaultDateRange() {
  const to = new Date()
  const from = new Date()
  from.setMonth(from.getMonth() - 6)
  from.setDate(1)
  return {
    from: from.toISOString().slice(0, 10),
    to: to.toISOString().slice(0, 10),
  }
}

export default function ExpenseByCategory() {
  const initial = defaultDateRange()
  const [fromDate, setFromDate] = useState(initial.from)
  const [toDate, setToDate] = useState(initial.to)
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(null)

  const load = async () => {
    setLoading(true)
    setError(null)
    try {
      const res = await getExpenseByCategoryReport({
        from_date: fromDate,
        to_date: toDate,
      })
      setData(res.data)
    } catch (err) {
      setError(err?.response?.data?.message || 'Failed to load report')
    } finally {
      setLoading(false)
    }
  }

  const loadRef = useRef(load)
  useEffect(() => { loadRef.current = load })
  useEffect(() => { loadRef.current() }, [])

  const chartData = data?.rows ? pivotForChart(data.rows) : []
  const categoryNames = data?.summary?.category_totals?.map(c => c.category_name) ?? []

  return (
    <div className="space-y-6">
      <div>
        <h1 className="font-bold" style={{fontFamily:'var(--font-display)',fontSize:'32px',color:'var(--color-navy)'}}>
          Expense by Category
        </h1>
        <p style={{color:'#44474f',marginTop:'4px'}}>
          Monthly aggregation across all expense categories for council review
        </p>
      </div>

      {/* Date range filter */}
      <div className="bg-white rounded-xl p-4 md:p-6 flex flex-wrap items-end gap-4"
           style={{border:'1px solid var(--color-surface-border)'}}>
        <div className="flex flex-col">
          <label className="text-xs font-bold uppercase tracking-wider mb-1" style={{color:'#44474f'}}>From</label>
          <input type="date" value={fromDate} onChange={e => setFromDate(e.target.value)}
                 className="px-3 py-2 rounded-lg" style={{border:'1px solid var(--color-surface-border)'}} />
        </div>
        <div className="flex flex-col">
          <label className="text-xs font-bold uppercase tracking-wider mb-1" style={{color:'#44474f'}}>To</label>
          <input type="date" value={toDate} onChange={e => setToDate(e.target.value)}
                 className="px-3 py-2 rounded-lg" style={{border:'1px solid var(--color-surface-border)'}} />
        </div>
        <button onClick={load} disabled={loading}
                className="btn-primary px-6 py-2">
          {loading ? 'Loading...' : 'Update Report'}
        </button>
        <DownloadReportMenu
          pdfHandler={() => downloadExpenseByCategoryPdf({ from_date: fromDate, to_date: toDate })}
          xlsxHandler={() => downloadExpenseByCategoryXlsx({ from_date: fromDate, to_date: toDate })}
          filenameBase={`expense-by-category-${fromDate}-to-${toDate}`}
          disabled={loading || !data}
        />
      </div>

      {error && (
        <div className="rounded-xl p-4" style={{backgroundColor:'#fee2e2',border:'1px solid #fca5a5',color:'#991b1b'}}>
          {error}
        </div>
      )}

      {data && (
        <>
          {/* Summary cards */}
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <SummaryCard label="Total Expenses" value={formatGHS(data.summary.grand_total)}
                         sub={`Across ${data.summary.month_count} month${data.summary.month_count === 1 ? '' : 's'}`} />
            <SummaryCard label="Monthly Average" value={formatGHS(data.summary.monthly_average)}
                         sub="Per month in range" />
            <SummaryCard label="Top Category" value={data.summary.top_category ?? '—'}
                         sub={data.summary.category_totals?.[0]
                              ? data.summary.category_totals[0].percentage.toFixed(1) + '% of total'
                              : ''} />
          </div>

          {/* Chart */}
          <div className="bg-white rounded-xl p-4 md:p-6"
               style={{border:'1px solid var(--color-surface-border)'}}>
            <h2 className="font-bold mb-4"
                style={{fontFamily:'var(--font-display)',fontSize:'20px',color:'var(--color-navy)'}}>
              Monthly Expense Breakdown
            </h2>
            {chartData.length === 0 ? (
              <p style={{color:'#9ca3af'}}>No data in selected range.</p>
            ) : (
              <ResponsiveContainer width="100%" height={360}>
                <BarChart data={chartData} margin={{top:20,right:20,left:0,bottom:20}}>
                  <CartesianGrid strokeDasharray="3 3" stroke="#e1e2e5" />
                  <XAxis dataKey="month" stroke="#44474f" />
                  <YAxis stroke="#44474f" tickFormatter={formatGHSShort} />
                  <Tooltip formatter={(v) => formatGHS(v)} />
                  <Legend />
                  {categoryNames.map((name, idx) => (
                    <Bar key={name} dataKey={name} stackId="a" fill={COLORS[idx % COLORS.length]} />
                  ))}
                </BarChart>
              </ResponsiveContainer>
            )}
          </div>

          {/* Category breakdown table */}
          <div className="bg-white rounded-xl overflow-hidden"
               style={{border:'1px solid var(--color-surface-border)'}}>
            <div className="px-6 py-4" style={{borderBottom:'1px solid var(--color-surface-border)'}}>
              <h2 className="font-bold"
                  style={{fontFamily:'var(--font-display)',fontSize:'20px',color:'var(--color-navy)'}}>
                Category Breakdown
              </h2>
            </div>
            <table className="w-full text-sm">
              <thead style={{backgroundColor:'#edeef1'}}>
                <tr>
                  <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Category</th>
                  <th className="text-right px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Total</th>
                  <th className="text-right px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Share</th>
                </tr>
              </thead>
              <tbody>
                {data.summary.category_totals.map(c => (
                  <tr key={c.category_id} style={{borderTop:'1px solid var(--color-surface-border)'}}>
                    <td className="px-6 py-3" style={{color:'var(--color-navy)'}}>{c.category_name}</td>
                    <td className="px-6 py-3 text-right font-mono">{formatGHS(c.total)}</td>
                    <td className="px-6 py-3 text-right" style={{color:'#44474f'}}>{c.percentage.toFixed(1)}%</td>
                  </tr>
                ))}
                <tr style={{borderTop:'2px solid var(--color-navy)',backgroundColor:'#f8f9fa'}}>
                  <td className="px-6 py-3 font-bold" style={{color:'var(--color-navy)'}}>Total Expenses</td>
                  <td className="px-6 py-3 text-right font-mono font-bold" style={{color:'var(--color-navy)'}}>
                    {formatGHS(data.summary.grand_total)}
                  </td>
                  <td className="px-6 py-3 text-right font-bold" style={{color:'var(--color-navy)'}}>100%</td>
                </tr>
              </tbody>
            </table>
          </div>
        </>
      )}

      {!data && !loading && !error && (
        <div className="bg-white rounded-xl p-6 text-center" style={{border:'1px solid var(--color-surface-border)',color:'#9ca3af'}}>
          Select a date range and click Update Report.
        </div>
      )}
    </div>
  )
}

function SummaryCard({ label, value, sub }) {
  return (
    <div className="bg-white rounded-xl p-5"
         style={{border:'1px solid var(--color-surface-border)'}}>
      <div className="text-xs font-bold uppercase tracking-wider" style={{color:'#44474f'}}>{label}</div>
      <div className="mt-2 font-bold"
           style={{fontFamily:'var(--font-display)',fontSize:'28px',color:'var(--color-navy)'}}>
        {value}
      </div>
      {sub && <div className="text-xs mt-1" style={{color:'#9ca3af'}}>{sub}</div>}
    </div>
  )
}
