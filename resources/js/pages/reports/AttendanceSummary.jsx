import { useState, useEffect } from 'react'
import { getAttendanceSummaryReport, downloadAttendanceSummaryPdf, downloadAttendanceSummaryXlsx } from '../../api/reports'
import DownloadReportMenu from '../../components/reports/DownloadReportMenu'
import {
  LineChart, Line, XAxis, YAxis, Tooltip, Legend,
  ResponsiveContainer, CartesianGrid, BarChart, Bar,
} from 'recharts'

const COLORS = [
  '#1b3a6b', // navy
  '#c9a84c', // gold
  '#2e7d32', // green
  '#a14d4d', // muted red
  '#5b6c8a', // slate
  '#4a7c7a', // teal
]

const WELFARE_COLORS = {
  engaged: '#15803d',
  moderate: '#ca8a04',
  at_risk: '#dc2626',
  inactive_risk: '#6b7280',
}

const WELFARE_BG = {
  engaged: '#dcfce7',
  moderate: '#fef9c3',
  at_risk: '#fee2e2',
  inactive_risk: '#e5e7eb',
}

function formatCount(n) {
  if (n === null || n === undefined) return '—'
  return n.toLocaleString('en-GH')
}

function formatPct(n) {
  if (n === null || n === undefined) return '—'
  return `${n}%`
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

export default function AttendanceSummary() {
  const today = new Date()
  const defaultTo = today.toISOString().slice(0, 10)
  const defaultFrom = new Date(today.getTime() - 12 * 7 * 86400000)
    .toISOString().slice(0, 10)

  const [fromDate, setFromDate] = useState(defaultFrom)
  const [toDate, setToDate] = useState(defaultTo)

  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(null)

  async function load() {
    setLoading(true)
    setError(null)
    try {
      const res = await getAttendanceSummaryReport({
        from_date: fromDate,
        to_date: toDate,
      })
      setData(res.data)
    } catch (e) {
      setError(e?.response?.data?.message ?? 'Failed to load report.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load() }, [])

  // Chart data: total attendance per Sunday, oldest first
  const chartData = data?.sundays
    ? [...data.sundays].reverse().map(s => ({
        date: s.date_label,
        total: s.total_count,
        adults: s.adult_count,
        children: s.children_count,
      }))
    : []

  // Collect all cell names across all Sundays for the breakdown chart
  const cellNames = data?.sundays
    ? [...new Set(data.sundays.flatMap(s => s.by_cell.map(c => c.name)))]
    : []

  // Stacked bar data: one bar per Sunday, segments per cell
  const stackedData = data?.sundays
    ? [...data.sundays].reverse().map(s => {
        const point = { date: s.date_label }
        for (const cell of s.by_cell) {
          point[cell.name] = cell.count
        }
        return point
      })
    : []

  const ws = data?.summary?.welfare_summary
  const totalWelfare = ws ? ws.engaged + ws.moderate + ws.at_risk + ws.inactive_risk : 0

  return (
    <div className="space-y-6">
      <div>
        <h1 className="font-bold" style={{fontFamily:'var(--font-display)',fontSize:'32px',color:'var(--color-navy)'}}>
          Leaders' Meeting Report
        </h1>
        <p style={{color:'#44474f',marginTop:'4px'}}>
          Church attendance summary with welfare overview — for leadership accountability and council meetings
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
        <button onClick={load} disabled={loading}
                className="btn-primary px-6 py-2">
          {loading ? 'Loading...' : 'Update Report'}
        </button>
        <DownloadReportMenu
          pdfHandler={() => downloadAttendanceSummaryPdf({ from_date: fromDate, to_date: toDate })}
          csvHandler={() => downloadAttendanceSummaryXlsx({ from_date: fromDate, to_date: toDate })}
          filenameBase={`leaders-meeting-report-${fromDate}-to-${toDate}`}
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
          {/* ── KPI CARDS ──────────────────────────────────────── */}
          <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
            <SummaryCard
              label="Overall Attendance Rate"
              value={formatPct(data.summary.overall_attendance_rate)}
              sub={`Across ${data.summary.total_sundays} Sundays`} />
            <SummaryCard
              label="Avg Attendance / Sunday"
              value={formatCount(data.summary.avg_per_sunday)}
              sub={`${formatCount(data.summary.avg_adults)} adults · ${formatCount(data.summary.avg_children)} children`} />
            <SummaryCard
              label="Active Members"
              value={formatCount(data.summary.total_active_members)}
              sub={`${data.cell_summary?.length ?? 0} Cells`} />
            <SummaryCard
              label="Attendance Trend"
              value={
                <span style={{color: trendColor(data.summary.trend.direction)}}>
                  {trendIcon(data.summary.trend.direction)} {data.summary.trend.direction}
                </span>
              }
              sub={`${formatCount(data.summary.trend.recent_avg)} vs ${formatCount(data.summary.trend.prior_avg)} avg`} />
          </div>

          {/* ── CELL / CLASS BREAKDOWN TABLE ────────────────────── */}
          <div className="bg-white rounded-xl overflow-hidden"
               style={{border:'1px solid var(--color-surface-border)'}}>
            <div className="px-6 py-4" style={{borderBottom:'1px solid var(--color-surface-border)'}}>
              <h2 className="font-bold"
                  style={{fontFamily:'var(--font-display)',fontSize:'20px',color:'var(--color-navy)'}}>
                Cell / Class Breakdown
              </h2>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead style={{backgroundColor:'#edeef1'}}>
                  <tr>
                    <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Cell / Class</th>
                    <th className="text-right px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Members</th>
                    <th className="text-right px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Avg Att.</th>
                    <th className="text-right px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Rate</th>
                    <th className="text-right px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Contribution</th>
                    <th className="text-center px-4 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Welfare</th>
                    <th className="text-right px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Notes</th>
                  </tr>
                </thead>
                <tbody>
                  {data.cell_summary.map(cell => {
                    const grandTotal = data.cell_summary.reduce((s, c) => s + c.avg_attendance, 0)
                    const contribution = grandTotal > 0 ? ((cell.avg_attendance / grandTotal) * 100).toFixed(1) : '0'
                    return (
                      <tr key={cell.cell_id} style={{borderTop:'1px solid var(--color-surface-border)'}}>
                        <td className="px-6 py-3 font-medium" style={{color:'var(--color-navy)'}}>{cell.name}</td>
                        <td className="px-6 py-3 text-right font-mono">{cell.member_count}</td>
                        <td className="px-6 py-3 text-right font-mono">{formatCount(cell.avg_attendance)}</td>
                        <td className="px-6 py-3 text-right font-mono">
                          {cell.attendance_rate !== null ? (
                            <span style={{color: cell.attendance_rate < 50 ? '#dc2626' : undefined, fontWeight: cell.attendance_rate < 50 ? 700 : undefined}}>
                              {formatPct(cell.attendance_rate)}
                            </span>
                          ) : '—'}
                        </td>
                        <td className="px-6 py-3 text-right font-mono">{contribution}%</td>
                        <td className="px-4 py-3">
                          <div className="flex justify-center gap-1">
                            {Object.entries(cell.welfare_distribution).map(([flag, count]) =>
                              count > 0 ? (
                                <span key={flag} className="inline-block px-1.5 py-0.5 rounded text-xs font-bold"
                                      style={{backgroundColor: WELFARE_BG[flag], color: WELFARE_COLORS[flag]}}>
                                  {count}
                                </span>
                              ) : null
                            )}
                          </div>
                        </td>
                        <td className="px-6 py-3 text-right font-mono">{cell.recent_pastoral_notes_count}</td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          </div>

          {/* ── STATE OF THE MEMBERS ───────────────────────────── */}
          <div className="bg-white rounded-xl p-4 md:p-6"
               style={{border:'1px solid var(--color-surface-border)'}}>
            <h2 className="font-bold mb-4"
                style={{fontFamily:'var(--font-display)',fontSize:'20px',color:'var(--color-navy)'}}>
              State of the Members
            </h2>
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
              {Object.entries(ws).map(([flag, count]) => (
                <div key={flag} className="rounded-lg p-4 text-center"
                     style={{backgroundColor: WELFARE_BG[flag], border:`1px solid ${WELFARE_COLORS[flag]}20`}}>
                  <div className="text-xs font-bold uppercase tracking-wider" style={{color: WELFARE_COLORS[flag]}}>
                    {flag.replace('_', ' ')}
                  </div>
                  <div className="mt-1 font-bold" style={{fontSize:'28px', color: WELFARE_COLORS[flag]}}>
                    {formatCount(count)}
                  </div>
                  <div className="text-xs" style={{color:'#6b7280'}}>
                    {totalWelfare > 0 ? ((count / totalWelfare) * 100).toFixed(1) : 0}%
                  </div>
                </div>
              ))}
            </div>
          </div>

          {/* ── CELLS AT RISK CALLOUT ──────────────────────────── */}
          {data.summary.cells_at_risk?.length > 0 && (
            <div className="rounded-xl p-4" style={{backgroundColor:'#FFF7ED',border:'1px solid #FDBA74'}}>
              <div className="font-bold text-sm mb-1" style={{color:'#9A3412'}}>
                ⚠ Cells Requiring Attention
              </div>
              <p className="text-sm" style={{color:'#78350F'}}>
                The following cells have an average attendance rate below 50% and may require pastoral follow-up:{' '}
                <strong>{data.summary.cells_at_risk.join(', ')}</strong>
              </p>
            </div>
          )}

          {/* ── Total attendance line chart ─────────────────────── */}
          <div className="bg-white rounded-xl p-4 md:p-6"
               style={{border:'1px solid var(--color-surface-border)'}}>
            <h2 className="font-bold mb-4"
                style={{fontFamily:'var(--font-display)',fontSize:'20px',color:'var(--color-navy)'}}>
              Weekly Attendance Trend
            </h2>
            {chartData.length === 0 ? (
              <p style={{color:'#9ca3af'}}>No data in selected range.</p>
            ) : (
              <ResponsiveContainer width="100%" height={320}>
                <LineChart data={chartData} margin={{top:20,right:20,left:0,bottom:20}}>
                  <CartesianGrid strokeDasharray="3 3" stroke="#e1e2e5" />
                  <XAxis dataKey="date" stroke="#44474f" angle={-30} textAnchor="end" height={60} />
                  <YAxis stroke="#44474f" />
                  <Tooltip formatter={(v) => formatCount(v)} />
                  <Legend />
                  <Line type="monotone" dataKey="total" name="Total" stroke="#1b3a6b" strokeWidth={2} dot={{r:3}} activeDot={{r:5}} />
                  <Line type="monotone" dataKey="adults" name="Adults" stroke="#2e7d32" strokeWidth={1.5} dot={{r:2}} strokeDasharray="5 5" />
                  <Line type="monotone" dataKey="children" name="Children" stroke="#c9a84c" strokeWidth={1.5} dot={{r:2}} strokeDasharray="5 5" />
                </LineChart>
              </ResponsiveContainer>
            )}
          </div>

          {/* ── Per-cell stacked bar chart ──────────────────────── */}
          {cellNames.length > 0 && (
            <div className="bg-white rounded-xl p-4 md:p-6"
                 style={{border:'1px solid var(--color-surface-border)'}}>
              <h2 className="font-bold mb-4"
                  style={{fontFamily:'var(--font-display)',fontSize:'20px',color:'var(--color-navy)'}}>
                Attendance by Cell
              </h2>
              {stackedData.length === 0 ? (
                <p style={{color:'#9ca3af'}}>No data in selected range.</p>
              ) : (
                <ResponsiveContainer width="100%" height={360}>
                  <BarChart data={stackedData} margin={{top:20,right:20,left:0,bottom:20}}>
                    <CartesianGrid strokeDasharray="3 3" stroke="#e1e2e5" />
                    <XAxis dataKey="date" stroke="#44474f" angle={-30} textAnchor="end" height={60} />
                    <YAxis stroke="#44474f" />
                    <Tooltip formatter={(v) => formatCount(v)} />
                    <Legend />
                    {cellNames.map((name, idx) => (
                      <Bar key={name} dataKey={name} stackId="cells" fill={COLORS[idx % COLORS.length]} />
                    ))}
                  </BarChart>
                </ResponsiveContainer>
              )}
            </div>
          )}

          {/* ── Per-Sunday breakdown table ──────────────────────── */}
          <div className="bg-white rounded-xl overflow-hidden"
               style={{border:'1px solid var(--color-surface-border)'}}>
            <div className="px-6 py-4" style={{borderBottom:'1px solid var(--color-surface-border)'}}>
              <h2 className="font-bold"
                  style={{fontFamily:'var(--font-display)',fontSize:'20px',color:'var(--color-navy)'}}>
                Sunday Breakdown
              </h2>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead style={{backgroundColor:'#edeef1'}}>
                  <tr>
                    <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Sunday</th>
                    <th className="text-right px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Adults</th>
                    <th className="text-right px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Children</th>
                    <th className="text-right px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Total</th>
                    <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Cell Breakdown</th>
                  </tr>
                </thead>
                <tbody>
                  {data.sundays.map(s => (
                    <tr key={s.service_date} style={{borderTop:'1px solid var(--color-surface-border)'}}>
                      <td className="px-6 py-3 font-medium" style={{color:'var(--color-navy)'}}>{s.date_label}</td>
                      <td className="px-6 py-3 text-right font-mono">{formatCount(s.adult_count)}</td>
                      <td className="px-6 py-3 text-right font-mono">{formatCount(s.children_count)}</td>
                      <td className="px-6 py-3 text-right font-mono font-bold">{formatCount(s.total_count)}</td>
                      <td className="px-6 py-3 text-xs" style={{color:'#6b7280'}}>
                        {s.by_cell.map(c => (
                          <span key={c.name}>
                            {c.name}: {formatCount(c.count)}
                            {c.name !== s.by_cell[s.by_cell.length - 1].name && ', '}
                          </span>
                        ))}
                      </td>
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
