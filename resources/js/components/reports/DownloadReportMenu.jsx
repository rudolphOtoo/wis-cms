import { useState, useRef, useEffect } from 'react'
import { toast } from 'sonner'

import { NAVY, MUTED, PLACEHOLDER, BORDER, FONT_DISPLAY } from '../../constants/styles'
/**
 * A small download menu button. Click to open dropdown with PDF + CSV options.
 *
 * Props:
 *   pdfHandler:  () => Promise<{ data: Blob }>   axios call returning blob
 *   csvHandler:  () => Promise<{ data: Blob }>   axios call returning blob
 *   filenameBase: string  (without extension; '.pdf' or '.csv' appended)
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

  const trigger = async (handler, ext) => {
    setOpen(false)
    setDownloading(true)
    try {
      const res = await handler()
      const blob = res.data instanceof Blob
        ? res.data
        : new Blob([res.data], { type: ext === 'pdf' ? 'application/pdf' : 'text/csv' })
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
        aria-haspopup="menu"
        aria-expanded={open}
        className="px-4 py-2 rounded-lg font-medium inline-flex items-center gap-2"
        style={{
          border: BORDER,
          backgroundColor: disabled || downloading ? '#f3f4f6' : 'white',
          color: NAVY,
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
            border: BORDER,
            boxShadow: '0 4px 12px rgba(0,0,0,0.08)',
            minWidth: '160px',
          }}
        >
          <button
            type="button"
            onClick={() => trigger(pdfHandler, 'pdf')}
            className="block w-full text-left px-4 py-2 text-sm"
            style={{ color: NAVY, borderBottom: BORDER }}
            onMouseEnter={e => e.currentTarget.style.backgroundColor = '#f8f9fa'}
            onMouseLeave={e => e.currentTarget.style.backgroundColor = 'white'}
          >
            <span style={{ fontWeight: 600 }}>PDF</span>
            <span style={{ color: PLACEHOLDER, marginLeft: '6px', fontSize: '11px' }}>For printing / council</span>
          </button>
          <button
            type="button"
            onClick={() => trigger(csvHandler, 'csv')}
            className="block w-full text-left px-4 py-2 text-sm"
            style={{ color: NAVY }}
            onMouseEnter={e => e.currentTarget.style.backgroundColor = '#f8f9fa'}
            onMouseLeave={e => e.currentTarget.style.backgroundColor = 'white'}
          >
            <span style={{ fontWeight: 600 }}>CSV</span>
            <span style={{ color: PLACEHOLDER, marginLeft: '6px', fontSize: '11px' }}>Opens in Excel</span>
          </button>
        </div>
      )}
    </div>
  )
}
