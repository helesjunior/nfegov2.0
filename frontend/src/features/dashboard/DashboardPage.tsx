import { useQuery } from '@tanstack/react-query'
import { http } from '../../lib/api'

interface ResumoDashboard {
  nfes: {
    total: number
    ultimos_30_dias: number
    valor_ultimos_30_dias: number
    resumos: number
  }
  unidades: { total: number; ativas: number }
  certificados: { vencendo_30_dias: number; vencidos: number }
  sync: { com_erro_24h: number; circuito_aberto: boolean }
}

/**
 * Painel inicial.
 *
 * Versão enxuta (a tela completa é a Task 20): apresenta os indicadores
 * principais e sinaliza quando o freio da SEFAZ está acionado.
 */
export function DashboardPage() {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['dashboard'],
    queryFn: async () => {
      const { data } = await http.get<ResumoDashboard>('/dashboard')
      return data
    },
  })

  if (isLoading) return <div className="card">Carregando indicadores…</div>
  if (isError || !data) return <div className="alerta alerta--erro">Não foi possível carregar o painel.</div>

  const cartoes = [
    { rotulo: 'NF-e recebidas', valor: data.nfes.total },
    { rotulo: 'NF-e (30 dias)', valor: data.nfes.ultimos_30_dias },
    { rotulo: 'Unidades ativas', valor: data.unidades.ativas },
    { rotulo: 'Certificados vencendo (30d)', valor: data.certificados.vencendo_30_dias },
  ]

  return (
    <div>
      <h1 style={{ marginTop: 0 }}>Painel</h1>

      {data.sync.circuito_aberto && (
        <div className="alerta alerta--erro">
          O acesso à SEFAZ está temporariamente suspenso (consumo indevido). As
          sincronizações retomam automaticamente.
        </div>
      )}

      {data.certificados.vencidos > 0 && (
        <div className="alerta alerta--erro">
          {data.certificados.vencidos} certificado(s) vencido(s). Renove para manter a
          recepção de NF-e.
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
            <div style={{ fontSize: '1.8rem', fontWeight: 700 }}>{c.valor}</div>
          </div>
        ))}
      </div>
    </div>
  )
}