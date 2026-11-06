import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { NfeBuscaForm } from './NfeBuscaForm'

/**
 * Formulário de recuperação manual de NF-e.
 *
 * O gatilho ("Buscar NF-e") só aparece para quem pode executar sincronizações
 * (`sync.executar` ou super-admin) — o backend rejeita os demais com 403.
 */

let papeis: string[] = []
let permissoes: string[] = []

vi.mock('../../lib/api', async () => {
  const actual = await vi.importActual<typeof import('../../lib/api')>('../../lib/api')
  return {
    ...actual,
    nfeApi: { recuperar: vi.fn() },
    unidadesApi: { listar: vi.fn().mockResolvedValue({ data: [], meta: { total: 0 } }) },
  }
})

vi.mock('../auth/session', () => ({
  useSessao: () => ({ usuario: { id: 1, papeis, permissoes } }),
}))

function renderForm() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <NfeBuscaForm />
    </QueryClientProvider>,
  )
}

describe('NfeBuscaForm', () => {
  beforeEach(() => {
    papeis = []
    permissoes = []
  })

  it('não mostra o gatilho para papel somente-leitura', () => {
    papeis = ['consulta']
    permissoes = ['nfe.view']

    renderForm()

    expect(screen.queryByRole('button', { name: /buscar nf-e/i })).not.toBeInTheDocument()
  })

  it('mostra o gatilho para quem tem sync.executar', () => {
    papeis = ['operador']
    permissoes = ['nfe.view', 'sync.executar']

    renderForm()

    expect(screen.getByRole('button', { name: /buscar nf-e/i })).toBeInTheDocument()
  })
})