import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Routes, Route } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { MfaPage } from './MfaPage'
import { useStepUp } from './stepUp'

/**
 * MfaPage: renova a janela de step-up e volta para a tela de origem quando há
 * um desafio registrado. Sair sem confirmar descarta o desafio (rascunho).
 */

const navigateMock = vi.fn()
const verifyMock = vi.fn()

vi.mock('../../lib/api', () => ({
  mfaApi: {
    setup: vi.fn(),
    confirm: vi.fn(),
    verify: (...args: unknown[]) => verifyMock(...args),
  },
  mensagemDeErro: (erro: unknown) =>
    erro instanceof Error ? erro.message : 'Não foi possível concluir a operação.',
}))

// Sessão com MFA já ativo (não pendente): caminho de "verificar".
vi.mock('./session', () => ({
  useSessao: () => ({
    usuario: { id: 1, mfa_pendente: false, mfa_confirmado: true },
  }),
}))

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return { ...actual, useNavigate: () => navigateMock }
})

function renderMfa() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/mfa']}>
        <Routes>
          <Route path="/mfa" element={<MfaPage />} />
          <Route path="/" element={<div>Início</div>} />
          <Route path="/entidades" element={<div>Entidades</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

/** Semeia o desafio via hook (mesmo singleton usado pela página). */
function semearDesafio() {
  const { result } = renderHookStepUp()
  result.current.desafiar('/entidades', {
    origem: 'tenant',
    modo: 'criar',
    dados: { nome: 'X' },
  })
}

// Helper para acessar o singleton fora do render da página.
import { renderHook, act } from '@testing-library/react'
function renderHookStepUp() {
  return renderHook(() => useStepUp())
}

describe('MfaPage', () => {
  beforeEach(() => {
    navigateMock.mockReset()
    verifyMock.mockReset()
    const { result } = renderHookStepUp()
    act(() => result.current.limparTudo())
  })

  it('após verify com rota de retorno, navega para a rota', async () => {
    const user = userEvent.setup()
    verifyMock.mockResolvedValue({ recente_ate: new Date().toISOString() })
    semearDesafio()

    renderMfa()
    await user.type(screen.getByLabelText(/código/i), '123456')
    await user.click(screen.getByRole('button', { name: /confirmar/i }))

    await waitFor(() => expect(navigateMock).toHaveBeenCalledWith('/entidades'))

    // O rascunho permanece para o formulário de origem consumir.
    const { result } = renderHookStepUp()
    expect(result.current.consumirRascunho('tenant')?.dados).toEqual({ nome: 'X' })
  })

  it('sem rota de retorno, volta para a tela inicial', async () => {
    const user = userEvent.setup()
    verifyMock.mockResolvedValue({ recente_ate: new Date().toISOString() })

    renderMfa()
    await user.type(screen.getByLabelText(/código/i), '123456')
    await user.click(screen.getByRole('button', { name: /confirmar/i }))

    await waitFor(() => expect(navigateMock).toHaveBeenCalledWith('/'))
  })

  it('sair de /mfa sem confirmar descarta o desafio', () => {
    semearDesafio()
    const { unmount } = renderMfa()
    unmount()

    const { result } = renderHookStepUp()
    expect(result.current.usarRotaRetorno()).toBeNull()
    expect(result.current.consumirRascunho('tenant')).toBeNull()
  })
})