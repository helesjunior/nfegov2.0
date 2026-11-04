import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { NfeListPage } from './NfeListPage'

/**
 * Lista de NF-e.
 *
 * Cobre o essencial: renderiza resultados paginados e aplica o filtro de
 * período, repassando as datas à API.
 */

const listarMock = vi.fn()

vi.mock('../../lib/api', async () => {
  const actual = await vi.importActual<typeof import('../../lib/api')>('../../lib/api')
  return {
    ...actual,
    nfeApi: {
      listar: (...args: unknown[]) => listarMock(...args),
      obter: vi.fn(),
      exportar: vi.fn(),
      exportarXml: vi.fn(),
    },
    unidadesApi: { listar: vi.fn().mockResolvedValue({ data: [], meta: { total: 0 } }) },
  }
})

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/nfes']}>
        <Routes>
          <Route path="/nfes" element={<NfeListPage />} />
          <Route path="/nfes/:chave" element={<div>Detalhe da NF-e</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const paginaMock = {
  data: [
    {
      id: 1,
      chave: '35200111222333000181550010000010001000010010',
      numero: '100',
      serie: '1',
      modelo: '55',
      unidade_id: 1,
      emitente_cnpj: '11222333000181',
      emitente_nome: 'FORNECEDOR EXEMPLO LTDA',
      dest_cnpj: '00000000000191',
      valor_total: '1500.00',
      natureza_operacao: 'Venda',
      data_emissao: '2026-10-05T10:00:00+00:00',
      cstat: '100',
      estado: 'completo',
      emitente_proprio: false,
      tem_xml: true,
      xml_url: null,
      created_at: '2026-10-05T10:00:00+00:00',
    },
  ],
  links: {},
  meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
}

describe('NfeListPage', () => {
  beforeEach(() => {
    listarMock.mockReset()
  })

  it('lista as NF-e retornadas pela API', async () => {
    listarMock.mockResolvedValue(paginaMock)

    renderPage()

    expect(await screen.findByText(/FORNECEDOR EXEMPLO LTDA/i)).toBeInTheDocument()
    expect(screen.getByText(/1\.500,00/)).toBeInTheDocument()
  })

  it('aplica o filtro de período e repassa as datas à API', async () => {
    const user = userEvent.setup()
    listarMock.mockResolvedValue(paginaMock)

    renderPage()

    await screen.findByText(/FORNECEDOR EXEMPLO LTDA/i)

    await user.type(screen.getByLabelText(/^de$/i), '2026-10-01')
    await user.type(screen.getByLabelText(/^até$/i), '2026-10-31')
    await user.click(screen.getByRole('button', { name: /filtrar/i }))

    await waitFor(() =>
      expect(listarMock).toHaveBeenCalledWith(
        expect.objectContaining({ de: '2026-10-01', ate: '2026-10-31', page: 1 }),
      ),
    )
  })

  it('abre o detalhe ao clicar na linha', async () => {
    const user = userEvent.setup()
    listarMock.mockResolvedValue(paginaMock)

    renderPage()

    await user.click(await screen.findByText(/FORNECEDOR EXEMPLO LTDA/i))

    expect(await screen.findByText(/detalhe da nf-e/i)).toBeInTheDocument()
  })
})