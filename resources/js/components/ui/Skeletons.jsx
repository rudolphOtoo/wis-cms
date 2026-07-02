import { BORDER } from '../../constants/styles'

const COL_WIDTHS = ['45%', '62%', '55%', '40%', '68%', '50%', '72%', '48%']

export function StatCardSkeleton() {
  return (
    <div className="surface-card p-6 animate-pulse">
      <div className="flex justify-between items-start mb-4">
        <div className="h-3 w-28 rounded bg-slate-200" />
        <div className="w-9 h-9 rounded-lg bg-slate-200" />
      </div>
      <div className="h-10 w-24 rounded bg-slate-200" />
    </div>
  )
}

export function TableSkeleton({ rows = 8, cols = 6, hasAvatar = false }) {
  return (
    <>
      {Array.from({ length: rows }).map((_, rowIdx) => (
        <tr key={rowIdx} style={{ borderTop: BORDER }}>
          {hasAvatar ? (
            <td style={{ padding: '16px 24px' }}>
              <div className="flex items-center gap-3 animate-pulse">
                <div className="w-10 h-10 rounded-full bg-slate-200 flex-shrink-0" />
                <div className="space-y-2">
                  <div className="h-3 w-28 rounded bg-slate-200" />
                  <div className="h-2.5 w-20 rounded bg-slate-100" />
                </div>
              </div>
            </td>
          ) : null}
          {Array.from({ length: hasAvatar ? cols - 1 : cols }).map((_, colIdx) => (
            <td key={colIdx} style={{ padding: '16px 24px' }}>
              <div
                className="h-3 rounded bg-slate-200 animate-pulse"
                style={{ width: COL_WIDTHS[(rowIdx + colIdx) % COL_WIDTHS.length] }}
              />
            </td>
          ))}
        </tr>
      ))}
    </>
  )
}

export function DashboardSkeleton() {
  return (
    <div className="space-y-6" style={{ maxWidth: '1440px' }}>
      <div className="animate-pulse rounded-xl h-28 bg-gradient-to-r from-slate-200 to-slate-100" />
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        {Array.from({ length: 4 }).map((_, i) => <StatCardSkeleton key={i} />)}
      </div>
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {Array.from({ length: 2 }).map((_, i) => (
          <div key={i} className="surface-card p-6 animate-pulse">
            <div className="h-4 w-40 rounded bg-slate-200 mb-6" />
            <div className="h-60 rounded bg-slate-100" />
          </div>
        ))}
      </div>
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {Array.from({ length: 3 }).map((_, i) => (
          <div key={i} className="surface-card p-6 animate-pulse">
            <div className="h-4 w-32 rounded bg-slate-200 mb-6" />
            <div className="space-y-4">
              {Array.from({ length: 4 }).map((_, j) => (
                <div
                  key={j}
                  className="h-3 rounded bg-slate-200"
                  style={{ width: COL_WIDTHS[(i + j) % COL_WIDTHS.length] }}
                />
              ))}
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}
