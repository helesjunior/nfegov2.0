import { useState, type FormEvent } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { nfseApi, unidadesApi, mensagemDeErro } from '../../lib/api'
import { useSessao } from '../auth/session'

/**
 * Busca manual de NFS-e por chave de acesso (50 dígitos).
 *
 * Traz a nota e os eventos dela do Ambiente de Dados Nacional, sem mexer na
 * fila de sincronização. A busca roda em segundo plano (202) e o resultado
 * chega em Notificações. O ADN só entrega notas em que a unidade é tomadora,
 * prestadora ou intermediária.
 */
export function NfseBuscaForm() {
  const { usuario } = useSessao()
  const [aberto, setAberto] = useState(false)
  const [unidadeId, setUnidadeId] = useState('')
  const [chave, setChave] = useState('')
  const [aviso, setAviso] = useState<string | null>(null)
  const [erro, setErro] = useState<string | null>(null)

  const { data: unidades } = useQuery({
    queryKey: ['unidades', 'opcoes'],
    queryFn: () => unidadesApi.listar(100, 1),
    enabled: aberto,
  })

  const buscar = useMutation({
    mutationFn: () => nfseApi.recuperar({ unidade_id: Number(unidadeId), chave }),
    onSuccess: (mensagem) => {
      setErro(null)
      setAviso(mensagem)
      setChave('')
    },
    onError: (e) => {
      setAviso(null)
      setErro(mensagemDeErro(e))
    },
  })

  function enviar(evento: FormEvent) {
    evento.preventDefault()
    buscar.mutate()
  }

  // Só quem executa sincronizações vê o gatilho (o backend barra com 403 de qualquer forma).
  const podeBuscar =
    usuario?.papeis.includes('super-admin') || usuario?.permissoes.includes('sync.executar')

  if (!podeBuscar) {
    return null
  }

  if (!aberto) {
    return (
      <button type="button" className="botao" onClick={() => setAberto(true)}>
        Buscar NFS-e
      </button>
    )
  }

  return (
    <div className="card" style={{ marginBottom: 'var(--esp-4)' }}>
      <h2 style={{ marginTop: 0 }}>Buscar NFS-e por chave</h2>

      <p className="shell__usuario" style={{ marginTop: 0 }}>
        Recupera uma nota do Ambiente de Dados Nacional. A busca roda em segundo plano; ao
        terminar, você recebe um aviso em Notificações.
      </p>

      <form onSubmit={enviar} noValidate>
        <div className="campo">
          <label htmlFor="nfse-busca-unidade">Unidade de busca</label>
          <select
            id="nfse-busca-unidade"
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

        <div className="campo">
          <label htmlFor="nfse-busca-chave">Chave de acesso (50 dígitos)</label>
          <input
            id="nfse-busca-chave"
            inputMode="numeric"
            maxLength={50}
            value={chave}
            onChange={(e) => setChave(e.target.value.replace(/\D/g, ''))}
            required
          />
        </div>

        <div style={{ display: 'flex', gap: 'var(--esp-2)', marginTop: 'var(--esp-3)' }}>
          <button
            type="submit"
            className="botao"
            disabled={buscar.isPending || !unidadeId || chave.length !== 50}
          >
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
