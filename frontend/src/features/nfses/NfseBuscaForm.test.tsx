import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { NfseBuscaForm } from './NfseBuscaForm'

/**
 * Busca manual de NFS-e por chave: só para quem executa sincronizações, chave
 * de 50 dígitos, resposta assíncrona.
 */

const recuperarMock = vi.fn()
let permissoes: string[] = ['nfe.view', 'sync.executar']

vi.mock('../../lib/api', async () => {
  const actual = await vi.importActual<typeof import('../../lib/api')>('../../lib/api')
  return {
    ...actual,
    nfseApi: { recuperar: (...args: unknown[]) => recuperarMock(...args) },
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
    usuario: { id: 1, papeis: ['operador'], permissoes },
    carregando: false,
    autenticado: true,
    recarregar: vi.fn(),
  }),
}))

function renderizar() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  render(
    <QueryClientProvider client={queryClient}>
      <NfseBuscaForm />
    </QueryClientProvider>,
  )
}

describe('NfseBuscaForm', () => {
  beforeEach(() => {
    recuperarMock.mockReset()
    permissoes = ['nfe.view', 'sync.executar']
  })

  it('não aparece para quem não pode executar sincronizações', () => {
    permissoes = ['nfe.view']
    renderizar()

    expect(screen.queryByRole('button', { name: /buscar nfs-e/i })).not.toBeInTheDocument()
  })

  it('só libera o botão com os 50 dígitos e mostra o aviso do backend', async () => {
    const user = userEvent.setup()
    recuperarMock.mockResolvedValue('Busca agendada. Você será notificado quando terminar.')
    renderizar()

    await user.click(screen.getByRole('button', { name: /buscar nfs-e/i }))
    await waitFor(() => expect(screen.getByRole('option', { name: 'Unidade 7' })).toBeInTheDocument())
    await user.selectOptions(screen.getByLabelText(/unidade de busca/i), '7')
    const botao = screen.getByRole('button', { name: /^buscar$/i })
    await user.type(screen.getByLabelText(/chave de acesso/i), 'x5300108222195284000012400000000000022304226369446')
    expect(botao).toBeDisabled() // 49 dígitos (a letra é descartada)

    await user.type(screen.getByLabelText(/chave de acesso/i), '6')
    expect(botao).toBeEnabled()
    await user.click(botao)

    expect(await screen.findByRole('status')).toHaveTextContent('Busca agendada')
    expect(recuperarMock).toHaveBeenCalledWith({
      unidade_id: 7,
      chave: '53001082221952840000124000000000000223042263694466',
    })
  })
})
