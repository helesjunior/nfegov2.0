import { Link, useParams, useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { nfseApi } from '../../lib/api'
import {
  formatarCnpj,
  formatarCompetencia,
  formatarDataHora,
  formatarDocumento,
  formatarMoeda,
} from '../../lib/format'
import { ROTULO_PAPEL, ROTULO_SITUACAO, classeSituacao, rotularEvento } from './rotulos'

/**
 * Detalhe de uma NFS-e: partes, serviço, valores e eventos.
 *
 * O XML só é baixável com `nfe.export`: o backend devolve `xml_url`
 * (assinada, 5 min) apenas nesse caso. O DANFSe (PDF) fica para uma próxima
 * versão: a API oficial que o gerava foi desativada em 07/2026.
 */
export function NfseDetailPage() {
  const { chave = '' } = useParams()
  const [busca] = useSearchParams()
  const unidadeId = Number(busca.get('unidade')) || undefined

  const { data: nfse, isLoading, isError } = useQuery({
    queryKey: ['nfse', chave, unidadeId],
    queryFn: () => nfseApi.obter(chave, unidadeId),
    enabled: chave.length > 0,
  })

  if (isLoading) return <div className="card">Carregando NFS-e…</div>
  if (isError || !nfse) {
    return (
      <div>
        <div className="alerta alerta--erro">NFS-e não encontrada.</div>
        <Link to="/nfses">Voltar</Link>
      </div>
    )
  }

  const eventos = nfse.eventos ?? []

  return (
    <div>
      <div className="pagina-topo">
        <h1>NFS-e {nfse.numero ?? ''}</h1>
        <Link to="/nfses">Voltar</Link>
      </div>

      <div className="card">
        <div style={{ display: 'flex', gap: 'var(--esp-3)', marginBottom: 'var(--esp-4)' }}>
          {nfse.xml_url ? (
            <a className="botao" href={nfse.xml_url} target="_blank" rel="noreferrer">
              Baixar XML
            </a>
          ) : (
            <button type="button" className="botao" disabled title="XML indisponível">
              Baixar XML
            </button>
          )}
        </div>

        <dl className="ficha">
          <dt>Chave de acesso</dt>
          <dd style={{ fontFamily: 'var(--fonte-mono)', wordBreak: 'break-all' }}>{nfse.chave}</dd>

          <dt>Situação</dt>
          <dd>
            <span className={classeSituacao(nfse.situacao)}>{ROTULO_SITUACAO[nfse.situacao]}</span>{' '}
            <span className="shell__usuario">{ROTULO_PAPEL[nfse.papel]} pela unidade</span>
          </dd>

          <dt>Unidade</dt>
          <dd>
            {nfse.unidade
              ? `${nfse.unidade.nome_resumido || nfse.unidade.nome} — ${formatarCnpj(nfse.unidade.cnpj)}`
              : '—'}
          </dd>

          <dt>Prestador</dt>
          <dd>
            {nfse.prestador_nome ?? '—'} — {formatarDocumento(nfse.prestador_documento)}
          </dd>

          <dt>Tomador</dt>
          <dd>
            {nfse.tomador_nome ?? '—'} — {formatarDocumento(nfse.tomador_documento)}
          </dd>

          {nfse.intermediario_documento && (
            <>
              <dt>Intermediário</dt>
              <dd>
                {nfse.intermediario_nome ?? '—'} — {formatarDocumento(nfse.intermediario_documento)}
              </dd>
            </>
          )}

          <dt>Município emissor</dt>
          <dd>{nfse.municipio_emissor_nome ?? nfse.municipio_emissor_codigo ?? '—'}</dd>

          <dt>Emissão</dt>
          <dd>{formatarDataHora(nfse.data_emissao)}</dd>

          <dt>Competência</dt>
          <dd>{formatarCompetencia(nfse.competencia)}</dd>

          <dt>Serviço</dt>
          <dd>
            {nfse.codigo_servico ?? '—'} {nfse.servico_nome ? `— ${nfse.servico_nome}` : ''}
          </dd>

          <dt>Descrição</dt>
          <dd style={{ whiteSpace: 'pre-line' }}>{nfse.descricao_servico ?? '—'}</dd>

          <dt>Valor do serviço</dt>
          <dd>{formatarMoeda(nfse.valor_servico)}</dd>

          <dt>Valor líquido</dt>
          <dd>{formatarMoeda(nfse.valor_liquido)}</dd>

          <dt>ISS</dt>
          <dd>
            {nfse.valor_iss !== null ? formatarMoeda(nfse.valor_iss) : '—'}
            {nfse.iss_retido ? ' (retido)' : ''}
          </dd>
        </dl>
      </div>

      <h2 style={{ marginTop: 'var(--esp-5)' }}>Eventos</h2>
      <div className="card" style={{ padding: 0 }}>
        <table className="tabela">
          <thead>
            <tr>
              <th>Data</th>
              <th>Evento</th>
              <th>Motivo</th>
            </tr>
          </thead>
          <tbody>
            {eventos.map((evento) => (
              <tr key={evento.id}>
                <td>{formatarDataHora(evento.data_evento)}</td>
                <td>{evento.descricao ?? rotularEvento(evento.tipo_evento)}</td>
                <td>{evento.motivo ?? '—'}</td>
              </tr>
            ))}
            {eventos.length === 0 && (
              <tr>
                <td colSpan={3} style={{ textAlign: 'center', color: 'var(--cor-texto-suave)' }}>
                  Nenhum evento para esta nota.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}
