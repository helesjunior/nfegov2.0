import { useState } from 'react'
import type { DashboardResumo } from '../../lib/api'
import { formatarMoeda } from '../../lib/format'

type Dia = DashboardResumo['graficos']['por_dia'][number]
type Fornecedor = DashboardResumo['graficos']['fornecedores'][number]

/** `2026-10-09` -> `09/10` (sem passar por Date, para não sofrer com fuso). */
function diaMes(data: string): string {
  const [, mes, dia] = data.split('-')
  return `${dia}/${mes}`
}

/** Tipo de documento que o gráfico mostra (muda os textos, não o desenho). */
export type Documento = 'NF-e' | 'NFS-e'

function qtd(n: number, documento: Documento): string {
  return `${n} ${documento}`
}

/**
 * Colunas com a quantidade de notas por dia de emissão (últimos 30 dias).
 *
 * Uma única série, na cor primária. O dia sob o cursor (ou foco do teclado)
 * aparece no cabeçalho; sem interação, mostra o total do período. Serve para
 * NF-e e para NFS-e: só mudam os textos.
 */
export function GraficoPorDia({
  dias,
  documento = 'NF-e',
}: {
  dias: Dia[]
  documento?: Documento
}) {
  const [ativo, setAtivo] = useState<number | null>(null)
  const maximo = Math.max(1, ...dias.map((d) => d.quantidade))
  const total = dias.reduce((s, d) => s + d.quantidade, 0)
  const valor = dias.reduce((s, d) => s + d.valor, 0)
  const sel = ativo !== null ? dias[ativo] : null

  return (
    <div className="card grafico">
      <div className="grafico__topo">
        <div>
          <h2 className="grafico__titulo">{documento} por dia</h2>
          <p className="grafico__sub">Data de emissão · últimos 30 dias</p>
        </div>
        <div className="grafico__destaque" aria-live="polite" data-testid="destaque-dia">
          {sel ? (
            <>
              <strong>{qtd(sel.quantidade, documento)}</strong>
              <span>
                {diaMes(sel.data)} · {formatarMoeda(sel.valor)}
              </span>
            </>
          ) : (
            <>
              <strong>{qtd(total, documento)}</strong>
              <span>{formatarMoeda(valor)} no período</span>
            </>
          )}
        </div>
      </div>

      {total === 0 ? (
        <p className="grafico__vazio">Nenhuma {documento} emitida nos últimos 30 dias.</p>
      ) : (
        <>
          <div className="colunas" aria-hidden="true" onMouseLeave={() => setAtivo(null)}>
            <span className="colunas__max">{maximo}</span>
            {dias.map((d, i) => (
              <div
                key={d.data}
                className={`colunas__slot${ativo === i ? ' colunas__slot--ativo' : ''}`}
                onMouseEnter={() => setAtivo(i)}
              >
                <span
                  className="colunas__barra"
                  style={{ height: d.quantidade ? `${(d.quantidade / maximo) * 100}%` : 0 }}
                />
              </div>
            ))}
          </div>
          <div className="colunas__eixo" aria-hidden="true">
            <span>{diaMes(dias[0].data)}</span>
            <span>{diaMes(dias[Math.floor(dias.length / 2)].data)}</span>
            <span>Hoje</span>
          </div>
        </>
      )}

      {/* Visão em tabela para leitores de tela. */}
      <table className="sr-only">
        <caption>{documento} por dia de emissão, últimos 30 dias</caption>
        <thead>
          <tr>
            <th>Dia</th>
            <th>Quantidade</th>
            <th>Valor</th>
          </tr>
        </thead>
        <tbody>
          {dias.map((d) => (
            <tr key={d.data}>
              <td>{diaMes(d.data)}</td>
              <td>{d.quantidade}</td>
              <td>{formatarMoeda(d.valor)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

/**
 * Barras horizontais com os 5 maiores emitentes por valor (últimos 30 dias):
 * fornecedores de NF-e ou prestadores de NFS-e. O valor fica escrito na ponta
 * de cada barra; o nome, acima dela.
 */
export function GraficoFornecedores({
  fornecedores,
  documento = 'NF-e',
}: {
  fornecedores: Fornecedor[]
  documento?: Documento
}) {
  const maximo = Math.max(1, ...fornecedores.map((f) => f.valor))
  const titulo = documento === 'NFS-e' ? 'Maiores prestadores' : 'Maiores fornecedores'
  const vazio =
    documento === 'NFS-e'
      ? 'Sem notas de prestadores no período.'
      : 'Sem notas de fornecedores no período.'

  return (
    <div className="card grafico">
      <div className="grafico__topo">
        <div>
          <h2 className="grafico__titulo">{titulo}</h2>
          <p className="grafico__sub">Por valor · últimos 30 dias</p>
        </div>
      </div>

      {fornecedores.length === 0 ? (
        <p className="grafico__vazio">{vazio}</p>
      ) : (
        <ol className="barras">
          {fornecedores.map((f) => (
            <li key={f.cnpj ?? f.nome} title={`${f.nome} · ${qtd(f.quantidade, documento)}`}>
              <div className="barras__nome">{f.nome}</div>
              <div className="barras__linha">
                <span className="barras__trilho">
                  <span
                    className="barras__barra"
                    style={{ width: `${Math.max(2, (f.valor / maximo) * 100)}%` }}
                  />
                </span>
                <span className="barras__valor">{formatarMoeda(f.valor)}</span>
              </div>
            </li>
          ))}
        </ol>
      )}
    </div>
  )
}
