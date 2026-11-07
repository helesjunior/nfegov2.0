import { useState, type FormEvent } from 'react'
import { useMutation } from '@tanstack/react-query'
import {
  ehErroDeStepUp,
  mensagemDeErro,
  mfaApi,
  MOTIVOS_REJEICAO_NFSE,
  nfseApi,
  type Nfse,
  type PedidoManifestacaoNfse,
} from '../../lib/api'

/**
 * Diálogo de manifestação do tomador (Confirmação ou Rejeição).
 *
 * É um ato fiscal registrado na SEFIN Nacional: o texto avisa que não se
 * desfaz por aqui e o botão só libera depois de marcar o aceite. A operação
 * exige MFA recente; sem ele o diálogo pede o código e reenvia.
 */
export function ManifestacaoDialog({
  nfse,
  acao,
  onFechar,
  onSucesso,
}: {
  nfse: Nfse
  acao: 'confirmar' | 'rejeitar'
  onFechar: () => void
  onSucesso: (nfse: Nfse) => void
}) {
  const rejeitar = acao === 'rejeitar'
  const [motivo, setMotivo] = useState('')
  const [descricao, setDescricao] = useState('')
  const [ciente, setCiente] = useState(false)
  const [codigo, setCodigo] = useState('')
  const [erro, setErro] = useState<string | null>(null)
  const [exigeStepUp, setExigeStepUp] = useState(false)

  const pedido = (): PedidoManifestacaoNfse => ({
    unidade_id: nfse.unidade_id,
    acao,
    ...(rejeitar ? { motivo: Number(motivo) } : {}),
    ...(rejeitar && descricao.trim() ? { descricao: descricao.trim() } : {}),
  })

  const enviar = useMutation({
    mutationFn: () => nfseApi.manifestar(nfse.chave, pedido()),
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
      return nfseApi.manifestar(nfse.chave, pedido())
    },
    onSuccess: onSucesso,
    onError: (e) => setErro(mensagemDeErro(e)),
  })

  const descricaoObrigatoria = motivo === '9'
  const descricaoInvalida =
    (descricaoObrigatoria || descricao.trim() !== '') &&
    (descricao.trim().length < 15 || descricao.trim().length > 255)
  const podeEnviar = ciente && (!rejeitar || (motivo !== '' && !descricaoInvalida))

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
        <h2 style={{ marginTop: 0 }}>
          {rejeitar ? 'Rejeitar NFS-e' : 'Confirmar NFS-e'} {nfse.numero ?? ''}
        </h2>
        <p className="auth-subtitulo">
          {rejeitar
            ? 'Você declara à Receita Federal que não reconhece esta nota ou discorda dela.'
            : 'Você declara à Receita Federal que o serviço desta nota foi prestado.'}{' '}
          O evento é registrado na SEFIN Nacional e <strong>não pode ser desfeito por aqui</strong>.
          {!rejeitar && ' Depois da confirmação, o prestador não consegue mais cancelar a nota (só substituí-la).'}
        </p>

        {erro && (
          <div className="alerta alerta--erro" role="alert">
            {erro}
          </div>
        )}

        {!exigeStepUp ? (
          <form onSubmit={aoEnviar} noValidate>
            {rejeitar && (
              <>
                <div className="campo">
                  <label htmlFor="manifestacao-motivo">Motivo da rejeição</label>
                  <select
                    id="manifestacao-motivo"
                    value={motivo}
                    onChange={(e) => setMotivo(e.target.value)}
                    required
                  >
                    <option value="">Selecione…</option>
                    {MOTIVOS_REJEICAO_NFSE.map((m) => (
                      <option key={m.codigo} value={m.codigo}>
                        {m.rotulo}
                      </option>
                    ))}
                  </select>
                </div>

                <div className="campo">
                  <label htmlFor="manifestacao-descricao">
                    Descrição do motivo{descricaoObrigatoria ? '' : ' (opcional)'}
                  </label>
                  <textarea
                    id="manifestacao-descricao"
                    rows={3}
                    maxLength={255}
                    value={descricao}
                    onChange={(e) => setDescricao(e.target.value)}
                  />
                  {descricaoInvalida && (
                    <small style={{ color: 'var(--cor-erro)' }}>
                      Use de 15 a 255 caracteres.
                    </small>
                  )}
                </div>
              </>
            )}

            <label style={{ display: 'flex', gap: 'var(--esp-2)', alignItems: 'flex-start' }}>
              <input
                type="checkbox"
                checked={ciente}
                onChange={(e) => setCiente(e.target.checked)}
              />
              <span>
                Estou ciente de que esta manifestação é um ato fiscal e não poderá ser desfeita
                por este sistema.
              </span>
            </label>

            <div className="modal-acoes">
              <button type="button" className="botao botao--secundario" onClick={onFechar}>
                Cancelar
              </button>
              <button type="submit" className="botao" disabled={!podeEnviar || enviar.isPending}>
                {enviar.isPending
                  ? 'Enviando…'
                  : rejeitar
                    ? 'Registrar rejeição'
                    : 'Registrar confirmação'}
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
              <label htmlFor="manifestacao-codigo">Código de 6 dígitos</label>
              <input
                id="manifestacao-codigo"
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
              <button
                type="submit"
                className="botao"
                disabled={codigo.length !== 6 || verificarEReenviar.isPending}
              >
                {verificarEReenviar.isPending ? 'Verificando…' : 'Verificar e registrar'}
              </button>
            </div>
          </form>
        )}
      </div>
    </div>
  )
}
