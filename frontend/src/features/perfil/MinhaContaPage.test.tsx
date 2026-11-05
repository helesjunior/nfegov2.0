import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { describe, it, expect, vi, beforeEach } from 'vitest'

const atualizarMock = vi.fn()
const trocarSenhaMock = vi.fn()
const desabilitarMock = vi.fn()
vi.mock('../../lib/api', () => ({
  perfilApi: {
    atualizar: (...a: unknown[]) => atualizarMock(...a),
    trocarSenha: (...a: unknown[]) => trocarSenhaMock(...a),
    desabilitarMfa: (...a: unknown[]) => desabilitarMock(...a),
  },
  mfaApi: { setup: vi.fn(), confirm: vi.fn() },
  mensagemDeErro: (e: unknown) => (e instanceof Error ? e.message : 'erro'),
}))

const usuarioBase = {
  id: 1, name: 'Fulano', email: 'f@x', tenant_id: null, tenant_ativo: null,
  tenants_disponiveis: [], pode_todas: false, papeis: ['operador'], permissoes: [],
  mfa_pendente: false, mfa_confirmado: true, mfa_obrigatorio: false,
}
let usuarioAtual = usuarioBase
vi.mock('../auth/session', () => ({
  useSessao: () => ({ usuario: usuarioAtual, recarregar: vi.fn() }),
}))

const navigateMock = vi.fn()
vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return { ...actual, useNavigate: () => navigateMock }
})

import { MinhaContaPage } from './MinhaContaPage'
import { useStepUp } from '../auth/stepUp'
import { renderHook, act } from '@testing-library/react'

function renderizar() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MinhaContaPage />
    </QueryClientProvider>,
  )
}

describe('MinhaContaPage', () => {
  beforeEach(() => {
    navigateMock.mockReset()
    atualizarMock.mockReset().mockResolvedValue(usuarioBase)
    trocarSenhaMock.mockReset().mockResolvedValue(usuarioBase)
    desabilitarMock.mockReset().mockResolvedValue({ ...usuarioBase, mfa_confirmado: false })

    const { result } = renderHook(() => useStepUp())
    act(() => result.current.limparTudo())
  })

  it('renovar verificação define retorno para /minha-conta e navega para /mfa', async () => {
    const user = userEvent.setup()
    renderizar()

    await user.click(screen.getByRole('button', { name: /renovar verificação/i }))

    expect(navigateMock).toHaveBeenCalledWith('/mfa')
    const { result } = renderHook(() => useStepUp())
    expect(result.current.usarRotaRetorno()).toBe('/minha-conta')
  })

  it('mostra campo de senha atual apenas quando o e-mail muda', async () => {
    const user = userEvent.setup()
    renderizar()

    expect(screen.queryByLabelText(/senha atual/i)).not.toBeInTheDocument()

    const email = screen.getByLabelText(/e-mail/i)
    await user.clear(email)
    await user.type(email, 'novo@x')

    expect(await screen.findByLabelText(/senha atual/i)).toBeInTheDocument()
  })

  it('NÃO mostra botão de desabilitar MFA quando o papel é obrigatório', () => {
    usuarioAtual = { ...usuarioBase, papeis: ['admin-tenant'], mfa_obrigatorio: true }
    renderizar()
    expect(screen.queryByRole('button', { name: /desabilitar/i })).not.toBeInTheDocument()
    usuarioAtual = usuarioBase
  })

  it('mostra botão de desabilitar MFA para quem não é obrigado e envia o código', async () => {
    const user = userEvent.setup()
    renderizar()

    const btn = screen.getByRole('button', { name: /desabilitar/i })
    await user.click(btn)

    const campo = await screen.findByLabelText(/código/i)
    await user.type(campo, '123456')
    await user.click(screen.getByRole('button', { name: /confirmar desabilita/i }))

    await waitFor(() => expect(desabilitarMock).toHaveBeenCalledWith('123456'))
  })
})