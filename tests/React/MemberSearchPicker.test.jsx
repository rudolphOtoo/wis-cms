import { describe, it, expect, vi } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import MemberSearchPicker from '../../resources/js/components/MemberSearchPicker'

/**
 * The guardian field on the child form runs this picker in server-side
 * mode (results arrive via `members`, typing is reported through
 * `onQueryChange`). The name rendering below is a contract, not a detail:
 * ChildrenResource returns the chosen guardian as `{ name }` while
 * MemberResource returns `full_name`, so a caller that forgets to alias
 * one onto the other renders a guardian chip with a blank name. These
 * tests pin the two shapes the picker is expected to handle.
 */
describe('MemberSearchPicker', () => {
  const fullNameMembers = [
    { id: '1', full_name: 'Zebedee Okoro', phone: '0245555555', member_number: 'M-001' },
    { id: '2', full_name: 'Grace Ansah', phone: '0247778888', member_number: 'M-002' },
  ]

  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders the selected member name from full_name', () => {
    render(
      <MemberSearchPicker members={fullNameMembers} value="2" onChange={vi.fn()} />,
    )

    expect(screen.getByText('Grace Ansah')).toBeInTheDocument()
    expect(screen.getByText(/0247778888/)).toBeInTheDocument()
  })

  it('renders the selected member name from first/last when full_name is absent', () => {
    render(
      <MemberSearchPicker
        members={[{ id: '1', first_name: 'Kofi', last_name: 'Mensah', phone: '0241001001' }]}
        value="1"
        onChange={vi.fn()}
      />,
    )

    expect(screen.getByText(/Kofi Mensah/)).toBeInTheDocument()
  })

  it('reports each keystroke to onQueryChange for server-side search', () => {
    const onQueryChange = vi.fn()
    render(
      <MemberSearchPicker
        members={fullNameMembers}
        value=""
        onChange={vi.fn()}
        onQueryChange={onQueryChange}
      />,
    )

    fireEvent.change(screen.getByPlaceholderText(/Search by name or phone/), {
      target: { value: 'Zeb' },
    })

    expect(onQueryChange).toHaveBeenCalledWith('Zeb')
  })

  it('filters client-side without requiring onQueryChange', () => {
    render(<MemberSearchPicker members={fullNameMembers} value="" onChange={vi.fn()} />)

    const input = screen.getByPlaceholderText(/Search by name or phone/)
    fireEvent.focus(input)
    fireEvent.change(input, { target: { value: 'Grace' } })

    expect(screen.getByText('Grace Ansah')).toBeInTheDocument()
    expect(screen.queryByText('Zebedee Okoro')).not.toBeInTheDocument()
  })

  it('matches on phone and member number as well as name', () => {
    render(<MemberSearchPicker members={fullNameMembers} value="" onChange={vi.fn()} />)

    const input = screen.getByPlaceholderText(/Search by name or phone/)
    fireEvent.focus(input)
    fireEvent.change(input, { target: { value: 'M-002' } })

    expect(screen.getByText('Grace Ansah')).toBeInTheDocument()
    expect(screen.queryByText('Zebedee Okoro')).not.toBeInTheDocument()
  })

  it('selects by id and resets the query so the list returns to its default', () => {
    const onChange = vi.fn()
    const onQueryChange = vi.fn()
    render(
      <MemberSearchPicker
        members={fullNameMembers}
        value=""
        onChange={onChange}
        onQueryChange={onQueryChange}
      />,
    )

    const input = screen.getByPlaceholderText(/Search by name or phone/)
    fireEvent.focus(input)
    fireEvent.change(input, { target: { value: 'Zebedee' } })
    fireEvent.click(screen.getByText('Zebedee Okoro'))

    expect(onChange).toHaveBeenCalledWith('1')
    expect(onQueryChange).toHaveBeenLastCalledWith('')
  })

  it('clears the selection through the chip and resets the query', () => {
    const onChange = vi.fn()
    const onQueryChange = vi.fn()
    render(
      <MemberSearchPicker
        members={fullNameMembers}
        value="1"
        onChange={onChange}
        onQueryChange={onQueryChange}
      />,
    )

    fireEvent.click(screen.getByLabelText('Clear selection'))

    expect(onChange).toHaveBeenCalledWith('')
    expect(onQueryChange).toHaveBeenCalledWith('')
  })

  it('reports "Searching..." while a server-side query is in flight', () => {
    render(
      <MemberSearchPicker
        members={[]}
        value=""
        onChange={vi.fn()}
        onQueryChange={vi.fn()}
        searching
      />,
    )

    fireEvent.focus(screen.getByPlaceholderText(/Search by name or phone/))

    expect(screen.getByText('Searching...')).toBeInTheDocument()
  })
})
