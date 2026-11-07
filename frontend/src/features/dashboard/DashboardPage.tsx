import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { dashboardApi } from '../../lib/api'
import { formatarDataHora, formatarMoeda } from '../../lib/format'
import { Icone, type NomeIcone } from '../../components/ui/Icone'
import { StatusSelo } from '../sync/SyncPage'
import { GraficoFornecedores, GraficoPorDia } from './Graficos'

interface Cartao {
  rotulo: string
  valor: string
  icone: NomeIcone
  cor: string
}

function Indicadores({ cartoes }: { cartoes: Cartao[] }) {
  return (
    <div className="kpis">
      {cartoes.map((c) => (
        <div className={`card kpi kpi--${c.cor}`} key={c.rotulo}>
          <span className="kpi__icone">
            <Icone nome={c.icone} tamanho={20} />
          </span>
          <div>
            <div className="kpi__rotulo">{c.rotulo}</div>
            <div className="kpi__valor">{c.valor}</div>
          </div>
        </div>
      ))}
    </div>
  )
}

/**
 * Painel inicial: visão geral da recepção de NF-e e de NFS-e.
 *
 * Reúne os indicadores e gráficos de cada documento, o estado dos freios da
 * SEFAZ e do ADN e as últimas execuções de sincronização. O bloco de NFS-e só
 * aparece com a recepção de NFS-e ligada (o backend manda `nfses: null`
 * quando está desligada).
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

  const cartoes: Cartao[] = [
    { rotulo: 'NF-e recebidas', valor: String(data.nfes.total), icone: 'documento', cor: 'azul' },
    { rotulo: 'NF-e (30 dias)', valor: String(data.nfes.ultimos_30_dias), icone: 'calendario', cor: 'anil' },
    { rotulo: 'Valor (30 dias)', valor: formatarMoeda(data.nfes.valor_ultimos_30_dias), icone: 'moeda', cor: 'verde' },
    { rotulo: 'Resumos pendentes', valor: String(data.nfes.resumos), icone: 'relogio', cor: 'ambar' },
    { rotulo: 'Unidades ativas', valor: `${data.unidades.ativas} / ${data.unidades.total}`, icone: 'predio', cor: 'agua' },
    { rotulo: 'Certificados vencendo (30d)', valor: String(data.certificados.vencendo_30_dias), icone: 'escudo', cor: 'rosa' },
  ]

  const nfse = data.nfses
  const cartoesNfse: Cartao[] = nfse
    ? [
        { rotulo: 'NFS-e recebidas', valor: String(nfse.total), icone: 'documento', cor: 'azul' },
        { rotulo: 'NFS-e (30 dias)', valor: String(nfse.ultimos_30_dias), icone: 'calendario', cor: 'anil' },
        { rotulo: 'Serviços (30 dias)', valor: formatarMoeda(nfse.valor_ultimos_30_dias), icone: 'moeda', cor: 'verde' },
        { rotulo: 'Sem manifestação', valor: String(nfse.sem_manifestacao), icone: 'relogio', cor: 'ambar' },
        { rotulo: 'Canceladas (30 dias)', valor: String(nfse.canceladas_30_dias), icone: 'escudo', cor: 'rosa' },
      ]
    : []

  return (
    <div>
      <div className="pagina-topo">
        <div>
          <h1>Painel</h1>
          <p className="pagina-subtitulo">Visão geral da recepção de NF-e e NFS-e.</p>
        </div>
      </div>

      {data.sync.circuito_aberto && (
        <div className="alerta alerta--erro">
          O acesso à SEFAZ está temporariamente suspenso (freio anti-bloqueio). As
          sincronizações retomam automaticamente.
        </div>
      )}

      {data.sync.circuito_adn_aberto && (
        <div className="alerta alerta--erro">
          O acesso ao Ambiente de Dados Nacional (NFS-e) está temporariamente suspenso (freio
          anti-bloqueio). As sincronizações de NFS-e retomam automaticamente.
        </div>
      )}

      {data.certificados.vencidos > 0 && (
        <div className="alerta alerta--erro">
          {data.certificados.vencidos} certificado(s) vencido(s). Renove para manter a recepção de
          NF-e e NFS-e.
        </div>
      )}

      {data.sync.com_erro_24h > 0 && (
        <div className="alerta alerta--info">
          {data.sync.com_erro_24h} sincronização(ões) com erro nas últimas 24 horas.{' '}
          <Link to="/sync">Ver detalhes</Link>
        </div>
      )}

      <section aria-labelledby="painel-nfe">
        <h2 className="secao-titulo" id="painel-nfe">
          NF-e
        </h2>

        <Indicadores cartoes={cartoes} />

        {data.graficos && (
          <div className="graficos">
            <GraficoPorDia dias={data.graficos.por_dia} />
            <GraficoFornecedores fornecedores={data.graficos.fornecedores} />
          </div>
        )}
      </section>

      {nfse && (
        <section aria-labelledby="painel-nfse">
          <h2 className="secao-titulo" id="painel-nfse">
            NFS-e
          </h2>
          <p className="pagina-subtitulo">
            Notas de serviço recebidas pelo Ambiente de Dados Nacional (a unidade é a tomadora).
          </p>

          <Indicadores cartoes={cartoesNfse} />

          {data.graficos?.nfse_por_dia && data.graficos.nfse_prestadores && (
            <div className="graficos">
              <GraficoPorDia dias={data.graficos.nfse_por_dia} documento="NFS-e" />
              <GraficoFornecedores
                documento="NFS-e"
                fornecedores={data.graficos.nfse_prestadores.map((p) => ({
                  cnpj: p.documento,
                  nome: p.nome,
                  quantidade: p.quantidade,
                  valor: p.valor,
                }))}
              />
            </div>
          )}

          {nfse.sem_manifestacao > 0 && (
            <p className="pagina-subtitulo">
              {nfse.sem_manifestacao} nota(s) recebida(s) ainda sem manifestação do tomador.{' '}
              <Link to="/nfses">Ver NFS-e</Link>
            </p>
          )}
        </section>
      )}

      <h2 className="secao-titulo">Últimas sincronizações</h2>
      <div className="card" style={{ padding: 0 }}>
        <table className="tabela">
          <thead>
            <tr>
              <th>Início</th>
              <th>Documento</th>
              <th>Status</th>
              <th>Notas</th>
            </tr>
          </thead>
          <tbody>
            {data.sync.ultimas.map((s) => (
              <tr key={s.id}>
                <td>{formatarDataHora(s.iniciado_em)}</td>
                <td>{s.tipo === 'nfse' ? 'NFS-e' : 'NF-e'}</td>
                <td>
                  <StatusSelo status={s.status} />
                </td>
                <td>{s.docs_count}</td>
              </tr>
            ))}
            {data.sync.ultimas.length === 0 && (
              <tr>
                <td colSpan={4} className="tabela__vazia">
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
