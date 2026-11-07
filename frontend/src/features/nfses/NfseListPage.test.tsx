import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { NfseListPage } from './NfseListPage'

/**
 * Lista de NFS-e: abre em "Recebidas", aplica filtros e abre o detalhe da
 * cópia da unidade.
 */

const listarMock = vi.fn()
const exportarMock = vi.fn()
const exportarXmlMock = vi.fn()

vi.mock('../../lib/api', async () => {
  const actual = await vi.importActual<typeof import('../../lib/api')>('../../lib/api')
  return {
    ...actual,
    nfseApi: {
      listar: (...args: unknown[]) => listarMock(...args),
      obter: vi.fn(),
      exportar: (...args: unknown[]) => exportarMock(...args),
      exportarXml: (...args: unknown[]) => exportarXmlMock(...args),
      recuperar: vi.fn(),
    },
    unidadesApi: {
      listar: vi.fn().mockResolvedValue({
        data: [{ id: 7, nome: 'Unidade Sete', nome_resumido: 'Unidade 7', cnpj: '00101980000119' }],
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
  return <div>{`Detalhe da NFS-e ${local.pathname}${local.search}`}</div>
}

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/nfses']}>
        <Routes>
          <Route path="/nfses" element={<NfseListPage />} />
          <Route path="/nfses/:chave" element={<DetalheFalso />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const CHAVE = '53001082221952840000124000000000000223042263694466'

const paginaMock = {
  data: [
    {
      id: 1,
      chave: CHAVE,
      nsu: 1,
      papel: 'tomador',
      situacao: 'cancelada',
      numero: '2',
      unidade_id: 7,
      unidade: { id: 7, nome: 'Unidade Sete', nome_resumido: 'Unidade 7', cnpj: '00101980000119' },
      municipio_emissor_nome: 'Brasília',
      prestador_documento: '21952840000124',
      prestador_nome: 'SERRALHERIA EXEMPLO',
      tomador_documento: '00101980000119',
      tomador_nome: 'Tomador',
      data_emissao: '2023-04-20T15:25:29-03:00',
      competencia: '2023-04-20',
      valor_servico: '5000.00',
      valor_liquido: '5000.00',
      valor_iss: null,
      iss_retido: true,
      tem_xml: true,
      xml_url: null,
    },
  ],
  links: {},
  meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
}

describe('NfseListPage', () => {
  beforeEach(() => {
    listarMock.mockReset()
    exportarMock.mockReset()
    exportarXmlMock.mockReset()
    listarMock.mockResolvedValue(paginaMock)
  })

  it('abre nas notas recebidas e mostra prestador, competência, valor e situação', async () => {
    renderPage()

    expect(await screen.findByText('SERRALHERIA EXEMPLO')).toBeInTheDocument()
    expect(listarMock).toHaveBeenCalledWith(expect.objectContaining({ papel: 'tomador', page: 1 }))
    expect(screen.getByText('04/2023')).toBeInTheDocument()
    expect(screen.getByText(/5\.000,00/)).toBeInTheDocument()
    expect(screen.getByText('ISS retido')).toBeInTheDocument()
    expect(screen.getByRole('table')).toHaveTextContent('Cancelada')
  })

  it('filtra por competência, unidade e situação', async () => {
    const user = userEvent.setup()
    renderPage()
    await screen.findByText('SERRALHERIA EXEMPLO')

    await user.selectOptions(screen.getByLabelText(/período por/i), 'competencia')
    await user.type(screen.getByLabelText(/^de$/i), '2023-01-01')
    await user.selectOptions(screen.getByLabelText(/situação/i), 'cancelada')
    await waitFor(() => expect(screen.getByRole('option', { name: /Unidade 7/ })).toBeInTheDocument())
    await user.selectOptions(screen.getByLabelText(/^unidade$/i), '7')
    await user.click(screen.getByRole('button', { name: /filtrar/i }))

    await waitFor(() =>
      expect(listarMock).toHaveBeenLastCalledWith(
        expect.objectContaining({
          de: '2023-01-01',
          data: 'competencia',
          unidade_id: 7,
          situacao: 'cancelada',
          papel: 'tomador',
        }),
      ),
    )
  })

  it('abre o detalhe da cópia da unidade', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.click(await screen.findByText('SERRALHERIA EXEMPLO'))

    expect(await screen.findByText(`Detalhe da NFS-e /nfses/${CHAVE}?unidade=7`)).toBeInTheDocument()
  })

  it('exporta com os filtros aplicados', async () => {
    const user = userEvent.setup()
    exportarMock.mockResolvedValue('A exportação foi agendada.')
    renderPage()
    await screen.findByText('SERRALHERIA EXEMPLO')

    await user.click(screen.getByRole('button', { name: /exportar xlsx/i }))

    expect(exportarMock).toHaveBeenCalledWith({ papel: 'tomador' }, 'xlsx')
    expect(await screen.findByText('A exportação foi agendada.')).toBeInTheDocument()
  })

  it('exporta os XMLs em ZIP com os filtros aplicados', async () => {
    const user = userEvent.setup()
    exportarXmlMock.mockResolvedValue('A exportação dos XMLs foi agendada.')
    renderPage()
    await screen.findByText('SERRALHERIA EXEMPLO')

    await user.click(screen.getByRole('button', { name: /exportar xmls/i }))

    expect(exportarXmlMock).toHaveBeenCalledWith({ papel: 'tomador' })
    expect(await screen.findByText('A exportação dos XMLs foi agendada.')).toBeInTheDocument()
  })
})
