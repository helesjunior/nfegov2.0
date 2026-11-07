import { Link, useParams, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { nfeApi, mensagemDeErro } from '../../lib/api'
import { formatarCnpj, formatarDataHora, formatarMoeda } from '../../lib/format'

/**
 * Detalhe de uma NF-e: dados do documento, itens e ações.
 *
 * O XML só é baixável quando a NF-e tem arquivo e o usuário possui
 * `nfe.export` — o backend devolve `xml_url` (assinada, 5 min) apenas nesse
 * caso. O DANFE é um PDF gerado sob demanda.
 */
export function NfeDetailPage() {
  const { chave = '' } = useParams()
  // A mesma chave pode existir em mais de uma unidade: `?unidade=` escolhe a cópia.
  const [busca] = useSearchParams()
  const unidadeId = Number(busca.get('unidade')) || undefined

  const { data: nfe, isLoading, isError } = useQuery({
    queryKey: ['nfe', chave, unidadeId],
    queryFn: () => nfeApi.obter(chave, unidadeId),
    enabled: chave.length > 0,
  })

  const danfe = useMutation({
    mutationFn: async () => {
      // O DANFE é binário (PDF): baixamos via fetch autenticado e abrimos.
      const sufixo = nfe ? `?unidade_id=${nfe.unidade_id}` : ''
      const resp = await fetch(`/api/nfes/${chave}/danfe${sufixo}`, {
        credentials: 'include',
        headers: { Accept: 'application/pdf' },
      })
      if (!resp.ok) throw new Error('Não foi possível gerar o DANFE.')
      const blob = await resp.blob()
      window.open(URL.createObjectURL(blob), '_blank')
    },
    onError: (e) => alert(mensagemDeErro(e)),
  })

  if (isLoading) return <div className="card">Carregando NF-e…</div>
  if (isError || !nfe) {
    return (
      <div>
        <div className="alerta alerta--erro">NF-e não encontrada.</div>
        <Link to="/nfes">Voltar</Link>
      </div>
    )
  }

  return (
    <div>
      <div className="pagina-topo">
        <h1>NF-e {nfe.numero ?? ''}</h1>
        <Link to="/nfes">Voltar</Link>
      </div>

      <div className="card">
        <div style={{ display: 'flex', gap: 'var(--esp-3)', marginBottom: 'var(--esp-4)' }}>
          {nfe.xml_url ? (
            <a className="botao" href={nfe.xml_url} target="_blank" rel="noreferrer">
              Baixar XML
            </a>
          ) : (
            <button type="button" className="botao" disabled title="XML indisponível">
              Baixar XML
            </button>
          )}
          <button
            type="button"
            className="botao botao--secundario"
            onClick={() => danfe.mutate()}
            disabled={danfe.isPending || !nfe.tem_xml}
          >
            {danfe.isPending ? 'Gerando…' : 'Gerar DANFE (PDF)'}
          </button>
        </div>

        <dl className="ficha">
          <dt>Chave de acesso</dt>
          <dd style={{ fontFamily: 'var(--fonte-mono)', wordBreak: 'break-all' }}>{nfe.chave}</dd>

          <dt>Unidade</dt>
          <dd>
            {nfe.unidade
              ? `${nfe.unidade.nome_resumido || nfe.unidade.nome} — ${formatarCnpj(nfe.unidade.cnpj)}`
              : '—'}
          </dd>

          <dt>Emitente</dt>
          <dd>
            {nfe.emitente_nome ?? '—'} — {formatarCnpj(nfe.emitente_cnpj)}
          </dd>

          <dt>Destinatário</dt>
          <dd>{formatarCnpj(nfe.dest_cnpj)}</dd>

          <dt>Emissão</dt>
          <dd>{formatarDataHora(nfe.data_emissao)}</dd>

          <dt>Natureza</dt>
          <dd>{nfe.natureza_operacao ?? '—'}</dd>

          <dt>Valor total</dt>
          <dd>{formatarMoeda(nfe.valor_total)}</dd>

          <dt>Situação</dt>
          <dd>
            <span className={nfe.estado === 'completo' ? 'selo selo--ok' : 'selo selo--neutro'}>
              {nfe.estado}
            </span>{' '}
            {nfe.cstat && <span className="shell__usuario">cStat {nfe.cstat}</span>}
          </dd>
        </dl>
      </div>

      <h2 style={{ marginTop: 'var(--esp-5)' }}>Itens</h2>
      <div className="card" style={{ padding: 0 }}>
        <table className="tabela">
          <thead>
            <tr>
              <th>#</th>
              <th>Descrição</th>
              <th>CFOP</th>
              <th>Qtd</th>
              <th>Vl. unit.</th>
              <th>Vl. total</th>
            </tr>
          </thead>
          <tbody>
            {(nfe.itens ?? []).map((item) => (
              <tr key={item.id}>
                <td>{item.sequencial}</td>
                <td>{item.descricao}</td>
                <td>{item.cfop ?? '—'}</td>
                <td>
                  {item.quantidade ?? '—'} {item.unidade_medida ?? ''}
                </td>
                <td>{formatarMoeda(item.valor_unitario)}</td>
                <td>{formatarMoeda(item.valor_total)}</td>
              </tr>
            ))}
            {(nfe.itens ?? []).length === 0 && (
              <tr>
                <td colSpan={6} style={{ textAlign: 'center', color: 'var(--cor-texto-suave)' }}>
                  Sem itens (NF-e ainda em resumo ou sem detalhamento).
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}