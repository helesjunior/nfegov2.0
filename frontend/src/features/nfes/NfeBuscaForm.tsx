import { useState, type FormEvent } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { nfeApi, unidadesApi, mensagemDeErro } from '../../lib/api'
import { useSessao } from '../auth/session'

type Modo = 'chave' | 'nsu'

/**
 * Formulário de recuperação manual de NF-e.
 *
 * Busca uma nota que já passou pela fila do DistDFe, por chave de acesso
 * (consChNFe) ou por NSU específico (consNSU). A busca roda em segundo plano
 * (202) e o usuário é avisado em Notificações ao terminar.
 */
export function NfeBuscaForm() {
  const { usuario } = useSessao()
  const [aberto, setAberto] = useState(false)
  const [modo, setModo] = useState<Modo>('chave')
  const [unidadeId, setUnidadeId] = useState('')
  const [chave, setChave] = useState('')
  const [nsu, setNsu] = useState('')
  const [aviso, setAviso] = useState<string | null>(null)
  const [erro, setErro] = useState<string | null>(null)

  const { data: unidades } = useQuery({
    queryKey: ['unidades', 'opcoes'],
    queryFn: () => unidadesApi.listar(100, 1),
    enabled: aberto,
  })

  const recuperar = useMutation({
    mutationFn: () =>
      nfeApi.recuperar({
        unidade_id: Number(unidadeId),
        ...(modo === 'chave' ? { chave } : { nsu: Number(nsu) }),
      }),
    onSuccess: (mensagem) => {
      setErro(null)
      setAviso(mensagem)
      setChave('')
      setNsu('')
    },
    onError: (e) => {
      setAviso(null)
      setErro(mensagemDeErro(e))
    },
  })

  function enviar(evento: FormEvent) {
    evento.preventDefault()
    recuperar.mutate()
  }

  // Só quem pode executar sincronizações vê o gatilho (o backend rejeita com
  // 403 de qualquer forma; assim evitamos oferecer uma ação que falharia).
  const podeBuscar =
    usuario?.papeis.includes('super-admin') || usuario?.permissoes.includes('sync.executar')

  if (!podeBuscar) {
    return null
  }

  if (!aberto) {
    return (
      <button type="button" className="botao" onClick={() => setAberto(true)}>
        Buscar NF-e
      </button>
    )
  }

  return (
    <div className="card" style={{ marginBottom: 'var(--esp-4)' }}>
      <h2 style={{ marginTop: 0 }}>Buscar NF-e</h2>

      <p className="shell__usuario" style={{ marginTop: 0 }}>
        Recupera uma nota que já passou pela fila da SEFAZ. A busca roda em
        segundo plano; ao terminar, você recebe um aviso em Notificações.
      </p>

      <form onSubmit={enviar} noValidate>
        <div className="campo">
          <label htmlFor="recuperar-unidade">Unidade de busca</label>
          <select
            id="recuperar-unidade"
            value={unidadeId}
            onChange={(e) => setUnidadeId(e.target.value)}
            required
          >
            <option value="">Selecione…</option>
            {unidades?.data.map((u) => (
              <option key={u.id} value={u.id}>
                {u.nome_resumido || u.nome}
              </option>
            ))}
          </select>
        </div>

        <div style={{ display: 'flex', gap: 'var(--esp-2)', marginBottom: 'var(--esp-3)' }}>
          <button
            type="button"
            className={modo === 'chave' ? 'botao' : 'botao botao--secundario'}
            onClick={() => setModo('chave')}
          >
            Chave
          </button>
          <button
            type="button"
            className={modo === 'nsu' ? 'botao' : 'botao botao--secundario'}
            onClick={() => setModo('nsu')}
          >
            NSU
          </button>
        </div>

        {modo === 'chave' ? (
          <div className="campo">
            <label htmlFor="recuperar-chave">Chave de acesso</label>
            <input
              id="recuperar-chave"
              inputMode="numeric"
              maxLength={44}
              value={chave}
              onChange={(e) => setChave(e.target.value.replace(/\D/g, ''))}
              required
            />
          </div>
        ) : (
          <div className="campo">
            <label htmlFor="recuperar-nsu">NSU</label>
            <input
              id="recuperar-nsu"
              inputMode="numeric"
              min={1}
              value={nsu}
              onChange={(e) => setNsu(e.target.value.replace(/\D/g, ''))}
              required
            />
          </div>
        )}

        <div style={{ display: 'flex', gap: 'var(--esp-2)', marginTop: 'var(--esp-3)' }}>
          <button type="submit" className="botao" disabled={recuperar.isPending || !unidadeId}>
            Buscar
          </button>
          <button
            type="button"
            className="botao botao--secundario"
            onClick={() => {
              setAberto(false)
              setAviso(null)
              setErro(null)
            }}
          >
            Cancelar
          </button>
        </div>
      </form>

      {aviso && (
        <div className="alerta alerta--info" role="status" style={{ marginTop: 'var(--esp-3)' }}>
          {aviso}
        </div>
      )}
      {erro && (
        <div className="alerta alerta--erro" role="alert" style={{ marginTop: 'var(--esp-3)' }}>
          {erro}
        </div>
      )}
    </div>
  )
}