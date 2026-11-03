import { useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { unidadesApi } from '../../lib/api'
import { CertificadoPage } from './CertificadoPage'

/**
 * Resolve a unidade da URL (rota `/unidades/:id/certificado`) e delega à
 * `CertificadoPage`.
 *
 * Buscamos a unidade na listagem escopada ao tenant (não há endpoint de
 * detalhe): se ela não pertencer à entidade, a tela indica "não encontrada".
 */
export function UnidadeCertificadoRoute() {
  const { id } = useParams()
  const unidadeId = Number(id)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['unidades'],
    queryFn: () => unidadesApi.listar(100, 1),
    enabled: Number.isFinite(unidadeId),
  })

  if (isLoading) return <div className="card">Carregando unidade…</div>

  const unidade = data?.data.find((u) => u.id === unidadeId)
  if (isError || !unidade) {
    return <div className="alerta alerta--erro">Unidade não encontrada.</div>
  }

  return <CertificadoPage unidade={unidade} />
}