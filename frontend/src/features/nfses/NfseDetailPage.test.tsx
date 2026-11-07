import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { NfseDetailPage } from './NfseDetailPage'

/**
 * Detalhe da NFS-e: DANFSe, XML e o painel de manifestação do tomador.
 */

const obterMock = vi.fn()

vi.mock('../../lib/api', async () => {
  const actual = await vi.importActual<typeof import('../../lib/api')>('../../lib/api')
  return { ...actual, nfseApi: { obter: (...args: unknown[]) => obterMock(...args) } }
})

const CHAVE = '53001082221952840000124000000000000223042263694466'

const base = {
  id: 1,
  chave: CHAVE,
  numero: '2',
  papel: 'tomador',
  situacao: 'normal',
  unidade_id: 7,
  unidade: { id: 7, nome: 'Unidade Sete', nome_resumido: 'Unidade 7', cnpj: '00101980000119' },
  prestador_nome: 'SERRALHERIA EXEMPLO',
  prestador_documento: '21952840000124',
  tomador_nome: 'Tomador',
  tomador_documento: '00101980000119',
  data_emissao: '2023-04-20T15:25:29-03:00',
  competencia: '2023-04-20',
  valor_servico: '5000.00',
  valor_liquido: '5000.00',
  valor_iss: null,
  iss_retido: false,
  manifestacao: null,
  manifestada_em: null,
  pode_manifestar: false,
  tem_xml: true,
  xml_url: 'http://localhost/xml',
  eventos: [],
}

function renderizar() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[`/nfses/${CHAVE}?unidade=7`]}>
        <Routes>
          <Route path="/nfses/:chave" element={<NfseDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('NfseDetailPage', () => {
  beforeEach(() => obterMock.mockReset())

  it('oferece confirmar e rejeitar quando a unidade pode manifestar', async () => {
    obterMock.mockResolvedValue({ ...base, pode_manifestar: true })
    renderizar()

    expect(await screen.findByText('Pendente')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Confirmar' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Rejeitar' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /gerar danfse/i })).toBeEnabled()
    expect(obterMock).toHaveBeenCalledWith(CHAVE, 7)
  })

  it('mostra o estado quando a nota já foi manifestada e não oferece os botões', async () => {
    obterMock.mockResolvedValue({
      ...base,
      manifestacao: 'confirmada',
      manifestada_em: '2026-11-07T16:10:46-03:00',
    })
    renderizar()

    expect(await screen.findByText('Confirmada')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Confirmar' })).not.toBeInTheDocument()
  })

  it('sem permissão para manifestar, não oferece os botões', async () => {
    obterMock.mockResolvedValue({ ...base, pode_manifestar: false })
    renderizar()

    expect(await screen.findByText('Não manifestada')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Rejeitar' })).not.toBeInTheDocument()
  })

  it('nota emitida pela unidade não tem o bloco de manifestação', async () => {
    obterMock.mockResolvedValue({ ...base, papel: 'prestador' })
    renderizar()

    await screen.findByText(/NFS-e 2/)
    expect(screen.queryByText('Manifestação do tomador')).not.toBeInTheDocument()
  })

  it('sem XML guardado, o DANFSe fica desabilitado', async () => {
    obterMock.mockResolvedValue({ ...base, tem_xml: false, xml_url: null })
    renderizar()

    expect(await screen.findByRole('button', { name: /gerar danfse/i })).toBeDisabled()
  })
})
