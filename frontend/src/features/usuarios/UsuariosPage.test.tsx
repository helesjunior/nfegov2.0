import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { describe, it, expect, vi, beforeEach } from 'vitest'

const listarMock = vi.fn()
vi.mock('../../lib/api', () => ({
  usuariosApi: { listar: (...a: unknown[]) => listarMock(...a) },
  tenantsApi: { listar: vi.fn().mockResolvedValue({ data: [], meta: {}, links: {} }) },
  mensagemDeErro: (e: unknown) => (e instanceof Error ? e.message : 'erro'),
}))

vi.mock('../auth/session', () => ({
  useSessao: () => ({
    usuario: {
      id: 1, name: 'A', email: 'a@a', tenant_id: null, tenant_ativo: null,
      tenants_disponiveis: [{ id: 1, nome: 'Entidade A' }], pode_todas: true,
      papeis: ['super-admin'], permissoes: ['usuario.gerenciar'], mfa_pendente: false, mfa_confirmado: true,
    },
    recarregar: vi.fn(),
  }),
}))

import { UsuariosPage } from './UsuariosPage'

function renderizar() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <UsuariosPage />
    </QueryClientProvider>,
  )
}

describe('UsuariosPage', () => {
  beforeEach(() => {
    listarMock.mockReset().mockResolvedValue({
      data: [{ id: 1, name: 'Operador Um', email: 'op@x', ativo: true, tenant_id: 1, papeis: ['operador'], tenants: [{ id: 1, nome: 'Entidade A' }], created_at: null }],
      meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 },
      links: {},
    })
  })

  it('lista usuários', async () => {
    renderizar()
    expect(await screen.findByText('Operador Um')).toBeInTheDocument()
  })
})