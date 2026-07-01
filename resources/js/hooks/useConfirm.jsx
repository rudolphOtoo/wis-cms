import { useState, useCallback, useRef } from 'react'
import ConfirmDialog from '../components/ConfirmDialog'

export function useConfirm() {
  const [state, setState] = useState({ open: false, title: '', message: '', confirmLabel: 'Confirm', variant: 'danger' })
  const resolveRef = useRef(null)

  const confirm = useCallback((message, options = {}) => {
    const { title = 'Confirm', confirmLabel = 'Confirm', variant = 'danger' } = options
    return new Promise((resolve) => {
      resolveRef.current = resolve
      setState({ open: true, title, message, confirmLabel, variant })
    })
  }, [])

  const handleConfirm = useCallback(() => {
    resolveRef.current?.(true)
    setState(s => ({ ...s, open: false }))
  }, [])

  const handleCancel = useCallback(() => {
    resolveRef.current?.(false)
    setState(s => ({ ...s, open: false }))
  }, [])

  const dialog = (
    <ConfirmDialog
      open={state.open}
      title={state.title}
      message={state.message}
      confirmLabel={state.confirmLabel}
      variant={state.variant}
      onConfirm={handleConfirm}
      onCancel={handleCancel}
    />
  )

  return { confirm, dialog }
}
