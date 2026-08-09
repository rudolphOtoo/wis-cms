import { useState, useEffect, useRef } from 'react'
import {
  getLifeEventsYearReport,
  downloadLifeEventsYearPdf,
  downloadLifeEventsYearXlsx,
} from '../../api/reports'
import DownloadReportMenu from '../../components/reports/DownloadReportMenu'

const CURRENT_YEAR = new Date().getFullYear()

function formatDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('en-GH', { day: 'numeric', month: 'short', year: 'numeric' })
}

export default function LifeEventsYear() {
  const [year, setYear] = useState(CURRENT_YEAR)
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(null)

  const load = async () => {
    setLoading(true)
    setError(null)
    try {
      const res = await getLifeEventsYearReport({ year })
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

  return (
    <div className="space-y-6">
      <div>
        <h1 className="font-bold" style={{fontFamily:'var(--font-display)',fontSize:'32px',color:'var(--color-navy)'}}>
          Year in Review — Life Events
        </h1>
        <p style={{color:'#44474f',marginTop:'4px'}}>
          Deaths and births for the whole church announcement: those who left us &amp; those who were born
        </p>
      </div>

      {/* Filter */}
      <div className="bg-white rounded-xl p-4 md:p-6 flex flex-wrap items-end gap-4"
           style={{border:'1px solid var(--color-surface-border)'}}>
        <div className="flex flex-col">
          <label className="text-xs font-bold uppercase tracking-wider mb-1" style={{color:'#44474f'}}>Year</label>
          <input type="number" value={year} onChange={e => setYear(parseInt(e.target.value, 10) || CURRENT_YEAR)}
                 className="px-3 py-2 rounded-lg" style={{border:'1px solid var(--color-surface-border)',width:'120px'}} />
        </div>
        <button onClick={load} disabled={loading} className="btn-primary px-6 py-2">
          {loading ? 'Loading...' : 'Update Report'}
        </button>
        <DownloadReportMenu
          pdfHandler={() => downloadLifeEventsYearPdf({ year })}
          xlsxHandler={() => downloadLifeEventsYearXlsx({ year })}
          filenameBase={`life-events-year-${year}`}
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
          {/* KPI cards */}
          <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
            <KpiCard label="Total Deaths" value={data.totals.deaths} color="#991b1b" sub="Those who left us" />
            <KpiCard label="Total Births" value={data.totals.births} color="#166534" sub="Those who were born" />
            <KpiCard label="Total Records" value={data.totals.deaths + data.totals.births} color="var(--color-navy)" sub={`Year ${data.year}`} />
          </div>

          {/* Monthly breakdown */}
          <div className="bg-white rounded-xl overflow-hidden" style={{border:'1px solid var(--color-surface-border)'}}>
            <div className="px-6 py-4" style={{borderBottom:'1px solid var(--color-surface-border)'}}>
              <h2 className="font-bold" style={{fontFamily:'var(--font-display)',fontSize:'20px',color:'var(--color-navy)'}}>
                Monthly Breakdown
              </h2>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead style={{backgroundColor:'#edeef1'}}>
                  <tr>
                    <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Month</th>
                    <th className="text-right px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#991b1b'}}>Deaths</th>
                    <th className="text-right px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#166534'}}>Births</th>
                  </tr>
                </thead>
                <tbody>
                  {data.monthly.map(m => (
                    <tr key={m.month} style={{borderTop:'1px solid var(--color-surface-border)'}}>
                      <td className="px-6 py-3 font-medium" style={{color:'var(--color-navy)'}}>{m.label}</td>
                      <td className="px-6 py-3 text-right font-mono">{m.deaths}</td>
                      <td className="px-6 py-3 text-right font-mono">{m.births}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          {/* Deaths */}
          <div className="bg-white rounded-xl overflow-hidden" style={{border:'1px solid var(--color-surface-border)'}}>
            <div className="px-6 py-4" style={{borderBottom:'1px solid var(--color-surface-border)'}}>
              <h2 className="font-bold" style={{fontFamily:'var(--font-display)',fontSize:'20px',color:'#991b1b'}}>
                Those Who Left Us ({data.totals.deaths})
              </h2>
            </div>
            {data.deaths.length === 0 ? (
              <p className="px-6 py-8 text-center" style={{color:'#9ca3af'}}>No deaths recorded for {data.year}.</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead style={{backgroundColor:'#edeef1'}}>
                    <tr>
                      <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Date</th>
                      <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Name</th>
                      <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Notes</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.deaths.map((d, i) => (
                      <tr key={i} style={{borderTop:'1px solid var(--color-surface-border)'}}>
                        <td className="px-6 py-3" style={{color:'#6b7280'}}>{formatDate(d.event_date)}</td>
                        <td className="px-6 py-3 font-medium" style={{color:'var(--color-navy)'}}>{d.name}</td>
                        <td className="px-6 py-3" style={{color:'#9ca3af'}}>{d.notes ?? ''}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>

          {/* Births */}
          <div className="bg-white rounded-xl overflow-hidden" style={{border:'1px solid var(--color-surface-border)'}}>
            <div className="px-6 py-4" style={{borderBottom:'1px solid var(--color-surface-border)'}}>
              <h2 className="font-bold" style={{fontFamily:'var(--font-display)',fontSize:'20px',color:'#166534'}}>
                Those Who Were Born ({data.totals.births})
              </h2>
            </div>
            {data.births.length === 0 ? (
              <p className="px-6 py-8 text-center" style={{color:'#9ca3af'}}>No births recorded for {data.year}.</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead style={{backgroundColor:'#edeef1'}}>
                    <tr>
                      <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Date</th>
                      <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Baby</th>
                      <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Mother</th>
                      <th className="text-left px-6 py-3 font-bold uppercase tracking-wider text-xs" style={{color:'#44474f'}}>Notes</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.births.map((b, i) => (
                      <tr key={i} style={{borderTop:'1px solid var(--color-surface-border)'}}>
                        <td className="px-6 py-3" style={{color:'#6b7280'}}>{formatDate(b.event_date)}</td>
                        <td className="px-6 py-3 font-medium" style={{color:'var(--color-navy)'}}>{b.name}</td>
                        <td className="px-6 py-3" style={{color:'#44474f'}}>{b.mother_name || '—'}</td>
                        <td className="px-6 py-3" style={{color:'#9ca3af'}}>{b.notes ?? ''}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </>
      )}

      {!data && !loading && !error && (
        <div className="bg-white rounded-xl p-6 text-center" style={{border:'1px solid var(--color-surface-border)',color:'#9ca3af'}}>
          Loading year in review...
        </div>
      )}
    </div>
  )
}

function KpiCard({ label, value, color, sub }) {
  return (
    <div className="bg-white rounded-xl p-5" style={{border:'1px solid var(--color-surface-border)'}}>
      <div className="text-xs font-bold uppercase tracking-wider" style={{color:'#44474f'}}>{label}</div>
      <div className="mt-2 font-bold" style={{fontFamily:'var(--font-display)',fontSize:'28px',color}}>
        {value}
      </div>
      {sub && <div className="text-xs mt-1" style={{color:'#9ca3af'}}>{sub}</div>}
    </div>
  )
}
