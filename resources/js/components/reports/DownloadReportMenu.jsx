import { useState, useRef, useEffect } from 'react'
import { toast } from 'sonner'

/**
 * A small download menu button. Click to open dropdown with PDF + CSV options.
 *
 * Props:
 *   pdfHandler:  () => Promise<{ data: Blob }>   axios call returning blob
 *   csvHandler:  () => Promise<{ data: Blob }>   axios call returning blob
 *   filenameBase: string  (without extension; '.pdf' or '.xlsx' appended)
 *   disabled?:   boolean  (e.g. while data is still loading)
 */
export default function DownloadReportMenu({ pdfHandler, csvHandler, filenameBase, disabled = false }) {
  const [open, setOpen] = useState(false)
  const [downloading, setDownloading] = useState(false)
  const ref = useRef(null)

  // Close dropdown when clicking outside
  useEffect(() => {
    function onClick(e) {
      if (ref.current && !ref.current.contains(e.target)) {
        setOpen(false)
      }
    }
    if (open) document.addEventListener('mousedown', onClick)
    return () => document.removeEventListener('mousedown', onClick)
  }, [open])

  const trigger = async (handler) => {
    setOpen(false)
    setDownloading(true)
    try {
      const res = await handler()

      // Derive the real format from the server's Content-Type instead of
      // assuming every non-PDF export is XLSX. CSV endpoints stream
      // text/csv; only the attendance summary streams a real xlsx
      // (spreadsheetml). Previously CSV bytes were saved with a .xlsx
      // extension (BUG-007).
      const contentType = res.headers?.['content-type'] ?? ''
      let ext = 'csv'
      let mime = 'text/csv'
      if (contentType.includes('spreadsheetml')) {
        ext = 'xlsx'
        mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
      } else if (contentType.includes('pdf')) {
        ext = 'pdf'
        mime = 'application/pdf'
      }

      const blob = res.data instanceof Blob
        ? res.data
        : new Blob([res.data], { type: mime })
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `${filenameBase}.${ext}`
      document.body.appendChild(a)
      a.click()
      document.body.removeChild(a)
      URL.revokeObjectURL(url)
    } catch (err) {
      console.error('Download failed:', err)
      toast.error('Could not download the report. Please try again.')
    } finally {
      setDownloading(false)
    }
  }

  return (
    <div ref={ref} className="relative inline-block">
      <button
        type="button"
        onClick={() => setOpen(!open)}
        disabled={disabled || downloading}
        className="px-4 py-2 rounded-lg font-medium inline-flex items-center gap-2"
        style={{
          border: '1px solid var(--color-surface-border)',
          backgroundColor: disabled || downloading ? '#f3f4f6' : 'white',
          color: 'var(--color-navy)',
          cursor: disabled || downloading ? 'not-allowed' : 'pointer',
        }}
      >
        {downloading ? (
          <span>Downloading…</span>
        ) : (
          <>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3" />
            </svg>
            <span>Download</span>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <path d="M6 9l6 6 6-6" />
            </svg>
          </>
        )}
      </button>

      {open && (
        <div
          className="absolute right-0 mt-1 z-10 rounded-lg overflow-hidden"
          style={{
            backgroundColor: 'white',
            border: '1px solid var(--color-surface-border)',
            boxShadow: '0 4px 12px rgba(0,0,0,0.08)',
            minWidth: '160px',
          }}
        >
          <button
            type="button"
            onClick={() => trigger(pdfHandler)}
            className="block w-full text-left px-4 py-2 text-sm"
            style={{ color: 'var(--color-navy)', borderBottom: '1px solid var(--color-surface-border)' }}
            onMouseEnter={e => e.currentTarget.style.backgroundColor = '#f8f9fa'}
            onMouseLeave={e => e.currentTarget.style.backgroundColor = 'white'}
          >
            <span style={{ fontWeight: 600 }}>PDF</span>
            <span style={{ color: '#9ca3af', marginLeft: '6px', fontSize: '11px' }}>For printing / council</span>
          </button>
          <button
            type="button"
            onClick={() => trigger(csvHandler)}
            className="block w-full text-left px-4 py-2 text-sm"
            style={{ color: 'var(--color-navy)' }}
            onMouseEnter={e => e.currentTarget.style.backgroundColor = '#f8f9fa'}
            onMouseLeave={e => e.currentTarget.style.backgroundColor = 'white'}
          >
            <span style={{ fontWeight: 600 }}>Excel</span>
            <span style={{ color: '#9ca3af', marginLeft: '6px', fontSize: '11px' }}>Opens in Excel</span>
          </button>
        </div>
      )}
    </div>
  )
}
