import { useState, useEffect } from 'react'

/**
 * Returns a debounced version of `value` that only updates after `delay` ms
 * of inactivity. Use as a drop-in replacement for the raw value in
 * useCallback / useEffect dependency arrays that trigger API calls —
 * this prevents a new request on every keystroke.
 *
 * @param {*}      value - The value to debounce (typically a search string)
 * @param {number} delay - Milliseconds of silence before the value settles
 * @returns The latest settled value
 */
export function useDebounce(value, delay = 400) {
  const [debounced, setDebounced] = useState(value)

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delay)
    return () => clearTimeout(timer)
  }, [value, delay])

  return debounced
}
