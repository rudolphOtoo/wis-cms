import { useState, useEffect, useRef } from 'react'
import { getAttendanceTrendsReport, downloadAttendanceTrendsPdf, downloadAttendanceTrendsXlsx } from '../../api/reports'
import DownloadReportMenu from '../../components/reports/DownloadReportMenu'
import {
  LineChart, Line, XAxis, YAxis, Tooltip, Legend,
  ResponsiveContainer, CartesianGrid
} from 'recharts'

// Service-type line colors. The chart picks 3-5 from this palette
// based on which types appear in the data.
const COLORS = [
  '#1b3a6b', // navy — primary
  '#c9a84c', // gold — secondary
  '#2e7d32', // green
  '#a14d4d', // muted red
  '#5b6c8a', // slate
  '#7a5a2e', // brown
  '#4a7c7a', // teal
]

function formatPercent(n) {
  if (n === null || n === undefined) return '—'
  return n.toFixed(1) + '%'
}

function formatCount(n) {
  if (n === null || n === undefined) return '—'
  return n.toLocaleString('en-GH')
}

/**
 * Pivot the API's per-period rows (each with a by_service_type sub-map)
 * into the flat shape Recharts wants for a multi-line chart:
 *   [{ period: 'Mar 9-15', 'Sunday Adult Service': 42, 'Cell Meeting': 8 }, ...]
 */
function pivotForChart(rows) {
  return rows.map(r => {
    const point = { period: r.period_label, rate: r.attendance_rate }
    for (const [name, counts] of Object.entries(r.by_service_type || {})) {
      point[name] = counts.present
    }
    return point
  })
}

/**
 * Collect the set of service-type names seen across all rows
 * (sorted by total presence — primary types first).
 */
function serviceTypeNames(rows) {
  const totals = {}
  rows.forEach(r => {
    for (const [name, counts] of Object.entries(r.by_service_type || {})) {
      totals[name] = (totals[name] || 0) + counts.present
    }
  })
  return Object.entries(totals)
    .sort((a, b) => b[1] - a[1])
    .map(([name]) => name)
}

function trendIcon(direction) {
  if (direction === 'up') return '↑'
  if (direction === 'down') return '↓'
  if (direction === 'flat') return '→'
  return '?'
}

function trendColor(direction) {
  if (direction === 'up') return '#2e7d32'
  if (direction === 'down') return '#a14d4d'
  return '#44474f'
}

export default function AttendanceTrends() {
  // 12 weeks back, ISO weekday = Monday
  const today = new Date()
  const defaultTo = today.toISOString().slice(0, 10)
  const defaultFrom = new Date(today.getTime() - 12 * 7 * 86400000)
    .toISOString().slice(0, 10)

  const [fromDate, setFromDate] = useState(defaultFrom)
  const [toDate, setToDate] = useState(defaultTo)
  const [groupBy, setGroupBy] = useState('week')

  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(null)

  async function load() {
    setLoading(true)
    setError(null)
    try {
      const res = await getAttendanceTrendsReport({
        from_date: fromDate,
        to_date: toDate,
        group_by: groupBy,
      })
      setData(res.data)
    } catch (e) {
      setError(e?.response?.data?.message ?? 'Failed to load report.')
    } finally {
      setLoading(false)
    }
  }

  const loadRef = useRef(load)
  useEffect(() => { loadRef.current = load })
  useEffect(() => { loadRef.current() }, [])

  const chartData = data?.rows ? pivotForChart(data.rows) : []
  const stNames = data?.rows ? serviceTypeNames(data.rows) : []

  return (
    <div className="space-y-6">
      <div>
        <h1 className="font-bold" style={{fontFamily:'var(--font-display)',fontSize:'32px',color:'var(--color-navy)'}}>
          Attendance Trends
        </h1>
        <p style={{color:'#44474f',marginTop:'4px'}}>
          Attendance rate and breakdown over time, for council monthly review
        </p>
      </div>

      {/* Filters */}
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
        <div className="flex flex-col">
          <label className="text-xs font-bold uppercase tracking-wider mb-1" style={{color:'#44474f'}}>Group By</label>
          <select value={groupBy} onChange={e => setGroupBy(e.target.value)}
                  className="px-3 py-2 rounded-lg" style={{border:'1px solid var(--color-surface-border)'}}>
            <option value="week">Week</option>
            <option value="month">Month</option>
          </select>
        </div>
        <button onClick={load} disabled={loading}
                className="btn-primary px-6 py-2">
          {loading ? 'Loading...' : 'Update Report'}
        </button>
        <DownloadReportMenu
          pdfHandler={() => downloadAttendanceTrendsPdf({ from_date: fromDate, to_date: toDate })}
          xlsxHandler={() => downloadAttendanceTrendsXlsx({ from_date: fromDate, to_date: toDate })}
          filenameBase={`attendance-trends-${fromDate}-to-${toDate}`}
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
          <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
            <SummaryCard
              label="Total Sessions"
              value={formatCount(data.summary.total_sessions)}
              sub={`Avg ${data.summary.avg_per_session} present/session`} />
            <SummaryCard
              label="Attendance Rate"
              value={formatPercent(data.summary.overall_attendance_rate)}
              sub={`${formatCount(data.summary.total_present)} of ${formatCount(data.summary.total_present + data.summary.total_absent)}`} />
            <SummaryCard
              label="Total Absent"
              value={formatCount(data.summary.total_absent)}
              sub="Across the period" />
            <SummaryCard
              label="Trend"
              value={
                <span style={{color: trendColor(data.summary.trend.direction)}}>
                  {trendIcon(data.summary.trend.direction)} {data.summary.trend.direction}
                </span>
              }
              sub={`${formatPercent(data.summary.trend.recent_rate)} vs ${formatPercent(data.summary.trend.prior_rate)}`} />
          </div>

          {/* Chart */}
          <div className="bg-white rounded-xl p-4 md:p-6"
               style={{border:'1px solid var(--color-surface-border)'}}>
            <h2 className="font-bold mb-4"
                style={{fontFamily:'var(--font-display)',fontSize:'20px',color:'var(--color-navy)'}}>
              Attendance Count by Service Type
            </h2>
            {chartData.length === 0 ? (
              <p style={{color:'#9ca3af'}}>No data in selected range.</p>
            ) : (
              <ResponsiveContainer width="100%" height={360}>
                <LineChart data={chartData} margin={{top:20,right:20,left:0,bottom:20}}>
                  <CartesianGrid strokeDasharray="3 3" stroke="#e1e2e5" />
                  <XAxis dataKey="period" stroke="#44474f" />
                  <YAxis stroke="#44474f" />
                  <Tooltip formatter={(v) => formatCount(v) + ' present'} />
                  <Legend />
                  {stNames.map((name, idx) => (
                    <Line key={name} type="monotone" dataKey={name}
                          stroke={COLORS[idx % COLORS.length]} strokeWidth={2}
                          dot={{r:3}} activeDot={{r:5}} />
                  ))}
                </LineChart>
              </ResponsiveContainer>
            )}
          </div>

          {/* Per-period breakdown */}
          <div className="bg-white rounded-xl overflow-hidden"
               style={{border:'1px solid var(--color-surface-border)'}}>
            <div className="px-6 py-4" style={{borderBottom:'1px solid var(--color-surface-border)'}}>
              <h2 className="font-bold"
                  style={{fontFamily:'var(--font-display)',fontSize:'20px',color:'var(--color-navy)'}}>
                Period Breakdown
              </h2>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead style={{backgroundColor:'#edeef1'}}>
                  <tr>
                    <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Period</th>
                    <th className="text-right px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Sessions</th>
                    <th className="text-right px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Present</th>
                    <th className="text-right px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Absent</th>
                    <th className="text-right px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Rate</th>
                  </tr>
                </thead>
                <tbody>
                  {data.rows.map(r => (
                    <tr key={r.period_start} style={{borderTop:'1px solid var(--color-surface-border)'}}>
                      <td className="px-6 py-3" style={{color:'var(--color-navy)'}}>{r.period_label}</td>
                      <td className="px-6 py-3 text-right font-mono">{formatCount(r.sessions_count)}</td>
                      <td className="px-6 py-3 text-right font-mono">{formatCount(r.records_present)}</td>
                      <td className="px-6 py-3 text-right font-mono">{formatCount(r.records_absent)}</td>
                      <td className="px-6 py-3 text-right font-mono">{formatPercent(r.attendance_rate)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
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
