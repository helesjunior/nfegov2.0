import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { syncApi } from '../../lib/api'
import { formatarDataHora, rotularStatusSync } from '../../lib/format'

const STATUS = [
  { valor: '', rotulo: 'Todos' },
  { valor: 'sucesso', rotulo: 'Sucesso' },
  { valor: 'erro', rotulo: 'Erro' },
  { valor: 'bloqueado', rotulo: 'Bloqueado' },
  { valor: 'em_andamento', rotulo: 'Em andamento' },
]

/**
 * Histórico de execuções da sincronização distDFe.
 *
 * Mostra o resultado de cada rodada (status, documentos recebidos, NSU e
 * eventual erro). O status `bloqueado` indica que o freio anti-bloqueio da
 * SEFAZ foi acionado.
 */
export function SyncPage() {
  const [status, setStatus] = useState('')
  const [pagina, setPagina] = useState(1)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['sync-runs', status, pagina],
    queryFn: () => syncApi.listar({ status: status || undefined, per_page: 25, page: pagina }),
  })

  return (
    <div>
      <h1 style={{ marginTop: 0 }}>Sincronizações</h1>

      <div className="filtros">
        <div className="campo">
          <label htmlFor="status">Status</label>
          <select
            id="status"
            value={status}
            onChange={(e) => {
              setStatus(e.target.value)
              setPagina(1)
            }}
          >
            {STATUS.map((s) => (
              <option key={s.valor} value={s.valor}>
                {s.rotulo}
              </option>
            ))}
          </select>
        </div>
      </div>

      {isLoading && <div className="card">Carregando execuções…</div>}
      {isError && (
        <div className="alerta alerta--erro">Não foi possível carregar as execuções.</div>
      )}

      {data && (
        <div className="card" style={{ padding: 0 }}>
          <table className="tabela">
            <thead>
              <tr>
                <th>Início</th>
                <th>Fim</th>
                <th>Status</th>
                <th>Docs</th>
                <th>NSU</th>
                <th>Erro</th>
              </tr>
            </thead>
            <tbody>
              {data.data.map((r) => (
                <tr key={r.id}>
                  <td>{formatarDataHora(r.iniciado_em)}</td>
                  <td>{formatarDataHora(r.finalizado_em)}</td>
                  <td>
                    <StatusSelo status={r.status} />
                  </td>
                  <td>{r.docs_count}</td>
                  <td>
                    {r.nsu_inicial}–{r.nsu_final}
                  </td>
                  <td style={{ color: 'var(--cor-erro)' }}>{r.erro ?? '—'}</td>
                </tr>
              ))}
              {data.data.length === 0 && (
                <tr>
                  <td colSpan={6} style={{ textAlign: 'center', color: 'var(--cor-texto-suave)' }}>
                    Nenhuma execução registrada.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}

      {data && data.meta.last_page > 1 && (
        <div className="paginacao">
          <button
            type="button"
            className="botao botao--secundario"
            disabled={pagina <= 1}
            onClick={() => setPagina((p) => p - 1)}
          >
            Anterior
          </button>
          <span>
            Página {data.meta.current_page} de {data.meta.last_page}
          </span>
          <button
            type="button"
            className="botao botao--secundario"
            disabled={pagina >= data.meta.last_page}
            onClick={() => setPagina((p) => p + 1)}
          >
            Próxima
          </button>
        </div>
      )}
    </div>
  )
}

function StatusSelo({ status }: { status: string }) {
  const classe =
    status === 'sucesso'
      ? 'selo--ok'
      : status === 'erro'
        ? 'selo--erro'
        : status === 'bloqueado'
          ? 'selo--alerta'
          : 'selo--neutro'

  return <span className={`selo ${classe}`}>{rotularStatusSync(status)}</span>
}