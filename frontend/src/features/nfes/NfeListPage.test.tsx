import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { NfeListPage } from './NfeListPage'

/**
 * Lista de NF-e.
 *
 * Cobre o essencial: renderiza resultados paginados e aplica o filtro de
 * período, repassando as datas à API.
 */

const listarMock = vi.fn()
const recuperarMock = vi.fn()

vi.mock('../../lib/api', async () => {
  const actual = await vi.importActual<typeof import('../../lib/api')>('../../lib/api')
  return {
    ...actual,
    nfeApi: {
      listar: (...args: unknown[]) => listarMock(...args),
      obter: vi.fn(),
      exportar: vi.fn(),
      exportarXml: vi.fn(),
      recuperar: (...args: unknown[]) => recuperarMock(...args),
    },
    unidadesApi: {
      listar: vi.fn().mockResolvedValue({
        data: [{ id: 7, nome: 'Unidade Sete', nome_resumido: 'Unidade 7' }],
        meta: { total: 1 },
      }),
    },
  }
})

vi.mock('../auth/session', () => ({
  useSessao: () => ({
    usuario: { id: 1, papeis: ['operador'], permissoes: ['nfe.view', 'sync.executar'] },
    carregando: false,
    autenticado: true,
    recarregar: vi.fn(),
  }),
}))

function DetalheFalso() {
  const local = useLocation()
  return <div>{`Detalhe da NF-e ${local.search}`}</div>
}

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/nfes']}>
        <Routes>
          <Route path="/nfes" element={<NfeListPage />} />
          <Route path="/nfes/:chave" element={<DetalheFalso />} />
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
      unidade: { id: 1, nome: 'Secretaria de Saúde', nome_resumido: 'SAÚDE', cnpj: '11222333000262' },
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
    recuperarMock.mockReset()
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

  it('mostra a unidade (nome e CNPJ) de cada NF-e', async () => {
    listarMock.mockResolvedValue(paginaMock)

    renderPage()

    expect(await screen.findByText('SAÚDE')).toBeInTheDocument()
    expect(screen.getByText('11.222.333/0002-62')).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: /unidade/i })).toBeInTheDocument()
  })

  it('filtra por unidade e repassa unidade_id à API', async () => {
    const user = userEvent.setup()
    listarMock.mockResolvedValue(paginaMock)

    renderPage()
    await screen.findByText(/FORNECEDOR EXEMPLO LTDA/i)

    await user.selectOptions(screen.getByLabelText(/^unidade$/i), '7')
    await user.click(screen.getByRole('button', { name: /filtrar/i }))

    await waitFor(() =>
      expect(listarMock).toHaveBeenCalledWith(expect.objectContaining({ unidade_id: 7, page: 1 })),
    )
  })

  it('abre o detalhe da cópia da unidade da linha', async () => {
    const user = userEvent.setup()
    listarMock.mockResolvedValue(paginaMock)

    renderPage()

    await user.click(await screen.findByText(/FORNECEDOR EXEMPLO LTDA/i))

    expect(await screen.findByText("Detalhe da NF-e ?unidade=1")).toBeInTheDocument()
  })

  it('recupera uma NF-e por NSU', async () => {
    const user = userEvent.setup()
    listarMock.mockResolvedValue(paginaMock)
    recuperarMock.mockResolvedValue('Busca agendada. Você será notificado quando terminar.')

    renderPage()
    await screen.findByText(/FORNECEDOR EXEMPLO LTDA/i)

    await user.click(screen.getByRole('button', { name: /buscar nf-e/i }))
    await user.selectOptions(screen.getByLabelText(/unidade de busca/i), '7')
    await user.click(screen.getByRole('button', { name: /^NSU$/i }))
    await user.type(screen.getByLabelText(/^NSU$/i), '150')
    await user.click(screen.getByRole('button', { name: /^buscar$/i }))

    await waitFor(() =>
      expect(recuperarMock).toHaveBeenCalledWith({ unidade_id: 7, nsu: 150 }),
    )
  })

  it('recupera uma NF-e por chave', async () => {
    const user = userEvent.setup()
    listarMock.mockResolvedValue(paginaMock)
    recuperarMock.mockResolvedValue('Busca agendada.')

    renderPage()
    await screen.findByText(/FORNECEDOR EXEMPLO LTDA/i)

    await user.click(screen.getByRole('button', { name: /buscar nf-e/i }))
    await user.selectOptions(screen.getByLabelText(/unidade de busca/i), '7')
    await user.type(screen.getByLabelText(/chave de acesso/i), '35200111222333000181550010000010001000010010')
    await user.click(screen.getByRole('button', { name: /^buscar$/i }))

    await waitFor(() =>
      expect(recuperarMock).toHaveBeenCalledWith({
        unidade_id: 7,
        chave: '35200111222333000181550010000010001000010010',
      }),
    )
  })

  it('abre ordenada pela emissão mais recente', async () => {
    listarMock.mockResolvedValue(paginaMock)

    renderPage()
    await screen.findByText(/FORNECEDOR EXEMPLO LTDA/i)

    expect(listarMock).toHaveBeenCalledWith(
      expect.objectContaining({ ordem: 'emissao', direcao: 'desc', page: 1 }),
    )
    expect(screen.getByRole('columnheader', { name: /emissão/i })).toHaveAttribute('aria-sort', 'descending')
  })

  it('ordena pela coluna clicada, inverte no segundo clique e mantém os filtros', async () => {
    const user = userEvent.setup()
    listarMock.mockResolvedValue(paginaMock)

    renderPage()
    await screen.findByText(/FORNECEDOR EXEMPLO LTDA/i)

    await user.type(screen.getByLabelText(/^busca$/i), 'exemplo')
    await user.click(screen.getByRole('button', { name: /filtrar/i }))

    await user.click(screen.getByRole('button', { name: /^emitente$/i }))
    await waitFor(() =>
      expect(listarMock).toHaveBeenLastCalledWith(
        expect.objectContaining({ q: 'exemplo', ordem: 'emitente', direcao: 'asc', page: 1 }),
      ),
    )
    expect(screen.getByRole('columnheader', { name: /emitente/i })).toHaveAttribute('aria-sort', 'ascending')
    expect(screen.getByRole('columnheader', { name: /emissão/i })).toHaveAttribute('aria-sort', 'none')

    await user.click(screen.getByRole('button', { name: /^emitente$/i }))
    await waitFor(() =>
      expect(listarMock).toHaveBeenLastCalledWith(
        expect.objectContaining({ q: 'exemplo', ordem: 'emitente', direcao: 'desc' }),
      ),
    )

    await user.click(screen.getByRole('button', { name: /^valor$/i }))
    await waitFor(() =>
      expect(listarMock).toHaveBeenLastCalledWith(
        expect.objectContaining({ ordem: 'valor', direcao: 'desc' }),
      ),
    )
  })
})
