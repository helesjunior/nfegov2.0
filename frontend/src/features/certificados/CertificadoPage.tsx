import { useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  certificadoApi,
  ehErroDeStepUp,
  mensagemDeErro,
  mfaApi,
  type Unidade,
} from '../../lib/api'
import { formatarCnpj, formatarData } from '../../lib/format'

/**
 * Tela do certificado digital de uma unidade.
 *
 * Mostra apenas METADADOS (titular, emissor, validade) — a senha do PFX e o
 * arquivo jamais são exibidos por ninguém, nem pelo super-admin.
 *
 * A rotação (envio de novo PFX) é uma operação SENSÍVEL e exige step-up de
 * MFA: se o backend responder 403 com `mfa_step_up_required`, a tela pede o
 * código TOTP e reenvia o upload automaticamente após a verificação.
 */
export function CertificadoPage({ unidade }: { unidade: Unidade }) {
  const queryClient = useQueryClient()

  const { data: certificado, isLoading } = useQuery({
    queryKey: ['certificado', unidade.id],
    queryFn: () => certificadoApi.obter(unidade.id),
  })

  const [dialogoAberto, setDialogoAberto] = useState(false)

  return (
    <div>
      <h1 style={{ marginTop: 0 }}>Certificado digital</h1>
      <p className="auth-subtitulo">
        {unidade.nome} — {formatarCnpj(unidade.cnpj)}
      </p>

      {isLoading && <div className="card">Carregando certificado…</div>}

      {!isLoading && !certificado && (
        <div className="card">
          <div className="alerta alerta--info">Nenhum certificado cadastrado para esta unidade.</div>
          <button type="button" className="botao" onClick={() => setDialogoAberto(true)}>
            Enviar certificado
          </button>
        </div>
      )}

      {certificado && (
        <div className="card">
          {certificado.vencido && (
            <div className="alerta alerta--erro">
              Certificado vencido. Renove para não interromper a recepção de NF-e.
            </div>
          )}
          {!certificado.vencido && certificado.dias_restantes <= 30 && (
            <div className="alerta alerta--info">
              Vence em {certificado.dias_restantes} dia(s). Providencie a renovação.
            </div>
          )}

          <dl className="ficha">
            <dt>Titular</dt>
            <dd>{certificado.titular}</dd>

            <dt>CNPJ</dt>
            <dd>{formatarCnpj(certificado.cnpj)}</dd>

            <dt>Emissor</dt>
            <dd>{certificado.emissor}</dd>

            <dt>Válido de</dt>
            <dd>{formatarData(certificado.valido_de)}</dd>

            <dt>Válido até</dt>
            <dd>
              {formatarData(certificado.valido_ate)}
              {certificado.vencido ? (
                <span className="selo selo--erro"> vencido</span>
              ) : (
                <span className="selo selo--ok"> {certificado.dias_restantes} dias restantes</span>
              )}
            </dd>
          </dl>

          <button type="button" className="botao" onClick={() => setDialogoAberto(true)}>
            Rotacionar certificado
          </button>
        </div>
      )}

      {dialogoAberto && (
        <RotacionarCertificadoDialog
          unidadeId={unidade.id}
          onFechar={() => setDialogoAberto(false)}
          onSucesso={() => {
            queryClient.invalidateQueries({ queryKey: ['certificado', unidade.id] })
            queryClient.invalidateQueries({ queryKey: ['unidades'] })
            setDialogoAberto(false)
          }}
        />
      )}
    </div>
  )
}

/**
 * Diálogo de rotação. Gerencia sozinho o ciclo:
 * enviar → (403 step-up) → pedir código → verificar → reenviar.
 */
function RotacionarCertificadoDialog({
  unidadeId,
  onFechar,
  onSucesso,
}: {
  unidadeId: number
  onFechar: () => void
  onSucesso: () => void
}) {
  const [arquivo, setArquivo] = useState<File | null>(null)
  const [senha, setSenha] = useState('')
  const [codigo, setCodigo] = useState('')
  const [erro, setErro] = useState<string | null>(null)
  const [exigeStepUp, setExigeStepUp] = useState(false)

  const enviar = useMutation({
    mutationFn: () => certificadoApi.enviar(unidadeId, arquivo as File, senha),
    onSuccess: onSucesso,
    onError: (e) => {
      if (ehErroDeStepUp(e)) {
        setExigeStepUp(true)
        setErro(null)
        return
      }
      setErro(mensagemDeErro(e))
    },
  })

  const verificarEReenviar = useMutation({
    mutationFn: async () => {
      await mfaApi.verify(codigo)
      return certificadoApi.enviar(unidadeId, arquivo as File, senha)
    },
    onSuccess: onSucesso,
    onError: (e) => setErro(mensagemDeErro(e)),
  })

  function aoEnviar(evento: FormEvent) {
    evento.preventDefault()
    setErro(null)
    enviar.mutate()
  }

  function aoVerificar(evento: FormEvent) {
    evento.preventDefault()
    setErro(null)
    verificarEReenviar.mutate()
  }

  return (
    <div className="modal-fundo" role="dialog" aria-modal="true">
      <div className="card modal-caixa">
        <h2 style={{ marginTop: 0 }}>Rotacionar certificado</h2>
        <p className="auth-subtitulo">
          A senha do certificado é armazenada cifrada e nunca é exibida.
        </p>

        {erro && (
          <div className="alerta alerta--erro" role="alert">
            {erro}
          </div>
        )}

        {!exigeStepUp ? (
          <form onSubmit={aoEnviar} noValidate>
            <div className="campo">
              <label htmlFor="arquivo">Arquivo (.pfx ou .p12)</label>
              <input
                id="arquivo"
                type="file"
                accept=".pfx,.p12"
                onChange={(e) => setArquivo(e.target.files?.[0] ?? null)}
                required
              />
            </div>

            <div className="campo">
              <label htmlFor="senha">Senha do certificado</label>
              <input
                id="senha"
                type="password"
                autoComplete="new-password"
                value={senha}
                onChange={(e) => setSenha(e.target.value)}
                required
              />
            </div>

            <div className="modal-acoes">
              <button type="button" className="botao botao--secundario" onClick={onFechar}>
                Cancelar
              </button>
              <button type="submit" className="botao" disabled={enviar.isPending}>
                {enviar.isPending ? 'Enviando…' : 'Enviar'}
              </button>
            </div>
          </form>
        ) : (
          <form onSubmit={aoVerificar} noValidate>
            <div className="alerta alerta--info">
              Esta operação exige uma nova verificação de segurança. Informe o código do
              autenticador para continuar.
            </div>

            <div className="campo">
              <label htmlFor="codigo">Código de 6 dígitos</label>
              <input
                id="codigo"
                inputMode="numeric"
                autoComplete="one-time-code"
                maxLength={6}
                value={codigo}
                onChange={(e) => setCodigo(e.target.value.replace(/\D/g, ''))}
                required
              />
            </div>

            <div className="modal-acoes">
              <button type="button" className="botao botao--secundario" onClick={onFechar}>
                Cancelar
              </button>
              <button type="submit" className="botao" disabled={verificarEReenviar.isPending}>
                {verificarEReenviar.isPending ? 'Verificando…' : 'Verificar e reenviar'}
              </button>
            </div>
          </form>
        )}
      </div>
    </div>
  )
}