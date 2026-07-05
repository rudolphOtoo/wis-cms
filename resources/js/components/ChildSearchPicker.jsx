import { useEffect, useMemo, useRef, useState } from 'react'

export default function ChildSearchPicker({
  records = [],
  value = '',
  onChange,
  placeholder = 'Search by name or class...',
  disabled = false,
}) {
  const [query, setQuery] = useState('')
  const [open, setOpen] = useState(false)
  const containerRef = useRef(null)

  useEffect(() => {
    const handler = (e) => {
      if (containerRef.current && !containerRef.current.contains(e.target)) {
        setOpen(false)
      }
    }
    document.addEventListener('mousedown', handler)
    return () => document.removeEventListener('mousedown', handler)
  }, [])

  const selectedChild = useMemo(() => {
    return value ? records.find(c => c.id === value) : null
  }, [value, records])

  const filteredChildren = useMemo(() => {
    if (!query.trim()) {
      return [...records]
        .sort((a, b) => (a.full_name ?? '').localeCompare(b.full_name ?? ''))
        .slice(0, 50)
    }
    const q = query.toLowerCase().trim()
    return records
      .filter(c => {
        const name = (c.full_name ?? `${c.first_name ?? ''} ${c.last_name ?? ''}`).toLowerCase()
        const cls = (c.class_group ?? '').toLowerCase()
        return name.includes(q) || cls.includes(q)
      })
      .slice(0, 50)
  }, [query, records])

  const handleSelect = (child) => {
    onChange?.(child.id)
    setQuery('')
    setOpen(false)
  }

  const handleClear = () => {
    onChange?.('')
    setQuery('')
  }

  const displayName = selectedChild
    ? selectedChild.full_name ?? `${selectedChild.first_name ?? ''} ${selectedChild.last_name ?? ''}`.trim()
    : ''

  return (
    <div className="relative flex-1" ref={containerRef}>
      {selectedChild ? (
        <div
          className="input-field flex items-center justify-between"
          style={{ cursor: disabled ? 'not-allowed' : 'pointer' }}
          onClick={() => { if (!disabled) { onChange?.(''); setQuery('') } }}
        >
          <div>
            <span className="font-semibold" style={{ color: 'var(--color-navy)' }}>
              {displayName}
            </span>
            <span className="text-sm ml-2" style={{ color: '#6b7280' }}>
              {selectedChild.class_group
                ? `${selectedChild.class_group} · ${selectedChild.age ?? ''} yrs`
                : selectedChild.age
                  ? `${selectedChild.age} yrs`
                  : ''}
            </span>
          </div>
          <button
            type="button"
            onClick={(e) => { e.stopPropagation(); handleClear() }}
            disabled={disabled}
            className="text-xl px-2"
            style={{ color: '#6b7280', cursor: 'pointer' }}
            aria-label="Clear selection"
          >
            ×
          </button>
        </div>
      ) : (
        <>
          <input
            type="text"
            className="input-field w-full"
            placeholder={placeholder}
            value={query}
            onChange={(e) => { setQuery(e.target.value); setOpen(true) }}
            onFocus={() => setOpen(true)}
            disabled={disabled}
          />
          {open && (
            <div
              className="absolute left-0 right-0 z-10 mt-1 rounded-lg shadow-lg max-h-80 overflow-y-auto"
              style={{
                backgroundColor: 'white',
                border: '1px solid var(--color-surface-border)',
              }}
            >
              {filteredChildren.length === 0 ? (
                <div className="px-4 py-3 text-sm" style={{ color: '#6b7280' }}>
                  {query.trim() ? 'No children match your search.' : 'No children available.'}
                </div>
              ) : (
                <>
                  {filteredChildren.map(c => (
                    <button
                      key={c.id}
                      type="button"
                      onClick={() => handleSelect(c)}
                      className="w-full text-left px-4 py-2.5 hover:bg-gray-50 transition-colors"
                      style={{ borderBottom: '1px solid #f3f4f6' }}
                    >
                      <div className="font-medium" style={{ color: 'var(--color-navy)' }}>
                        {c.full_name ?? `${c.first_name ?? ''} ${c.last_name ?? ''}`.trim()}
                      </div>
                      <div className="text-xs" style={{ color: '#6b7280' }}>
                        {c.class_group ?? 'No class'}
                        {c.age ? ` · ${c.age} yrs` : ''}
                        {c.guardian ? ` · Parent: ${c.guardian.name}` : ''}
                      </div>
                    </button>
                  ))}
                  {filteredChildren.length === 50 && records.length > 50 && !query.trim() && (
                    <div className="px-4 py-2 text-xs italic" style={{ color: '#9ca3af', backgroundColor: '#f9fafb' }}>
                      Showing first 50. Type to search through {records.length} children.
                    </div>
                  )}
                </>
              )}
            </div>
          )}
        </>
      )}
    </div>
  )
}