import { useEffect, useMemo, useRef, useState } from 'react'

import { NAVY, MUTED, PLACEHOLDER, BORDER, FONT_DISPLAY } from '../constants/styles'
/**
 * Searchable member picker. Filters a passed-in list of members by
 * name, phone, or member_number as the user types.
 */
export default function MemberSearchPicker({
  members = [],
  value = '',
  onChange,
  placeholder = 'Search by name or phone...',
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

  const selectedMember = useMemo(() => {
    return value ? members.find(m => m.id === value) : null
  }, [value, members])

  const filteredMembers = useMemo(() => {
    if (!query.trim()) {
      return [...members]
        .sort((a, b) => (a.full_name ?? `${a.first_name ?? ''} ${a.last_name ?? ''}`)
          .localeCompare(b.full_name ?? `${b.first_name ?? ''} ${b.last_name ?? ''}`))
        .slice(0, 50)
    }

    const q = query.toLowerCase().trim()
    return members
      .filter(m => {
        const name = (m.full_name ?? `${m.first_name ?? ''} ${m.last_name ?? ''}`).toLowerCase()
        const phone = (m.phone ?? '').toLowerCase()
        const num = (m.member_number ?? '').toLowerCase()
        return name.includes(q) || phone.includes(q) || num.includes(q)
      })
      .slice(0, 50)
  }, [query, members])

  const handleSelect = (member) => {
    onChange?.(member.id)
    setQuery('')
    setOpen(false)
  }

  const handleClear = () => {
    onChange?.('')
    setQuery('')
  }

  const displayName = selectedMember
    ? selectedMember.full_name ?? `${selectedMember.first_name ?? ''} ${selectedMember.last_name ?? ''}`.trim()
    : ''

  return (
    <div className="relative flex-1" ref={containerRef}>
      {selectedMember ? (
        <div
          className="input-field flex items-center justify-between"
          style={{ cursor: disabled ? 'not-allowed' : 'pointer' }}
          onClick={() => { if (!disabled) { onChange?.(''); setQuery('') } }}
          onKeyDown={(e) => { if (!disabled && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); onChange?.(''); setQuery('') } }}
          role="button"
          tabIndex={disabled ? -1 : 0}
        >
          <div>
            <span className="font-semibold" style={{ color: NAVY }}>
              {displayName}
            </span>
            <span className="text-sm ml-2" style={{ color: '#6b7280' }}>
              ({selectedMember.phone || selectedMember.member_number || 'No contact'})
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
            aria-label={placeholder}
          />
          {open && (
            <div
              className="absolute left-0 right-0 z-10 mt-1 rounded-lg shadow-lg max-h-80 overflow-y-auto"
              style={{
                backgroundColor: 'white',
                border: BORDER,
              }}
            >
              {filteredMembers.length === 0 ? (
                <div className="px-4 py-3 text-sm" style={{ color: '#6b7280' }}>
                  {query.trim() ? 'No members match your search.' : 'No members available.'}
                </div>
              ) : (
                <>
                  {filteredMembers.map(m => (
                    <button
                      key={m.id}
                      type="button"
                      onClick={() => handleSelect(m)}
                      className="w-full text-left px-4 py-2.5 hover:bg-gray-50 transition-colors"
                      style={{ borderBottom: '1px solid #f3f4f6' }}
                    >
                      <div className="font-medium" style={{ color: NAVY }}>
                        {m.full_name ?? `${m.first_name ?? ''} ${m.last_name ?? ''}`.trim()}
                      </div>
                      <div className="text-xs" style={{ color: '#6b7280' }}>
                        {m.phone}{m.member_number ? ` · ${m.member_number}` : ''}
                      </div>
                    </button>
                  ))}
                  {filteredMembers.length === 50 && members.length > 50 && !query.trim() && (
                    <div className="px-4 py-2 text-xs italic" style={{ color: PLACEHOLDER, backgroundColor: '#f9fafb' }}>
                      Showing first 50. Type to search through {members.length} members.
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
