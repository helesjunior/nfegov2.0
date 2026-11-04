import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { describe, it, expect, vi, beforeEach } from 'vitest'

const listarMock = vi.fn()
vi.mock('../../lib/api', () => ({
  tenantsApi: { listar: (...a: unknown[]) => listarMock(...a) },
  mensagemDeErro: (e: unknown) => (e instanceof Error ? e.message : 'erro'),
}))

import { TenantsPage } from './TenantsPage'

function renderizar() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <TenantsPage />
    </QueryClientProvider>,
  )
}

describe('TenantsPage', () => {
  beforeEach(() => {
    listarMock.mockReset().mockResolvedValue({
      data: [{ id: 1, nome: 'Prefeitura X', cnpj: '11222333000181', ativo: true, created_at: null }],
      meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 },
      links: {},
    })
  })

  it('lista entidades', async () => {
    renderizar()
    expect(await screen.findByText('Prefeitura X')).toBeInTheDocument()
  })

  it('reinicia o formulário ao trocar o alvo de edição', async () => {
    const user = userEvent.setup()
    listarMock.mockReset().mockResolvedValue({
      data: [
        { id: 1, nome: 'Entidade A', cnpj: '11222333000181', ativo: true, created_at: null },
        { id: 2, nome: 'Entidade B', cnpj: '11222333000182', ativo: true, created_at: null },
      ],
      meta: { current_page: 1, last_page: 1, per_page: 50, total: 2 },
      links: {},
    })

    renderizar()

    const editar = await screen.findAllByRole('button', { name: 'Editar' })

    await user.click(editar[0])
    expect(screen.getByLabelText('Nome')).toHaveValue('Entidade A')

    await user.click(editar[1])
    expect(screen.getByLabelText('Nome')).toHaveValue('Entidade B')
  })
})