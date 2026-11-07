import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { notificacoesApi, mensagemDeErro, type Notificacao } from '../../lib/api'
import { formatarDataHora } from '../../lib/format'

/**
 * Central de notificações do usuário.
 *
 * Principal uso hoje: avisos de exportações concluídas, com link assinado para
 * baixar o arquivo gerado (CSV/XLSX/ZIP). O link é temporário — obtido a cada
 * carregamento, nunca permanente.
 */
export function NotificacoesPage() {
  const queryClient = useQueryClient()

  const { data, isLoading, isError } = useQuery({
    queryKey: ['notificacoes'],
    queryFn: () => notificacoesApi.listar(),
  })

  const marcarLida = useMutation({
    mutationFn: (id: string) => notificacoesApi.marcarComoLida(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['notificacoes'] }),
    onError: (e) => alert(mensagemDeErro(e)),
  })

  return (
    <div>
      <h1 style={{ marginTop: 0 }}>Notificações</h1>

      {isLoading && <div className="card">Carregando notificações…</div>}
      {isError && (
        <div className="alerta alerta--erro">Não foi possível carregar as notificações.</div>
      )}

      {data && (
        <>
          {data.nao_lidas > 0 && (
            <div className="alerta alerta--info">{data.nao_lidas} notificação(ões) não lida(s).</div>
          )}

          <div className="card" style={{ padding: 0 }}>
            <table className="tabela">
              <thead>
                <tr>
                  <th>Quando</th>
                  <th>Mensagem</th>
                  <th>Ação</th>
                </tr>
              </thead>
              <tbody>
                {data.data.map((n) => (
                  <LinhaNotificacao
                    key={n.id}
                    notificacao={n}
                    onMarcarLida={() => marcarLida.mutate(n.id)}
                  />
                ))}
                {data.data.length === 0 && (
                  <tr>
                    <td colSpan={3} style={{ textAlign: 'center', color: 'var(--cor-texto-suave)' }}>
                      Nenhuma notificação.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </>
      )}
    </div>
  )
}

function LinhaNotificacao({
  notificacao,
  onMarcarLida,
}: {
  notificacao: Notificacao
  onMarcarLida: () => void
}) {
  const { dados } = notificacao

  const texto =
    dados.tipo === 'recuperacao'
      ? (dados.mensagem ?? 'Busca de NF-e concluída.')
      : `Exportação${dados.documento ? ` de ${dados.documento}` : ''} ${dados.formato ?? ''} pronta (${dados.total ?? 0} registro(s)).`

  return (
    <tr style={{ fontWeight: notificacao.lida ? 'normal' : 600 }}>
      <td>{formatarDataHora(notificacao.criada_em)}</td>
      <td>
        {texto}
        {!notificacao.lida && <span className="selo selo--alerta"> nova</span>}
      </td>
      <td style={{ display: 'flex', gap: 'var(--esp-2)' }}>
        {notificacao.url_download && (
          <a className="botao" href={notificacao.url_download}>
            Baixar
          </a>
        )}
        {!notificacao.lida && (
          <button type="button" className="botao botao--secundario" onClick={onMarcarLida}>
            Marcar lida
          </button>
        )}
      </td>
    </tr>
  )
}