import { useState, useEffect, useRef } from 'react'
import { getMemberWelfareReport, downloadMemberWelfarePdf, downloadMemberWelfareXlsx } from '../../api/reports'
import DownloadReportMenu from '../../components/reports/DownloadReportMenu'
import {
  BarChart, Bar, XAxis, YAxis, Tooltip, Legend,
  ResponsiveContainer, CartesianGrid, PieChart, Pie, Cell,
} from 'recharts'

const FLAG_COLORS = {
  engaged: '#15803d',
  moderate: '#2e7d32',
  at_risk: '#ca8a04',
  inactive_risk: '#ba1a1a',
  none: '#9ca3af',
}

function formatCount(n) {
  if (n === null || n === undefined) return '—'
  return n.toLocaleString('en-GH')
}

export default function MemberWelfare() {
  const [welfareStatus, setWelfareStatus] = useState('all')
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(null)

  async function load() {
    setLoading(true)
    setError(null)
    try {
      const params = {}
      if (welfareStatus !== 'all') params.welfare_status = welfareStatus
      const res = await getMemberWelfareReport(params)
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

  // Pie chart data from flag counts
  const pieData = data?.summary?.flag_counts
    ? Object.entries(data.summary.flag_counts)
        .filter(([_, count]) => count > 0)
        .map(([flag, count]) => ({ name: flag.replace('_', ' '), value: count, flag }))
    : []

  // Bar chart data from cell breakdown
  const cellBarData = data?.summary?.by_cell ?? []

  return (
    <div className="space-y-6">
      <div>
        <h1 className="font-bold" style={{fontFamily:'var(--font-display)',fontSize:'32px',color:'var(--color-navy)'}}>
          Member Welfare Report
        </h1>
        <p style={{color:'#44474f',marginTop:'4px'}}>
          Member engagement and welfare tracking for leadership accountability
        </p>
      </div>

      {/* Filters */}
      <div className="bg-white rounded-xl p-4 md:p-6 flex flex-wrap items-end gap-4"
           style={{border:'1px solid var(--color-surface-border)'}}>
        <div className="flex flex-col">
          <label className="text-xs font-bold uppercase tracking-wider mb-1" style={{color:'#44474f'}}>Welfare Status</label>
          <select value={welfareStatus} onChange={e => setWelfareStatus(e.target.value)}
                  className="px-3 py-2 rounded-lg" style={{border:'1px solid var(--color-surface-border)'}}>
            <option value="all">All</option>
            <option value="engaged">Engaged</option>
            <option value="moderate">Moderate</option>
            <option value="at_risk">At Risk</option>
            <option value="inactive_risk">Inactive Risk</option>
          </select>
        </div>
        <button onClick={load} disabled={loading}
                className="btn-primary px-6 py-2">
          {loading ? 'Loading...' : 'Update Report'}
        </button>
        <DownloadReportMenu
          pdfHandler={() => downloadMemberWelfarePdf({ welfare_status: welfareStatus })}
          xlsxHandler={() => downloadMemberWelfareXlsx({ welfare_status: welfareStatus })}
          filenameBase={`member-welfare-${welfareStatus}`}
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
              label="Total Members"
              value={formatCount(data.summary.total_members)}
              sub={`${data.period.window_weeks}-week window`} />
            <SummaryCard
              label="Avg Attendance"
              value={data.summary.avg_attendance_rate + '%'}
              sub={`${data.summary.total_sundays_in_window} Sundays in window`} />
            <SummaryCard
              label="Engaged"
              value={formatCount(data.summary.flag_counts?.engaged ?? 0)}
              sub={`>= 75% attendance`}
              valueColor="#15803d" />
            <SummaryCard
              label="At Risk / Inactive"
              value={formatCount((data.summary.flag_counts?.at_risk ?? 0) + (data.summary.flag_counts?.inactive_risk ?? 0))}
              sub={`Needs follow-up`}
              valueColor="#ba1a1a" />
          </div>

          {/* Charts row */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            {/* Welfare distribution pie chart */}
            <div className="bg-white rounded-xl p-4 md:p-6"
                 style={{border:'1px solid var(--color-surface-border)'}}>
              <h2 className="font-bold mb-4"
                  style={{fontFamily:'var(--font-display)',fontSize:'20px',color:'var(--color-navy)'}}>
                Welfare Distribution
              </h2>
              {pieData.length === 0 ? (
                <p style={{color:'#9ca3af'}}>No data.</p>
              ) : (
                <ResponsiveContainer width="100%" height={280}>
                  <PieChart>
                    <Pie
                      data={pieData}
                      cx="50%"
                      cy="50%"
                      innerRadius={60}
                      outerRadius={100}
                      paddingAngle={3}
                      dataKey="value"
                      label={({ name, value }) => `${name}: ${value}`}
                    >
                      {pieData.map((entry) => (
                        <Cell key={entry.flag} fill={FLAG_COLORS[entry.flag] || '#9ca3af'} />
                      ))}
                    </Pie>
                    <Tooltip />
                  </PieChart>
                </ResponsiveContainer>
              )}
            </div>

            {/* Per-cell welfare bar chart */}
            <div className="bg-white rounded-xl p-4 md:p-6"
                 style={{border:'1px solid var(--color-surface-border)'}}>
              <h2 className="font-bold mb-4"
                  style={{fontFamily:'var(--font-display)',fontSize:'20px',color:'var(--color-navy)'}}>
                Welfare by Cell
              </h2>
              {cellBarData.length === 0 ? (
                <p style={{color:'#9ca3af'}}>No data.</p>
              ) : (
                <ResponsiveContainer width="100%" height={280}>
                  <BarChart data={cellBarData} margin={{top:10,right:10,left:0,bottom:20}}>
                    <CartesianGrid strokeDasharray="3 3" stroke="#e1e2e5" />
                    <XAxis dataKey="name" stroke="#44474f" angle={-20} textAnchor="end" height={50} />
                    <YAxis stroke="#44474f" />
                    <Tooltip />
                    <Legend />
                    <Bar dataKey="engaged" stackId="welfare" fill="#15803d" />
                    <Bar dataKey="moderate" stackId="welfare" fill="#2e7d32" />
                    <Bar dataKey="at_risk" stackId="welfare" fill="#ca8a04" />
                    <Bar dataKey="inactive_risk" stackId="welfare" fill="#ba1a1a" />
                  </BarChart>
                </ResponsiveContainer>
              )}
            </div>
          </div>

          {/* Member table */}
          <div className="bg-white rounded-xl overflow-hidden"
               style={{border:'1px solid var(--color-surface-border)'}}>
            <div className="px-6 py-4" style={{borderBottom:'1px solid var(--color-surface-border)'}}>
              <h2 className="font-bold"
                  style={{fontFamily:'var(--font-display)',fontSize:'20px',color:'var(--color-navy)'}}>
                Member Details ({data.members.length})
              </h2>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead style={{backgroundColor:'#edeef1'}}>
                  <tr>
                    <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Name</th>
                    <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Cell</th>
                    <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Flag</th>
                    <th className="text-right px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Rate</th>
                    <th className="text-right px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Attended</th>
                    <th className="text-right px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Giving</th>
                    <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Last Attendance</th>
                  </tr>
                </thead>
                <tbody>
                  {data.members.map(m => (
                    <tr key={m.id} style={{borderTop:'1px solid var(--color-surface-border)'}}>
                      <td className="px-6 py-3 font-medium" style={{color:'var(--color-navy)'}}>{m.name}</td>
                      <td className="px-6 py-3">{m.cell_name}</td>
                      <td className="px-6 py-3">
                        <span className="inline-block px-2 py-0.5 rounded-full text-xs font-semibold"
                              style={{backgroundColor: FLAG_COLORS[m.welfare_flag] + '20', color: FLAG_COLORS[m.welfare_flag]}}>
                          {m.welfare_flag.replace('_', ' ')}
                        </span>
                      </td>
                      <td className="px-6 py-3 text-right font-mono">{m.attendance_rate}%</td>
                      <td className="px-6 py-3 text-right font-mono">{m.attended_services}/{m.total_sundays_in_window}</td>
                      <td className="px-6 py-3 text-right font-mono">GHS {m.giving_total.toLocaleString('en-GH', {minimumFractionDigits:2})}</td>
                      <td className="px-6 py-3">{m.last_attendance_date ?? '—'}</td>
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
          Click Update Report to load member welfare data.
        </div>
      )}
    </div>
  )
}

function SummaryCard({ label, value, sub, valueColor }) {
  return (
    <div className="bg-white rounded-xl p-5"
         style={{border:'1px solid var(--color-surface-border)'}}>
      <div className="text-xs font-bold uppercase tracking-wider" style={{color:'#44474f'}}>{label}</div>
      <div className="mt-2 font-bold"
           style={{fontFamily:'var(--font-display)',fontSize:'28px',color: valueColor || 'var(--color-navy)'}}>
        {value}
      </div>
      {sub && <div className="text-xs mt-1" style={{color:'#9ca3af'}}>{sub}</div>}
    </div>
  )
}
