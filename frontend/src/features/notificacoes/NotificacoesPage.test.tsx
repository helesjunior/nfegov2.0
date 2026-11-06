import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { NotificacoesPage } from './NotificacoesPage'

/**
 * Central de notificações.
 *
 * A mesma tela serve às exportações (com link de download) e às recuperações
 * manuais de NF-e (apenas aviso). O texto é escolhido pelo `tipo` do payload.
 */

const listarMock = vi.fn()

vi.mock('../../lib/api', async () => {
  const actual = await vi.importActual<typeof import('../../lib/api')>('../../lib/api')
  return {
    ...actual,
    notificacoesApi: {
      listar: (...args: unknown[]) => listarMock(...args),
      marcarComoLida: vi.fn(),
    },
  }
})

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  return render(
    <QueryClientProvider client={queryClient}>
      <NotificacoesPage />
    </QueryClientProvider>,
  )
}

describe('NotificacoesPage', () => {
  beforeEach(() => {
    listarMock.mockReset()
  })

  it('renderiza a mensagem de recuperação pelo tipo', async () => {
    listarMock.mockResolvedValue({
      data: [
        {
          id: 'n1',
          lida: false,
          criada_em: '2026-11-06T10:00:00+00:00',
          dados: {
            tipo: 'recuperacao',
            mensagem: 'Busca de NSU 150 concluída, mas nenhum documento foi encontrado.',
            status: 'nao_encontrado',
          },
          url_download: null,
        },
      ],
      nao_lidas: 1,
    })

    renderPage()

    expect(await screen.findByText(/nenhum documento foi encontrado/i)).toBeInTheDocument()
  })

  it('renderiza a mensagem de exportação quando o tipo está ausente', async () => {
    listarMock.mockResolvedValue({
      data: [
        {
          id: 'n2',
          lida: true,
          criada_em: '2026-11-06T10:00:00+00:00',
          dados: { formato: 'csv', total: 42 },
          url_download: 'http://localhost/exports/x.csv',
        },
      ],
      nao_lidas: 0,
    })

    renderPage()

    expect(await screen.findByText(/exportação csv pronta \(42 registro/i)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /baixar/i })).toBeInTheDocument()
  })
})