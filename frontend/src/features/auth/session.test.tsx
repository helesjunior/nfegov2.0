import { render, screen, act, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { describe, it, expect, vi, beforeEach } from 'vitest'

const meMock = vi.fn()
vi.mock('../../lib/api', () => ({ authApi: { me: (...a: unknown[]) => meMock(...a) } }))

import { SessionProvider, useSessao, limparCacheDaSessao } from './session'

function Sonda() {
  const { usuario, autenticado } = useSessao()
  return (
    <div>
      <span data-testid="quem">{usuario?.email ?? 'ninguem'}</span>
      <span data-testid="auth">{String(autenticado)}</span>
    </div>
  )
}

const resposta = (email: string) => ({ user: { id: 1, email, papeis: [], permissoes: [] } })
// Depois de sair, o /api/me do servidor responde 401 (sem usuário). Aqui a consulta responde
// "sem usuário" de imediato: o que importa é a sessão ter sido reiniciada, não o erro em si.
const semUsuario = { user: null }

function montar() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={qc}>
      <SessionProvider>
        <Sonda />
      </SessionProvider>
    </QueryClientProvider>,
  )
  return qc
}

describe('limparCacheDaSessao (sair da conta)', () => {
  beforeEach(() => meMock.mockReset())

  it('depois de sair e entrar com OUTRO usuário (sem recarregar), mostra o novo usuário', async () => {
    meMock.mockResolvedValue(resposta('a@x'))
    const qc = montar()
    await waitFor(() => expect(screen.getByTestId('quem')).toHaveTextContent('a@x'))

    // Sair (Topbar): o servidor passa a responder 401.
    meMock.mockResolvedValue(semUsuario)
    await act(async () => { limparCacheDaSessao(qc) })
    await waitFor(() => expect(screen.getByTestId('quem')).toHaveTextContent('ninguem'))

    // Entrar como outro (LoginPage.onSuccess semeia a sessão).
    act(() => {
      qc.setQueryData(['sessao'], resposta('b@x'))
    })
    await waitFor(() => expect(screen.getByTestId('quem')).toHaveTextContent('b@x'))

    // E o inverso: sair de novo e voltar como o primeiro.
    await act(async () => { limparCacheDaSessao(qc) })
    await waitFor(() => expect(screen.getByTestId('quem')).toHaveTextContent('ninguem'))
    act(() => {
      qc.setQueryData(['sessao'], resposta('a@x'))
    })
    await waitFor(() => expect(screen.getByTestId('quem')).toHaveTextContent('a@x'))
  })

  it('ao sair, a sessão fica deslogada e os dados do usuário anterior somem do cache', async () => {
    meMock.mockResolvedValue(resposta('a@x'))
    const qc = montar()
    await waitFor(() => expect(screen.getByTestId('auth')).toHaveTextContent('true'))
    qc.setQueryData(['usuarios', 1], { data: [{ email: 'dado-do-a@x' }] })

    meMock.mockResolvedValue(semUsuario)
    await act(async () => { limparCacheDaSessao(qc) })

    await waitFor(() => expect(screen.getByTestId('auth')).toHaveTextContent('false'))
    expect(qc.getQueryData(['usuarios', 1])).toBeUndefined()
  })
})
