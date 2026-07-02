import { useEffect, useRef } from 'react'
import { AlertTriangle, X } from 'lucide-react'

import { NAVY, MUTED, PLACEHOLDER, BORDER, FONT_DISPLAY } from '../constants/styles'
export default function ConfirmDialog({ open, title, message, confirmLabel = 'Confirm', variant = 'danger', onConfirm, onCancel }) {
  const confirmRef = useRef(null)
  const dialogRef = useRef(null)

  useEffect(() => {
    if (open) {
      confirmRef.current?.focus()
    }
  }, [open])

  useEffect(() => {
    if (!open) return

    const handler = (e) => {
      if (e.key === 'Escape') onCancel()
    }
    document.addEventListener('keydown', handler)
    return () => document.removeEventListener('keydown', handler)
  }, [open, onCancel])

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

  const isDanger = variant === 'danger'

  return (
    <div className="fixed inset-0 flex items-center justify-center z-50 p-4"
         style={{ backgroundColor: 'rgba(13,31,60,0.4)', backdropFilter: 'blur(4px)' }}
         role="presentation">
      <div ref={dialogRef}
           className="bg-white w-full max-w-md rounded-2xl shadow-2xl overflow-hidden"
           style={{ animation: 'fadeIn 0.15s ease-out' }}
           role="dialog"
           aria-modal="true"
           aria-labelledby="confirm-dialog-title">
        <div className="px-6 pt-6 pb-4 flex items-start gap-4">
          <div className="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0"
               style={{ backgroundColor: isDanger ? '#fef2f2' : '#f0f4f9' }}>
            <AlertTriangle size={20} strokeWidth={1.8}
              style={{ color: isDanger ? '#dc2626' : NAVY }} />
          </div>
          <div className="flex-1 min-w-0">
            <h3 id="confirm-dialog-title" className="font-bold text-lg" style={{ fontFamily: FONT_DISPLAY, color: NAVY }}>
              {title}
            </h3>
            <p className="text-sm mt-1" style={{ color: '#6b7280', lineHeight: 1.5 }}>
              {message}
            </p>
          </div>
          <button type="button" onClick={onCancel}
                  className="p-1 rounded hover:bg-gray-100 transition-colors flex-shrink-0"
                  style={{ color: PLACEHOLDER }} aria-label="Close">
            <X size={18} strokeWidth={2} />
          </button>
        </div>
        <div className="px-6 pb-6 pt-2 flex justify-end gap-3">
          <button type="button" onClick={onCancel}
                  className="px-5 py-2 rounded-lg text-sm font-semibold transition-colors"
                  style={{ backgroundColor: 'white', border: BORDER, color: '#374151' }}
                  aria-label="Cancel">
            Cancel
          </button>
          <button type="button" ref={confirmRef} onClick={onConfirm}
                  className="px-5 py-2 rounded-lg text-sm font-semibold transition-colors text-white"
                  style={{ backgroundColor: isDanger ? '#dc2626' : NAVY }}
                  aria-label={confirmLabel}>
            {confirmLabel}
          </button>
        </div>
      </div>
    </div>
  )
}
