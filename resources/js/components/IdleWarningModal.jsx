import { useEffect, useRef } from 'react'
import { AlertTriangle, X } from 'lucide-react'

export default function IdleWarningModal({ open, remainingSeconds, onStayLoggedIn, onLogoutNow }) {
  const dialogRef = useRef(null)
  const stayRef = useRef(null)

  useEffect(() => {
    if (open) stayRef.current?.focus()
  }, [open])

  useEffect(() => {
    if (!open) return
    const handler = (e) => {
      if (e.key === 'Escape') onLogoutNow()
    }
    document.addEventListener('keydown', handler)
    return () => document.removeEventListener('keydown', handler)
  }, [open, onLogoutNow])

  useEffect(() => {
    if (!open || !dialogRef.current) return

    const dialog = dialogRef.current
    const focusable = dialog.querySelectorAll(
      'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
    )
    if (focusable.length === 0) return

    const first = focusable[0]
    const last = focusable[focusable.length - 1]

    const trap = (e) => {
      if (e.key === 'Tab') {
        if (e.shiftKey && document.activeElement === first) {
          e.preventDefault()
          last.focus()
        } else if (!e.shiftKey && document.activeElement === last) {
          e.preventDefault()
          first.focus()
        }
      }
    }

    document.addEventListener('keydown', trap)
    return () => document.removeEventListener('keydown', trap)
  }, [open])

  if (!open) return null

  const progress = Math.max(0, (remainingSeconds / 60) * 100)

  return (
    <div
      className="fixed inset-0 flex items-center justify-center z-50 p-4"
      style={{ backgroundColor: 'rgba(13,31,60,0.4)', backdropFilter: 'blur(4px)' }}
      role="presentation"
    >
      <div
        ref={dialogRef}
        className="bg-white w-full max-w-md rounded-2xl shadow-2xl overflow-hidden"
        style={{ animation: 'fadeIn 0.15s ease-out' }}
        role="dialog"
        aria-modal="true"
        aria-labelledby="idle-warning-title"
      >
        <div className="px-6 pt-6 pb-4 flex items-start gap-4">
          <div
            className="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0"
            style={{ backgroundColor: '#fef9c3' }}
          >
            <AlertTriangle size={20} strokeWidth={1.8} style={{ color: '#ca8a04' }} />
          </div>
          <div className="flex-1 min-w-0">
            <h3
              id="idle-warning-title"
              className="font-bold text-lg"
              style={{ fontFamily: 'var(--font-display)', color: 'var(--color-navy)' }}
            >
              Session Expiring Soon
            </h3>
            <p className="text-sm mt-1" style={{ color: '#6b7280', lineHeight: 1.5 }}>
              Your session is about to expire due to inactivity.
            </p>
          </div>
          <button
            type="button"
            onClick={onLogoutNow}
            className="p-1 rounded hover:bg-gray-100 transition-colors flex-shrink-0"
            style={{ color: '#9ca3af' }}
            aria-label="Close"
          >
            <X size={18} strokeWidth={2} />
          </button>
        </div>

        {/* Countdown + progress bar */}
        <div className="px-6 pb-2">
          <div className="flex items-center justify-between mb-2">
            <span className="text-sm font-medium" style={{ color: 'var(--color-navy)' }}>
              Logging out in {remainingSeconds} second{remainingSeconds !== 1 ? 's' : ''}...
            </span>
          </div>
          <div className="w-full h-1.5 rounded-full overflow-hidden" style={{ backgroundColor: '#e5e7eb' }}>
            <div
              className="h-full rounded-full transition-all duration-1000 ease-linear"
              style={{
                width: `${progress}%`,
                backgroundColor: remainingSeconds <= 10 ? '#dc2626' : '#ca8a04',
              }}
            />
          </div>
        </div>

        {/* Actions */}
        <div className="px-6 pb-6 pt-4 flex justify-end gap-3">
          <button
            type="button"
            onClick={onLogoutNow}
            className="px-5 py-2 rounded-lg text-sm font-semibold transition-colors"
            style={{
              backgroundColor: 'white',
              border: '1px solid var(--color-surface-border)',
              color: '#374151',
            }}
          >
            Log Out Now
          </button>
          <button
            type="button"
            ref={stayRef}
            onClick={onStayLoggedIn}
            className="px-5 py-2 rounded-lg text-sm font-semibold transition-colors text-white"
            style={{ backgroundColor: 'var(--color-navy)' }}
          >
            Stay Logged In
          </button>
        </div>
      </div>
    </div>
  )
}
