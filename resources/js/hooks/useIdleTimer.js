import { useEffect, useRef, useCallback } from 'react'

const ACTIVITY_EVENTS = ['mousemove', 'keydown', 'click', 'scroll', 'touchstart']
const THROTTLE_MS = 5000

export default function useIdleTimer({ timeout = 900, warningTimeout, onTimeout, onWarning }) {
  const warningMs = (warningTimeout ?? timeout - 60) * 1000
  const timeoutMs = timeout * 1000

  const lastActivityRef = useRef(0)
  const warningTimerRef = useRef(null)
  const timeoutTimerRef = useRef(null)
  const warningShownRef = useRef(false)

  const clearTimers = useCallback(() => {
    if (warningTimerRef.current) clearTimeout(warningTimerRef.current)
    if (timeoutTimerRef.current) clearTimeout(timeoutTimerRef.current)
    warningTimerRef.current = null
    timeoutTimerRef.current = null
  }, [])

  const startTimers = useCallback(() => {
    clearTimers()

    if (lastActivityRef.current === 0) return

    const elapsed = Date.now() - lastActivityRef.current
    const remainingWarning = Math.max(warningMs - elapsed, 0)
    const remainingTimeout = Math.max(timeoutMs - elapsed, 0)

    if (remainingWarning > 0 && !warningShownRef.current) {
      warningTimerRef.current = setTimeout(() => {
        warningShownRef.current = true
        onWarning?.()
      }, remainingWarning)
    } else if (warningShownRef.current && remainingTimeout > 0) {
      onWarning?.()
    }

    if (remainingTimeout > 0) {
      timeoutTimerRef.current = setTimeout(() => {
        onTimeout?.()
      }, remainingTimeout)
    } else if (lastActivityRef.current > 0) {
      onTimeout?.()
    }
  }, [warningMs, timeoutMs, onWarning, onTimeout, clearTimers])

  const reset = useCallback(() => {
    lastActivityRef.current = Date.now()
    warningShownRef.current = false
    startTimers()
  }, [startTimers])

  useEffect(() => {
    lastActivityRef.current = Date.now()

    let lastReset = 0

    const handler = () => {
      const now = Date.now()
      if (now - lastReset < THROTTLE_MS) return
      lastReset = now
      lastActivityRef.current = now

      if (warningShownRef.current) {
        warningShownRef.current = false
      }

      startTimers()
    }

    ACTIVITY_EVENTS.forEach((event) => {
      document.addEventListener(event, handler, { passive: true })
    })

    startTimers()

    return () => {
      ACTIVITY_EVENTS.forEach((event) => {
        document.removeEventListener(event, handler)
      })
      clearTimers()
    }
  }, [startTimers, clearTimers])

  useEffect(() => {
    const handler = () => {
      if (document.visibilityState === 'visible') {
        const elapsed = Date.now() - lastActivityRef.current
        if (elapsed >= timeoutMs) {
          onTimeout?.()
        } else {
          startTimers()
        }
      }
    }

    document.addEventListener('visibilitychange', handler)
    return () => document.removeEventListener('visibilitychange', handler)
  }, [timeoutMs, onTimeout, startTimers])

  return { reset }
}
