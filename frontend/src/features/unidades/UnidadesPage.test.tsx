import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { describe, it, expect, vi, beforeEach } from 'vitest'

const listarMock = vi.fn()
const atualizarMock = vi.fn()
vi.mock('../../lib/api', () => ({
  unidadesApi: {
    listar: (...a: unknown[]) => listarMock(...a),
    atualizar: (...a: unknown[]) => atualizarMock(...a),
    definirAtivo: vi.fn(),
  },
  mensagemDeErro: (e: unknown) => (e instanceof Error ? e.message : 'erro'),
}))

import { UnidadesPage } from './UnidadesPage'

function renderizar() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <UnidadesPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('UnidadesPage', () => {
  beforeEach(() => {
    listarMock.mockReset().mockResolvedValue({
      data: [{ id: 1, tenant_id: 5, codigo_unidade: null, nome: 'Unidade A', nome_resumido: null, cnpj: '11222333000181', ie: null, uf: 'SP', tp_amb: 2, ativo: true, certificado: null, created_at: null }],
      meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 },
      links: {},
    })
    atualizarMock.mockReset().mockResolvedValue({})
  })

  it('abre o formulário de edição com os dados da unidade', async () => {
    const user = userEvent.setup()
    renderizar()

    await user.click(await screen.findByRole('button', { name: /editar/i }))

    expect(screen.getByDisplayValue('Unidade A')).toBeInTheDocument()
  })
})