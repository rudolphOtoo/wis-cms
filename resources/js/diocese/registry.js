import { getBootstrap } from '../api/auth'

const STORAGE_KEY = 'wis_diocese'

let diocese = null

export async function loadDiocese() {
  if (diocese) return diocese

  const cached = localStorage.getItem(STORAGE_KEY)
  if (cached) {
    try {
      diocese = JSON.parse(cached)
      return diocese
    } catch {
      /* fall through to network */
    }
  }

  // Never call the authenticated bootstrap endpoint without a token. The
  // 401 interceptor hard-redirects to /login, so firing it from the login
  // page (this effect runs on every mount) would cause an infinite reload
  // loop. Unauthenticated visitors only need the server-injected branding
  // (window.APP_META); capabilities load after login via CapabilityGate.
  if (!localStorage.getItem('wis_token')) {
    diocese = null
    return diocese
  }

  try {
    const { data } = await getBootstrap()
    diocese = data.diocese
    localStorage.setItem(STORAGE_KEY, JSON.stringify(diocese))
  } catch {
    diocese = null
  }

  return diocese
}

export function getDiocese() {
  return diocese
}

/**
 * Read a dotted path from the active profile's `capabilities`.
 * Missing values fall back to `fallback` (false by default).
 */
export function capability(path, fallback = false) {
  const value = path
    .split('.')
    .reduce((acc, key) => (acc && typeof acc === 'object' ? acc[key] : undefined), diocese?.capabilities)
  return value === undefined || value === null ? fallback : value
}

/**
 * Profile branding injected server-side into the shell (welcome.blade.php
 * / dashboard.blade.php). Available synchronously before React mounts, so
 * Login / Sidebar / Portal can render the right logo and titles on the
 * very first paint — no network round-trip, no flash of the wrong brand.
 */
export function appMeta() {
  return (typeof window !== 'undefined' && window.APP_META) || {}
}

/**
 * Read a dotted path from the active profile's `strings`.
 * Dotted keys are stored literally (e.g. 'app.title'), so a direct lookup
 * is tried first, with nested-array traversal as a fallback.
 */
export function string(path, fallback = '') {
  const src = diocese?.strings ?? {}
  if (Object.prototype.hasOwnProperty.call(src, path)) return src[path]
  const value = path
    .split('.')
    .reduce((acc, key) => (acc && typeof acc === 'object' ? acc[key] : undefined), src)
  return value === undefined || value === null ? fallback : value
}

export function clearDiocese() {
  diocese = null
  localStorage.removeItem(STORAGE_KEY)
}
