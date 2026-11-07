import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AxiosError } from 'axios'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ManifestacaoDialog } from './ManifestacaoDialog'
import type { Nfse } from '../../lib/api'

/**
 * Manifestação do tomador: ato fiscal, então o diálogo exige aceite explícito,
 * valida o motivo e refaz o pedido depois do MFA quando o backend pedir.
 */

const manifestarMock = vi.fn()
const verifyMock = vi.fn()

vi.mock('../../lib/api', async () => {
  const actual = await vi.importActual<typeof import('../../lib/api')>('../../lib/api')
  return {
    ...actual,
    nfseApi: { manifestar: (...args: unknown[]) => manifestarMock(...args) },
    mfaApi: { verify: (...args: unknown[]) => verifyMock(...args) },
  }
})

const nfse = {
  id: 1,
  chave: '53001082221952840000124000000000000223042263694466',
  numero: '2',
  unidade_id: 7,
  papel: 'tomador',
  situacao: 'normal',
} as Nfse

function renderizar(acao: 'confirmar' | 'rejeitar', onSucesso = vi.fn(), onFechar = vi.fn()) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  render(
    <QueryClientProvider client={queryClient}>
      <ManifestacaoDialog nfse={nfse} acao={acao} onFechar={onFechar} onSucesso={onSucesso} />
    </QueryClientProvider>,
  )

  return { onSucesso, onFechar }
}

function erroStepUp() {
  return { response: { status: 403, data: { mfa_step_up_required: true } } }
}

describe('ManifestacaoDialog', () => {
  beforeEach(() => {
    manifestarMock.mockReset()
    verifyMock.mockReset()
  })

  it('só libera a confirmação depois do aceite e envia o pedido', async () => {
    const user = userEvent.setup()
    manifestarMock.mockResolvedValue({ ...nfse, manifestacao: 'confirmada' })
    const { onSucesso } = renderizar('confirmar')

    const botao = screen.getByRole('button', { name: /registrar confirmação/i })
    expect(botao).toBeDisabled()

    await user.click(screen.getByRole('checkbox'))
    await user.click(botao)

    await waitFor(() => expect(onSucesso).toHaveBeenCalled())
    expect(manifestarMock).toHaveBeenCalledWith(nfse.chave, { unidade_id: 7, acao: 'confirmar' })
  })

  it('a rejeição exige motivo e envia motivo e descrição', async () => {
    const user = userEvent.setup()
    manifestarMock.mockResolvedValue({ ...nfse, manifestacao: 'rejeitada' })
    const { onSucesso } = renderizar('rejeitar')

    await user.click(screen.getByRole('checkbox'))
    const botao = screen.getByRole('button', { name: /registrar rejeição/i })
    expect(botao).toBeDisabled()

    await user.selectOptions(screen.getByLabelText(/motivo da rejeição/i), '4')
    await user.type(screen.getByLabelText(/descrição do motivo/i), 'Valor cobrado diferente do contratado')
    expect(botao).toBeEnabled()
    await user.click(botao)

    await waitFor(() => expect(onSucesso).toHaveBeenCalled())
    expect(manifestarMock).toHaveBeenCalledWith(nfse.chave, {
      unidade_id: 7,
      acao: 'rejeitar',
      motivo: 4,
      descricao: 'Valor cobrado diferente do contratado',
    })
  })

  it('o motivo "Outros" exige descrição de 15 a 255 caracteres', async () => {
    const user = userEvent.setup()
    renderizar('rejeitar')

    await user.click(screen.getByRole('checkbox'))
    await user.selectOptions(screen.getByLabelText(/motivo da rejeição/i), '9')
    const botao = screen.getByRole('button', { name: /registrar rejeição/i })

    expect(botao).toBeDisabled()
    await user.type(screen.getByLabelText(/descrição do motivo/i), 'curta')
    expect(botao).toBeDisabled()
    expect(screen.getByText(/de 15 a 255 caracteres/i)).toBeInTheDocument()

    await user.type(screen.getByLabelText(/descrição do motivo/i), ' mas agora ficou longa o bastante')
    expect(botao).toBeEnabled()
  })

  it('pede o código do MFA quando o backend exige e reenvia depois de verificar', async () => {
    const user = userEvent.setup()
    manifestarMock.mockRejectedValueOnce(erroStepUp()).mockResolvedValueOnce({ ...nfse, manifestacao: 'confirmada' })
    verifyMock.mockResolvedValue({ recente_ate: 'x' })
    const { onSucesso } = renderizar('confirmar')

    await user.click(screen.getByRole('checkbox'))
    await user.click(screen.getByRole('button', { name: /registrar confirmação/i }))

    await user.type(await screen.findByLabelText(/código de 6 dígitos/i), '123456')
    await user.click(screen.getByRole('button', { name: /verificar e registrar/i }))

    await waitFor(() => expect(onSucesso).toHaveBeenCalled())
    expect(verifyMock).toHaveBeenCalledWith('123456')
    expect(manifestarMock).toHaveBeenCalledTimes(2)
  })

  it('mostra a mensagem da SEFIN quando o pedido é recusado e não fecha', async () => {
    const user = userEvent.setup()
    manifestarMock.mockRejectedValue(
      new AxiosError('Request failed', 'ERR_BAD_REQUEST', undefined, undefined, {
        status: 422,
        statusText: 'Unprocessable',
        headers: {},
        config: {} as never,
        data: { message: 'E0861: Evento conflitante' },
      }),
    )
    const { onSucesso } = renderizar('confirmar')

    await user.click(screen.getByRole('checkbox'))
    await user.click(screen.getByRole('button', { name: /registrar confirmação/i }))

    expect(await screen.findByRole('alert')).toHaveTextContent('E0861: Evento conflitante')
    expect(onSucesso).not.toHaveBeenCalled()
  })
})
