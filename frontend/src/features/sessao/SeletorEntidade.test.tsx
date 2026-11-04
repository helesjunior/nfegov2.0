import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { describe, it, expect, vi, beforeEach } from 'vitest'

const trocarMock = vi.fn()
vi.mock('../../lib/api', () => ({
  sessaoApi: { trocarTenant: (...a: unknown[]) => trocarMock(...a) },
}))

const recarregarMock = vi.fn()
vi.mock('../auth/session', () => ({
  useSessao: () => ({
    usuario: {
      id: 1, name: 'X', email: 'x@x', tenant_id: null,
      tenant_ativo: { id: 1, nome: 'Entidade A' },
      tenants_disponiveis: [{ id: 1, nome: 'Entidade A' }, { id: 2, nome: 'Entidade B' }],
      pode_todas: true, papeis: [], permissoes: [], mfa_pendente: false, mfa_confirmado: true,
    },
    recarregar: recarregarMock,
  }),
}))

import { SeletorEntidade } from './SeletorEntidade'

function renderizar() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <SeletorEntidade />
    </QueryClientProvider>,
  )
}

describe('SeletorEntidade', () => {
  beforeEach(() => {
    trocarMock.mockReset().mockResolvedValue({ tenant_ativo: { id: 2, nome: 'Entidade B' } })
    recarregarMock.mockReset().mockResolvedValue(undefined)
  })

  it('lista Todas + entidades disponíveis para super-admin', () => {
    renderizar()
    const select = screen.getByRole('combobox', { name: /entidade/i })
    expect(select).toBeInTheDocument()
    expect(screen.getByRole('option', { name: /todas as entidades/i })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: /entidade b/i })).toBeInTheDocument()
  })

  it('troca de entidade e recarrega a sessão', async () => {
    const user = userEvent.setup()
    renderizar()

    await user.selectOptions(screen.getByRole('combobox', { name: /entidade/i }), '2')

    await waitFor(() => expect(trocarMock).toHaveBeenCalledWith(2))
    await waitFor(() => expect(recarregarMock).toHaveBeenCalled())
  })
})