import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor, fireEvent } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import ChildForm from '../../resources/js/pages/children/ChildForm'
import { getChild } from '../../resources/js/api/children'
import { getMembers } from '../../resources/js/api/members'

vi.mock('../../resources/js/api/children', () => ({
  getChild: vi.fn(),
  createChild: vi.fn(),
  updateChild: vi.fn(),
}))

vi.mock('../../resources/js/api/members', () => ({
  getMembers: vi.fn(),
}))

vi.mock('sonner', () => ({
  toast: { error: vi.fn(), success: vi.fn() },
}))

/**
 * Regression guard for the guardian field on the child form.
 *
 * ChildrenResource serialises the chosen guardian as
 * `{ id, name, member_number, phone }` — note `name`, not `full_name` —
 * while MemberResource (and therefore the search results) uses
 * `full_name`. The form has to bridge the two, otherwise the selected
 * guardian renders as a chip with an empty name next to their phone
 * number, and the admin cannot tell which guardian is already assigned.
 */
describe('ChildForm guardian field', () => {
  const childWithGuardian = {
    data: {
      data: {
        id: 'child-1',
        first_name: 'Kojo',
        last_name: 'Asante',
        gender: 'male',
        date_of_birth: '2016-04-02',
        class_group: 'K1',
        is_active: true,
        notes: '',
        guardian: {
          id: 'member-9',
          name: 'Zebedee Okoro',
          member_number: 'M-009',
          phone: '0245555555',
        },
      },
    },
  }

  const renderEditForm = () =>
    render(
      <MemoryRouter initialEntries={['/children/child-1/edit']}>
        <Routes>
          <Route path="/children/:id/edit" element={<ChildForm />} />
        </Routes>
      </MemoryRouter>,
    )

  beforeEach(() => {
    vi.clearAllMocks()
    getChild.mockResolvedValue(childWithGuardian)
    // The guardian is deliberately absent from the search results, forcing
    // the hydrated object to be the one the picker renders.
    getMembers.mockResolvedValue({ data: { data: [] } })
  })

  it('shows the assigned guardian name after the child loads', async () => {
    renderEditForm()

    await waitFor(() => {
      expect(screen.getByText('Zebedee Okoro')).toBeInTheDocument()
    })
  })

  it('queries the member search scoped to the branch rather than the cell', async () => {
    renderEditForm()

    await waitFor(() => expect(getMembers).toHaveBeenCalled())
    const [params] = getMembers.mock.calls[0]

    // A guardian may sit in any cell of the branch, so the cell-leader
    // scoping used elsewhere would wrongly hide them.
    expect(params.unscoped).toBe(1)
    expect(params.status).toBe('active')
  })

  it('searches the server as the guardian name is typed', async () => {
    renderEditForm()

    await waitFor(() => expect(getMembers).toHaveBeenCalled())
    getMembers.mockClear()

    const clear = screen.getByLabelText('Clear selection')
    fireEvent.click(clear)

    const input = screen.getByPlaceholderText(/Search guardian by name/)
    fireEvent.change(input, { target: { value: 'Okoro' } })

    await waitFor(() => {
      const calls = getMembers.mock.calls.map(([p]) => p)
      expect(calls.some(p => p.search === 'Okoro')).toBe(true)
    })
  })
})
