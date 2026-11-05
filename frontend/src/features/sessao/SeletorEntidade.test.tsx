import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { SessionProvider } from '../auth/session'

/**
 * Seletor de entidade com a SESSÃO REAL (`SessionProvider`), para exercitar o
 * caminho de recarga do `/me` após a troca — é exatamente o que o mock do teste
 * antigo escondia.
 */

const trocarMock = vi.fn()
const meMock = vi.fn()

vi.mock('../../lib/api', () => ({
  sessaoApi: { trocarTenant: (...a: unknown[]) => trocarMock(...a) },
  authApi: { me: (...a: unknown[]) => meMock(...a) },
}))

import { SeletorEntidade } from './SeletorEntidade'

/** Usuário super-admin, com `tenant_ativo` variável conforme o estado do servidor. */
function usuario(tenantAtivoId: number | null) {
  return {
    user: {
      id: 1,
      name: 'X',
      email: 'x@x',
      tenant_id: null,
      tenant_ativo: tenantAtivoId === null ? null : { id: tenantAtivoId, nome: `Entidade ${tenantAtivoId}` },
      tenants_disponiveis: [
        { id: 1, nome: 'Entidade 1' },
        { id: 2, nome: 'Entidade 2' },
      ],
      pode_todas: true,
      papeis: [],
      permissoes: [],
      mfa_pendente: false,
      mfa_confirmado: true,
    },
  }
}

function renderizar() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return render(
    <QueryClientProvider client={qc}>
      <SessionProvider>
        <SeletorEntidade />
      </SessionProvider>
    </QueryClientProvider>,
  )
}

describe('SeletorEntidade', () => {
  beforeEach(() => {
    // O servidor começa em "Todas"; após trocar, o `/me` passa a devolver a entidade 2.
    meMock.mockReset().mockResolvedValue(usuario(null))
    trocarMock.mockReset().mockImplementation(async (id: number | null) => {
      meMock.mockResolvedValue(usuario(id))
      return { tenant_ativo: id === null ? null : { id, nome: `Entidade ${id}` } }
    })
  })

  it('lista Todas + entidades disponíveis para super-admin', async () => {
    renderizar()
    const select = await screen.findByRole('combobox', { name: /entidade/i })
    expect(select).toBeInTheDocument()
    expect(screen.getByRole('option', { name: /todas as entidades/i })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: /entidade 2/i })).toBeInTheDocument()
  })

  it('troca de entidade: persiste no servidor e MANTÉM a seleção refletindo o novo /me', async () => {
    const user = userEvent.setup()
    renderizar()

    const select = await screen.findByRole('combobox', { name: /entidade/i }) as HTMLSelectElement
    expect(select.value).toBe('')

    await user.selectOptions(select, '2')

    // Persistiu no servidor...
    await waitFor(() => expect(trocarMock).toHaveBeenCalledWith(2))
    // ...e a SESSÃO foi recarregada (o combo passa a refletir a nova entidade).
    await waitFor(() => expect(select.value).toBe('2'))
    expect(select.selectedOptions[0].textContent).toBe('Entidade 2')
  })

  it('reflete a escolha IMEDIATAMENTE, mesmo com /me lento (não reverte para o valor antigo)', async () => {
    const user = userEvent.setup()
    renderizar()

    const select = await screen.findByRole('combobox', { name: /entidade/i }) as HTMLSelectElement
    expect(select.value).toBe('')

    // O servidor leva um tempo para refletir a troca: o `/me` da recarga fica
    // PENDENTE. Com um `<select>` totalmente controlado pelo `/me`, a seleção
    // do usuário seria revertida para o valor anterior enquanto o fetch não
    // responde — este é o bug relatado ("não seleciona a opção").
    trocarMock.mockImplementation(async () => ({ tenant_ativo: { id: 2, nome: 'Entidade 2' } }))
    meMock.mockImplementationOnce(() => new Promise(() => {}))

    await user.selectOptions(select, '2')

    // Sem esperar o `/me`, a UI JÁ deve mostrar a entidade escolhida.
    await waitFor(() => expect(select.value).toBe('2'))
    expect(select.selectedOptions[0].textContent).toBe('Entidade 2')
  })

  it('em falha do servidor, descarta a escolha otimista e volta ao valor da sessão', async () => {
    const user = userEvent.setup()
    renderizar()

    const select = await screen.findByRole('combobox', { name: /entidade/i }) as HTMLSelectElement
    expect(select.value).toBe('')

    trocarMock.mockRejectedValueOnce(new Error('falhou'))

    await user.selectOptions(select, '2')

    // Reverte para o valor real da sessão (ainda "Todas").
    await waitFor(() => expect(select.value).toBe(''))
  })
})