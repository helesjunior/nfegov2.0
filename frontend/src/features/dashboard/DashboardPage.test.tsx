import { render, screen, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { DashboardPage } from './DashboardPage'
import type { DashboardResumo } from '../../lib/api'

/**
 * Painel inicial: bloco de NF-e como antes e, com a recepção de NFS-e ligada,
 * indicadores e gráficos próprios de NFS-e.
 */

const obterMock = vi.fn()

vi.mock('../../lib/api', async () => {
  const actual = await vi.importActual<typeof import('../../lib/api')>('../../lib/api')
  return { ...actual, dashboardApi: { obter: (...args: unknown[]) => obterMock(...args) } }
})

function dias(quantidades: number[], prefixo = '2026-10-') {
  return quantidades.map((q, i) => ({
    data: `${prefixo}${String(i + 1).padStart(2, '0')}`,
    quantidade: q,
    valor: q * 100,
  }))
}

const base: DashboardResumo = {
  nfes: { total: 10, ultimos_30_dias: 4, valor_ultimos_30_dias: 1500, resumos: 2 },
  nfses: {
    total: 763,
    ultimos_30_dias: 6,
    valor_ultimos_30_dias: 12345.67,
    canceladas_30_dias: 1,
    sem_manifestacao: 718,
  },
  unidades: { total: 3, ativas: 2 },
  certificados: { vencendo_30_dias: 1, vencidos: 0, expirados: 0 },
  sync: {
    ultimas: [
      { id: 1, unidade_id: 1, tipo: 'nfe', status: 'sucesso', docs_count: 3, iniciado_em: '2026-11-07T10:00:00Z' },
      { id: 2, unidade_id: 1, tipo: 'nfse', status: 'sucesso', docs_count: 60, iniciado_em: '2026-11-07T11:00:00Z' },
    ],
    com_erro_24h: 0,
    circuito_aberto: false,
    circuito_adn_aberto: false,
  },
  graficos: {
    por_dia: dias([1, 0, 3]),
    fornecedores: [{ cnpj: '1', nome: 'Fornecedor Alfa', quantidade: 2, valor: 1000 }],
    nfse_por_dia: dias([0, 2, 5], '2026-11-'),
    nfse_prestadores: [{ documento: '21952840000124', nome: 'Serralheria Exemplo', quantidade: 3, valor: 5000 }],
  },
}

function renderizar() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <DashboardPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('DashboardPage', () => {
  beforeEach(() => obterMock.mockReset())

  it('mostra os indicadores de NF-e e os de NFS-e', async () => {
    obterMock.mockResolvedValue(base)
    renderizar()

    expect(await screen.findByText('NF-e recebidas')).toBeInTheDocument()

    const secao = screen.getByRole('region', { name: 'NFS-e' })
    expect(within(secao).getByText('NFS-e recebidas').nextSibling).toHaveTextContent('763')
    expect(within(secao).getByText('NFS-e (30 dias)').nextSibling).toHaveTextContent('6')
    expect(within(secao).getByText('Serviços (30 dias)').nextSibling).toHaveTextContent('12.345,67')
    expect(within(secao).getByText('Sem manifestação').nextSibling).toHaveTextContent('718')
    expect(within(secao).getByText('Canceladas (30 dias)').nextSibling).toHaveTextContent('1')
  })

  it('desenha os gráficos de NFS-e com os textos de serviço', async () => {
    obterMock.mockResolvedValue(base)
    renderizar()

    const secao = await screen.findByRole('region', { name: 'NFS-e' })

    expect(within(secao).getByRole('heading', { name: 'NFS-e por dia' })).toBeInTheDocument()
    expect(within(secao).getByTestId('destaque-dia')).toHaveTextContent('7 NFS-e')
    expect(within(secao).getByRole('heading', { name: 'Maiores prestadores' })).toBeInTheDocument()
    expect(within(secao).getByText('Serralheria Exemplo')).toBeInTheDocument()
    // Os gráficos de NF-e seguem fora da seção de NFS-e.
    expect(screen.getByRole('heading', { name: 'NF-e por dia' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Maiores fornecedores' })).toBeInTheDocument()
  })

  it('avisa quantas notas estão sem manifestação e leva à lista', async () => {
    obterMock.mockResolvedValue(base)
    renderizar()

    const secao = await screen.findByRole('region', { name: 'NFS-e' })

    expect(within(secao).getByText(/718 nota\(s\) recebida\(s\) ainda sem manifestação/)).toBeInTheDocument()
    expect(within(secao).getByRole('link', { name: 'Ver NFS-e' })).toHaveAttribute('href', '/nfses')
  })

  it('com a recepção de NFS-e desligada, o bloco some e a NF-e fica igual', async () => {
    obterMock.mockResolvedValue({
      ...base,
      nfses: null,
      graficos: { ...base.graficos, nfse_por_dia: null, nfse_prestadores: null },
    })
    renderizar()

    expect(await screen.findByText('NF-e recebidas')).toBeInTheDocument()
    expect(screen.queryByRole('region', { name: 'NFS-e' })).not.toBeInTheDocument()
    expect(screen.queryByText('Maiores prestadores')).not.toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'NF-e por dia' })).toBeInTheDocument()
  })

  it('avisa quando o freio do ADN está aberto', async () => {
    obterMock.mockResolvedValue({ ...base, sync: { ...base.sync, circuito_adn_aberto: true } })
    renderizar()

    expect(await screen.findByText(/Ambiente de Dados Nacional \(NFS-e\) está temporariamente suspenso/)).toBeInTheDocument()
  })

  it('a tabela de sincronizações diz de qual documento é cada execução', async () => {
    obterMock.mockResolvedValue(base)
    renderizar()

    const tabela = (await screen.findByText('Últimas sincronizações')).nextElementSibling as HTMLElement
    const linhas = within(tabela).getAllByRole('row').slice(1)

    expect(linhas[0]).toHaveTextContent('NF-e')
    expect(linhas[1]).toHaveTextContent('NFS-e')
    expect(linhas[1]).toHaveTextContent('60')
  })

  it('sem nenhuma NFS-e, mostra os zeros e as mensagens de gráfico vazio', async () => {
    obterMock.mockResolvedValue({
      ...base,
      nfses: { total: 0, ultimos_30_dias: 0, valor_ultimos_30_dias: 0, canceladas_30_dias: 0, sem_manifestacao: 0 },
      graficos: { ...base.graficos, nfse_por_dia: dias([0, 0], '2026-11-'), nfse_prestadores: [] },
    })
    renderizar()

    const secao = await screen.findByRole('region', { name: 'NFS-e' })

    expect(within(secao).getByText('Nenhuma NFS-e emitida nos últimos 30 dias.')).toBeInTheDocument()
    expect(within(secao).getByText('Sem notas de prestadores no período.')).toBeInTheDocument()
    expect(within(secao).queryByRole('link', { name: 'Ver NFS-e' })).not.toBeInTheDocument()
  })
})
