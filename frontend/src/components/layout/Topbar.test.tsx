import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { describe, it, expect, vi } from 'vitest'
vi.mock('../../lib/api', () => ({ authApi: { logout: vi.fn() } }))
vi.mock('../../features/sessao/SeletorEntidade', () => ({ SeletorEntidade: () => null }))

import { Topbar } from './Topbar'

const usuario = {
  id: 1, name: 'Fulano', email: 'f@x', tenant_id: null, tenant_ativo: null,
  tenants_disponiveis: [], pode_todas: false, papeis: ['operador'], permissoes: [],
  mfa_pendente: false, mfa_confirmado: true, mfa_obrigatorio: false,
}

describe('Topbar', () => {
  it('o nome do usuário é um link para Minha conta', () => {
    const qc = new QueryClient()
    render(
      <QueryClientProvider client={qc}>
        <MemoryRouter>
          <Topbar usuario={usuario} />
        </MemoryRouter>
      </QueryClientProvider>,
    )
    const link = screen.getByRole('link', { name: /minha conta/i })
    expect(link).toHaveAttribute('href', '/minha-conta')
  })
})