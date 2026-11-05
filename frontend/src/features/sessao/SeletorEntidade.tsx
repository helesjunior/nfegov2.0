import { useQueryClient } from '@tanstack/react-query'
import { sessaoApi } from '../../lib/api'
import { useSessao } from '../auth/session'

/**
 * Seletor do tenant ativo da sessão.
 *
 * Super-admin vê "Todas as entidades" + qualquer entidade; os demais veem
 * apenas as suas. Ao trocar, limpa o cache do React Query (os dados passam a
 * ser de outra entidade).
 */
export function SeletorEntidade() {
  const { usuario, recarregar } = useSessao()
  const queryClient = useQueryClient()

  if (!usuario) return null

  const soUma = usuario.tenants_disponiveis.length <= 1 && !usuario.pode_todas
  if (soUma) return null

  async function trocar(valor: string) {
    const tenantId = valor === '' ? null : Number(valor)
    await sessaoApi.trocarTenant(tenantId)
    // Invalida as queries ativas para revisitar o novo tenant. NÃO usamos
    // `queryClient.clear()`: ele removeria a própria query da sessão, deixando
    // a recarga do `/me` sem efeito (o seletor não refletiria a nova entidade).
    await queryClient.invalidateQueries()
    await recarregar()
  }

  const atual = usuario.tenant_ativo?.id ?? ''

  return (
    <label className="shell__entidade">
      <span className="shell__entidade-rotulo">Entidade</span>
      <select
        aria-label="Entidade"
        value={atual}
        onChange={(e) => trocar(e.target.value)}
      >
        {usuario.pode_todas && <option value="">Todas as entidades</option>}
        {usuario.tenants_disponiveis.map((t) => (
          <option key={t.id} value={t.id}>
            {t.nome}
          </option>
        ))}
      </select>
    </label>
  )
}