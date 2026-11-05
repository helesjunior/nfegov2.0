import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Routes, Route } from 'react-router-dom'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { renderHook, act } from '@testing-library/react'

/**
 * UnidadesPage: edição e step-up. Quando a criação/edição falha por falta de
 * MFA recente, o formulário registra o rascunho, redireciona para /mfa e, ao
 * voltar com o rascunho, reabre preenchido.
 */

const listarMock = vi.fn()
const criarMock = vi.fn()
const atualizarMock = vi.fn()
const definirAtivoMock = vi.fn()
const navigateMock = vi.fn()

vi.mock('../../lib/api', () => ({
  unidadesApi: {
    listar: (...a: unknown[]) => listarMock(...a),
    criar: (...a: unknown[]) => criarMock(...a),
    atualizar: (...a: unknown[]) => atualizarMock(...a),
    definirAtivo: (...a: unknown[]) => definirAtivoMock(...a),
  },
  mensagemDeErro: (e: unknown) => (e instanceof Error ? e.message : 'erro'),
  ehErroDeStepUp: (e: unknown) =>
    (e as { response?: { data?: { mfa_step_up_required?: boolean } } })?.response?.data
      ?.mfa_step_up_required === true,
}))

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return { ...actual, useNavigate: () => navigateMock }
})

import { UnidadesPage } from './UnidadesPage'
import { useStepUp } from '../auth/stepUp'

function renderizar() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/unidades']}>
        <Routes>
          <Route path="/unidades" element={<UnidadesPage />} />
          <Route path="/mfa" element={<div>MFA</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

function semearRascunho(rascunho: Parameters<ReturnType<typeof useStepUp>['desafiar']>[1]) {
  const { result } = renderHook(() => useStepUp())
  act(() => result.current.desafiar('/unidades', rascunho))
}

describe('UnidadesPage', () => {
  beforeEach(() => {
    navigateMock.mockReset()
    listarMock.mockReset().mockResolvedValue({
      data: [
        {
          id: 1,
          tenant_id: 5,
          codigo_unidade: null,
          nome: 'Unidade A',
          nome_resumido: null,
          cnpj: '11222333000181',
          ie: null,
          uf: 'SP',
          tp_amb: 2,
          ativo: true,
          certificado: null,
          created_at: null,
        },
      ],
      meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 },
      links: {},
    })
    criarMock.mockReset()
    atualizarMock.mockReset().mockResolvedValue({})
    definirAtivoMock.mockReset()

    const { result } = renderHook(() => useStepUp())
    act(() => result.current.limparTudo())
  })

  it('abre o formulário de edição com os dados da unidade', async () => {
    const user = userEvent.setup()
    renderizar()

    await user.click(await screen.findByRole('button', { name: /editar/i }))

    expect(screen.getByDisplayValue('Unidade A')).toBeInTheDocument()
  })

  it('ao criar sem step-up, registra rascunho e redireciona para /mfa', async () => {
    const user = userEvent.setup()
    criarMock.mockRejectedValue({
      response: { status: 403, data: { mfa_step_up_required: true } },
    })
    renderizar()

    await user.click(screen.getByRole('button', { name: /nova unidade/i }))
    await user.type(screen.getByLabelText(/^nome$/i), 'Unidade Nova')
    await user.type(screen.getByLabelText(/cnpj/i), '11222333000181')
    await user.type(screen.getByLabelText(/uf/i), 'DF')
    await user.click(screen.getByRole('button', { name: /salvar/i }))

    await waitFor(() => expect(navigateMock).toHaveBeenCalledWith('/mfa'))

    const { result } = renderHook(() => useStepUp())
    const r = result.current.consumirRascunho('unidade')
    expect(r?.origem).toBe('unidade')
    expect(r?.modo).toBe('criar')
    expect(r?.dados).toMatchObject({ nome: 'Unidade Nova', uf: 'DF' })
  })

  it('ao montar com rascunho de unidade, reabre o formulário preenchido', async () => {
    semearRascunho({
      origem: 'unidade',
      modo: 'criar',
      dados: { nome: 'Unidade Restaurada', uf: 'DF', tp_amb: 1 },
    })

    renderizar()

    expect(await screen.findByDisplayValue('Unidade Restaurada')).toBeInTheDocument()
    expect(screen.getByDisplayValue('DF')).toBeInTheDocument()
  })

  it('ativar/desativar sem step-up redireciona para /mfa (sem rascunho)', async () => {
    const user = userEvent.setup()
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    definirAtivoMock.mockRejectedValue({
      response: { status: 403, data: { mfa_step_up_required: true } },
    })
    renderizar()

    await user.click(await screen.findByRole('button', { name: /desativar/i }))

    await waitFor(() => expect(navigateMock).toHaveBeenCalledWith('/mfa'))

    const { result } = renderHook(() => useStepUp())
    expect(result.current.consumirRascunho('unidade')).toBeNull()
  })
})