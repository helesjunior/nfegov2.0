import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { mfaApi, mensagemDeErro } from '../../lib/api'
import { useSessao } from './session'

/**
 * Tela do segundo fator (TOTP).
 *
 * Dois cenários:
 * - MFA PENDENTE (`user.mfa_pendente`): mostra o QR/segredo e confirma o código
 *   para ativar o segundo fator.
 * - MFA JÁ ATIVO: apenas pede o código para renovar a janela de step-up.
 */
export function MfaPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { usuario } = useSessao()

  const [code, setCode] = useState('')
  const [erro, setErro] = useState<string | null>(null)

  const pendente = usuario?.mfa_pendente ?? false

  // Gera o segredo apenas quando a configuração é necessária (chamada manual).
  const setup = useMutation({
    mutationFn: () => mfaApi.setup(),
  })

  const confirmar = useMutation({
    mutationFn: () => mfaApi.confirm(code),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['sessao'] })
      navigate('/')
    },
    onError: (e) => setErro(mensagemDeErro(e)),
  })

  const verificar = useMutation({
    mutationFn: () => mfaApi.verify(code),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['sessao'] })
      navigate('/')
    },
    onError: (e) => setErro(mensagemDeErro(e)),
  })

  function aoEnviar(evento: FormEvent) {
    evento.preventDefault()
    setErro(null)
    ;(pendente ? confirmar : verificar).mutate()
  }

  const emAndamento = pendente ? confirmar.isPending : verificar.isPending

  return (
    <div className="auth-tela">
      <div className="card auth-card">
        <h1 className="auth-titulo">Verificação em duas etapas</h1>
        <p className="auth-subtitulo">
          {pendente
            ? 'Configure o autenticador e informe o código gerado.'
            : 'Informe o código do seu autenticador para continuar.'}
        </p>

        {erro && (
          <div className="alerta alerta--erro" role="alert">
            {erro}
          </div>
        )}

        {pendente && (
          <div className="alerta alerta--info">
            {setup.data ? (
              <>
                <p>Adicione a conta ao seu autenticador usando o segredo abaixo:</p>
                <p style={{ fontFamily: 'var(--fonte-mono)', wordBreak: 'break-all' }}>
                  {setup.data.secret}
                </p>
              </>
            ) : (
              <button
                type="button"
                className="botao botao--secundario"
                onClick={() => setup.mutate()}
                disabled={setup.isPending}
              >
                {setup.isPending ? 'Gerando…' : 'Gerar segredo / QR Code'}
              </button>
            )}
          </div>
        )}

        <form onSubmit={aoEnviar} noValidate>
          <div className="campo">
            <label htmlFor="code">Código de 6 dígitos</label>
            <input
              id="code"
              inputMode="numeric"
              autoComplete="one-time-code"
              maxLength={6}
              value={code}
              onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
              required
            />
          </div>

          <button type="submit" className="botao" disabled={emAndamento} style={{ width: '100%' }}>
            {emAndamento ? 'Validando…' : 'Confirmar'}
          </button>
        </form>
      </div>
    </div>
  )
}