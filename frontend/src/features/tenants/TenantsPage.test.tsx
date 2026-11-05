import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Routes, Route } from 'react-router-dom'
import { describe, it, expect, vi, beforeEach } from 'vitest'

/**
 * TenantsPage + step-up: quando a criação/edição falha por falta de MFA
 * recente, o formulário registra o rascunho, redireciona para /mfa e, ao
 * voltar com o rascunho, reabre preenchido.
 */

const listarMock = vi.fn()
const criarMock = vi.fn()
const atualizarMock = vi.fn()
const definirAtivoMock = vi.fn()
const navigateMock = vi.fn()

vi.mock('../../lib/api', () => ({
  tenantsApi: {
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

import { TenantsPage } from './TenantsPage'
import { useStepUp } from '../auth/stepUp'
import { renderHook, act } from '@testing-library/react'

function renderizar() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/entidades']}>
        <Routes>
          <Route path="/entidades" element={<TenantsPage />} />
          <Route path="/mfa" element={<div>MFA</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

/** Semeia o desafio no singleton (equivalente ao que a MfaPage deixaria). */
function semearRascunho(rascunho: Parameters<ReturnType<typeof useStepUp>['desafiar']>[1]) {
  const { result } = renderHook(() => useStepUp())
  act(() => result.current.desafiar('/entidades', rascunho))
}

describe('TenantsPage (step-up)', () => {
  beforeEach(() => {
    navigateMock.mockReset()
    listarMock.mockReset().mockResolvedValue({
      data: [{ id: 7, nome: 'Entidade A', cnpj: '11222333000181', ativo: true }],
      meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 },
      links: {},
    })
    criarMock.mockReset()
    atualizarMock.mockReset().mockResolvedValue({})
    definirAtivoMock.mockReset()

    const { result } = renderHook(() => useStepUp())
    act(() => result.current.limparTudo())
  })

  it('ao criar sem step-up, registra rascunho e redireciona para /mfa', async () => {
    const user = userEvent.setup()
    criarMock.mockRejectedValue({
      response: { status: 403, data: { mfa_step_up_required: true } },
    })
    renderizar()

    await user.click(screen.getByRole('button', { name: /nova entidade/i }))
    await user.type(screen.getByLabelText(/nome/i), 'Nova Entidade')
    await user.click(screen.getByRole('button', { name: /salvar/i }))

    await waitFor(() => expect(navigateMock).toHaveBeenCalledWith('/mfa'))

    const { result } = renderHook(() => useStepUp())
    const r = result.current.consumirRascunho('tenant')
    expect(r?.origem).toBe('tenant')
    expect(r?.modo).toBe('criar')
    expect(r?.dados).toMatchObject({ nome: 'Nova Entidade' })
  })

  it('ao montar com rascunho de criação, reabre o formulário preenchido', async () => {
    semearRascunho({
      origem: 'tenant',
      modo: 'criar',
      dados: { nome: 'Entidade Restaurada', cnpj: '11222333000181', ativo: true },
    })

    renderizar()

    expect(await screen.findByLabelText(/nome/i)).toHaveValue('Entidade Restaurada')
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
    expect(result.current.consumirRascunho('tenant')).toBeNull()
  })
})