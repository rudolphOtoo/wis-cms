import { useState, useEffect } from 'react'
import { getCellComparisonReport, downloadCellComparisonPdf, downloadCellComparisonCsv } from '../../api/reports'
import DownloadReportMenu from '../../components/reports/DownloadReportMenu'
import { BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid } from 'recharts'

// Bar color per cell — assigned in order rather than by health.
// Health is communicated via badges in the table, not by chart color.
const BAR_COLOR = '#1b3a6b'

// Health flag labels and tint for badges in the table.
// Keep these gentle (amber dot) rather than alarming (red) — these
// are prompts to act, not failures.
const FLAG_META = {
  no_leader:            { label: 'No leader',    tint: '#c9a84c' },
  no_recent_attendance: { label: 'No recent attendance', tint: '#c9a84c' },
  low_membership:       { label: 'Low membership',       tint: '#c87533' },
  high_inactive_rate:   { label: 'High inactive rate',   tint: '#ba1a1a' },
}

function formatRate(rate) {
  if (rate === null || rate === undefined) return '—'
  return rate.toFixed(1) + '%'
}

function formatDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('en-GH', { month: 'short', day: 'numeric', year: 'numeric' })
}

export default function CellComparison() {
  const [weeks, setWeeks] = useState(4)
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(null)

  const load = async () => {
    setLoading(true)
    setError(null)
    try {
      const res = await getCellComparisonReport({ weeks })
      setData(res.data)
    } catch (err) {
      setError(err?.response?.data?.message || 'Failed to load report')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load() }, [])

  const sortedCells = data?.cells ? [...data.cells].sort((a, b) => b.member_count - a.member_count) : []
  const chartData = sortedCells.map(c => ({ name: c.name, members: c.member_count }))

  return (
    <div className="space-y-6">
      <div>
        <h1 className="font-bold" style={{fontFamily:'var(--font-display)',fontSize:'32px',color:'var(--color-navy)'}}>
          Cell Comparison
        </h1>
        <p style={{color:'#44474f',marginTop:'4px'}}>
          Cell-by-cell health snapshot: leaders, members, recent attendance, intervention signals
        </p>
      </div>

      {/* Filter */}
      <div className="bg-white rounded-xl p-4 md:p-6 flex flex-wrap items-end gap-4"
           style={{border:'1px solid var(--color-surface-border)'}}>
        <div className="flex flex-col">
          <label className="text-xs font-bold uppercase tracking-wider mb-1" style={{color:'#44474f'}}>Recency Window</label>
          <select value={weeks} onChange={e => setWeeks(parseInt(e.target.value, 10))}
                  className="px-3 py-2 rounded-lg" style={{border:'1px solid var(--color-surface-border)'}}>
            <option value={2}>Last 2 weeks</option>
            <option value={4}>Last 4 weeks</option>
            <option value={8}>Last 8 weeks</option>
            <option value={12}>Last 12 weeks</option>
          </select>
        </div>
        <button onClick={load} disabled={loading} className="btn-primary px-6 py-2">
          {loading ? 'Loading...' : 'Update Report'}
        </button>
        <DownloadReportMenu
          pdfHandler={() => downloadCellComparisonPdf({ weeks })}
          csvHandler={() => downloadCellComparisonCsv({ weeks })}
          filenameBase={`cell-comparison-${new Date().toISOString().slice(0,10)}`}
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
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
            <SummaryCard
              label="Total Cells"
              value={data.summary.total_cells}
              sub={`${data.summary.total_members} total members`} />
            <SummaryCard
              label="With Leader"
              value={`${data.summary.cells_with_leader} / ${data.summary.total_cells}`}
              sub={data.summary.cells_with_leader === data.summary.total_cells ? 'All assigned' : 'Need leaders'} />
            <SummaryCard
              label="Recording Attendance"
              value={`${data.summary.cells_with_recent_attendance} / ${data.summary.total_cells}`}
              sub={`Last ${data.period.weeks} weeks`} />
            <SummaryCard
              label="Avg Attendance Rate"
              value={data.summary.avg_attendance_rate !== null ? data.summary.avg_attendance_rate.toFixed(1) + '%' : '—'}
              sub={data.summary.avg_attendance_rate !== null ? 'Across measured cells' : 'Not enough data'} />
          </div>

          {/* Per-cell health table */}
          <div className="bg-white rounded-xl overflow-hidden"
               style={{border:'1px solid var(--color-surface-border)'}}>
            <div className="px-6 py-4" style={{borderBottom:'1px solid var(--color-surface-border)'}}>
              <h2 className="font-bold"
                  style={{fontFamily:'var(--font-display)',fontSize:'20px',color:'var(--color-navy)'}}>
                Cell Health Snapshot
              </h2>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead style={{backgroundColor:'#edeef1'}}>
                  <tr>
                    <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Cell</th>
                    <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Leader</th>
                    <th className="text-right px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Members</th>
                    <th className="text-right px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Sessions</th>
                    <th className="text-right px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Rate</th>
                    <th className="text-right px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#15803d'}}>Engaged</th>
                    <th className="text-right px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#ca8a04'}}>At Risk</th>
                    <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Last Session</th>
                    <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Flags</th>
                  </tr>
                </thead>
                <tbody>
                  {sortedCells.map(c => (
                    <tr key={c.id} style={{borderTop:'1px solid var(--color-surface-border)'}}>
                      <td className="px-6 py-3 font-medium" style={{color:'var(--color-navy)'}}>{c.name}</td>
                      <td className="px-6 py-3" style={{color: c.leader ? '#44474f' : '#9ca3af', fontStyle: c.leader ? 'normal' : 'italic'}}>
                        {c.leader?.name ?? 'No leader'}
                      </td>
                      <td className="px-6 py-3 text-right font-mono">{c.member_count}</td>
                      <td className="px-6 py-3 text-right font-mono">{c.recent_sessions}</td>
                      <td className="px-6 py-3 text-right font-mono">{formatRate(c.recent_attendance_rate)}</td>
                      <td className="px-6 py-3 text-right font-mono" style={{color:'#15803d'}}>{c.welfare_distribution?.engaged ?? 0}</td>
                      <td className="px-6 py-3 text-right font-mono" style={{color:'#ba1a1a'}}>{(c.welfare_distribution?.at_risk ?? 0) + (c.welfare_distribution?.inactive_risk ?? 0)}</td>
                      <td className="px-6 py-3" style={{color:'#44474f'}}>{formatDate(c.last_session_date)}</td>
                      <td className="px-6 py-3">
                        <div className="flex flex-wrap gap-1">
                          {c.health_flags.length === 0 ? (
                            <span style={{color:'#2e7d32', fontSize:'12px'}}>✓ Healthy</span>
                          ) : (
                            c.health_flags.map(flag => (
                              <FlagBadge key={flag} flag={flag} />
                            ))
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          {/* Member count chart */}
          <div className="bg-white rounded-xl p-4 md:p-6"
               style={{border:'1px solid var(--color-surface-border)'}}>
            <h2 className="font-bold mb-4"
                style={{fontFamily:'var(--font-display)',fontSize:'20px',color:'var(--color-navy)'}}>
              Active Members per Cell
            </h2>
            {chartData.length === 0 ? (
              <p style={{color:'#9ca3af'}}>No cells to display.</p>
            ) : (
              <ResponsiveContainer width="100%" height={Math.max(200, chartData.length * 50)}>
                <BarChart data={chartData} layout="vertical" margin={{top:10,right:30,left:60,bottom:10}}>
                  <CartesianGrid strokeDasharray="3 3" stroke="#e1e2e5" />
                  <XAxis type="number" stroke="#44474f" />
                  <YAxis type="category" dataKey="name" stroke="#44474f" width={140} />
                  <Tooltip formatter={(v) => v + ' members'} />
                  <Bar dataKey="members" fill={BAR_COLOR} />
                </BarChart>
              </ResponsiveContainer>
            )}
          </div>
        </>
      )}

      {!data && !loading && !error && (
        <div className="bg-white rounded-xl p-6 text-center" style={{border:'1px solid var(--color-surface-border)',color:'#9ca3af'}}>
          Loading cell health snapshot...
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

function FlagBadge({ flag }) {
  const meta = FLAG_META[flag]
  if (!meta) return null
  return (
    <span style={{
      display:'inline-flex',
      alignItems:'center',
      gap:'4px',
      padding:'2px 8px',
      borderRadius:'12px',
      fontSize:'11px',
      backgroundColor: meta.tint + '22',  // 22 = ~13% opacity
      color: meta.tint,
      fontWeight: 600,
    }}>
      <span style={{width:'6px',height:'6px',borderRadius:'50%',backgroundColor:meta.tint}}></span>
      {meta.label}
    </span>
  )
}
