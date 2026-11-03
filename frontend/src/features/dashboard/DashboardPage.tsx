import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { dashboardApi } from '../../lib/api'
import { formatarDataHora, formatarMoeda, rotularStatusSync } from '../../lib/format'

/**
 * Painel inicial: visão geral da recepção de NF-e.
 *
 * Reúne os indicadores principais, o estado do freio da SEFAZ e as últimas
 * execuções de sincronização.
 */
export function DashboardPage() {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['dashboard'],
    queryFn: () => dashboardApi.obter(),
  })

  if (isLoading) return <div className="card">Carregando indicadores…</div>
  if (isError || !data) {
    return <div className="alerta alerta--erro">Não foi possível carregar o painel.</div>
  }

  const cartoes = [
    { rotulo: 'NF-e recebidas', valor: String(data.nfes.total) },
    { rotulo: 'NF-e (30 dias)', valor: String(data.nfes.ultimos_30_dias) },
    { rotulo: 'Valor (30 dias)', valor: formatarMoeda(data.nfes.valor_ultimos_30_dias) },
    { rotulo: 'Resumos pendentes', valor: String(data.nfes.resumos) },
    { rotulo: 'Unidades ativas', valor: `${data.unidades.ativas} / ${data.unidades.total}` },
    { rotulo: 'Certificados vencendo (30d)', valor: String(data.certificados.vencendo_30_dias) },
  ]

  return (
    <div>
      <h1 style={{ marginTop: 0 }}>Painel</h1>

      {data.sync.circuito_aberto && (
        <div className="alerta alerta--erro">
          O acesso à SEFAZ está temporariamente suspenso (freio anti-bloqueio). As
          sincronizações retomam automaticamente.
        </div>
      )}

      {data.certificados.vencidos > 0 && (
        <div className="alerta alerta--erro">
          {data.certificados.vencidos} certificado(s) vencido(s). Renove para manter a recepção de
          NF-e.
        </div>
      )}

      {data.sync.com_erro_24h > 0 && (
        <div className="alerta alerta--info">
          {data.sync.com_erro_24h} sincronização(ões) com erro nas últimas 24 horas.{' '}
          <Link to="/sync">Ver detalhes</Link>
        </div>
      )}

      <div
        style={{
          display: 'grid',
          gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))',
          gap: 'var(--esp-4)',
        }}
      >
        {cartoes.map((c) => (
          <div className="card" key={c.rotulo}>
            <div className="shell__usuario">{c.rotulo}</div>
            <div style={{ fontSize: '1.6rem', fontWeight: 700 }}>{c.valor}</div>
          </div>
        ))}
      </div>

      <h2 style={{ marginTop: 'var(--esp-6)' }}>Últimas sincronizações</h2>
      <div className="card" style={{ padding: 0 }}>
        <table className="tabela">
          <thead>
            <tr>
              <th>Início</th>
              <th>Status</th>
              <th>Documentos</th>
            </tr>
          </thead>
          <tbody>
            {data.sync.ultimas.map((s) => (
              <tr key={s.id}>
                <td>{formatarDataHora(s.iniciado_em)}</td>
                <td>{rotularStatusSync(s.status)}</td>
                <td>{s.docs_count}</td>
              </tr>
            ))}
            {data.sync.ultimas.length === 0 && (
              <tr>
                <td colSpan={3} style={{ textAlign: 'center', color: 'var(--cor-texto-suave)' }}>
                  Nenhuma sincronização registrada.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}