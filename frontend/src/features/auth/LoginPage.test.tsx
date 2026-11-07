import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Routes, Route } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { LoginPage } from './LoginPage'

/**
 * Login: renderiza, exibe erro em credenciais inválidas e navega em sucesso.
 *
 * O cliente de API é mockado — o teste cobre o comportamento da tela, não a
 * rede. Usamos um roteador em memória e um QueryClient isolado por teste.
 */

const navigateMock = vi.fn()

// Mocka o serviço de autenticação consumido pela página.
const loginMock = vi.fn()
vi.mock('../../lib/api', () => ({
  authApi: {
    login: (...args: unknown[]) => loginMock(...args),
    me: vi.fn(),
    logout: vi.fn(),
  },
  mensagemDeErro: (erro: unknown) =>
    erro instanceof Error ? erro.message : 'Não foi possível concluir a operação.',
}))

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return {
    ...actual,
    useNavigate: () => navigateMock,
  }
})

function renderLogin() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/login']}>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route path="/" element={<div>Tela inicial</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('LoginPage', () => {
  beforeEach(() => {
    navigateMock.mockReset()
    loginMock.mockReset()
  })

  it('renderiza os campos de e-mail e senha', () => {
    renderLogin()

    expect(screen.getByLabelText(/e-?mail/i)).toBeInTheDocument()
    expect(screen.getByLabelText(/senha/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /entrar/i })).toBeInTheDocument()
  })

  it('mostra a mensagem de erro quando as credenciais são inválidas', async () => {
    const user = userEvent.setup()
    loginMock.mockRejectedValue(new Error('Credenciais inválidas.'))

    renderLogin()

    await user.type(screen.getByLabelText(/e-?mail/i), 'admin@orgao.gov.br')
    await user.type(screen.getByLabelText(/senha/i), 'errada')
    await user.click(screen.getByRole('button', { name: /entrar/i }))

    expect(await screen.findByRole('alert')).toHaveTextContent(/credenciais inválidas/i)
    expect(navigateMock).not.toHaveBeenCalled()
  })

  it('navega para a tela inicial após login com sucesso', async () => {
    const user = userEvent.setup()
    loginMock.mockResolvedValue({
      user: { id: 1, name: 'Admin', email: 'admin@orgao.gov.br', mfa_pendente: false },
    })

    renderLogin()

    await user.type(screen.getByLabelText(/e-?mail/i), 'admin@orgao.gov.br')
    await user.type(screen.getByLabelText(/senha/i), 'segredo')
    await user.click(screen.getByRole('button', { name: /entrar/i }))

    await waitFor(() => expect(navigateMock).toHaveBeenCalledWith('/'))
  })

  it('redireciona para o MFA quando o segundo fator está pendente', async () => {
    const user = userEvent.setup()
    loginMock.mockResolvedValue({
      user: { id: 1, name: 'Admin', email: 'admin@orgao.gov.br', mfa_pendente: true },
    })

    renderLogin()

    await user.type(screen.getByLabelText(/e-?mail/i), 'admin@orgao.gov.br')
    await user.type(screen.getByLabelText(/senha/i), 'segredo')
    await user.click(screen.getByRole('button', { name: /entrar/i }))

    await waitFor(() => expect(navigateMock).toHaveBeenCalledWith('/mfa'))
  })

  it('pede o código do MFA quando o segundo fator já está ativo', async () => {
    const user = userEvent.setup()
    loginMock.mockResolvedValue({
      user: {
        id: 1,
        name: 'Admin',
        email: 'admin@orgao.gov.br',
        mfa_pendente: false,
        mfa_confirmado: true,
        mfa_verificacao_pendente: true,
      },
    })

    renderLogin()

    await user.type(screen.getByLabelText(/e-?mail/i), 'admin@orgao.gov.br')
    await user.type(screen.getByLabelText(/senha/i), 'segredo')
    await user.click(screen.getByRole('button', { name: /entrar/i }))

    await waitFor(() => expect(navigateMock).toHaveBeenCalledWith('/mfa'))
  })
})
