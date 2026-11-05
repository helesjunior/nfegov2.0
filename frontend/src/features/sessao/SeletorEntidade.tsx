import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { sessaoApi } from '../../lib/api'
import { useSessao } from '../auth/session'

/**
 * Seletor do tenant ativo da sessão.
 *
 * Super-admin vê "Todas as entidades" + qualquer entidade; os demais veem
 * apenas as suas. Ao trocar, limpa o cache do React Query (os dados passam a
 * ser de outra entidade).
 *
 * O `<select>` é CONTROLADO, mas mantém uma "escolha otimista" local: a
 * seleção do usuário aparece imediatamente, sem esperar o `/me` da recarga.
 * Sem isso, o combo reverte para o valor anterior enquanto o `/me` não
 * responde (o usuário clica e "não seleciona").
 */
export function SeletorEntidade() {
  const { usuario, recarregar } = useSessao()
  const queryClient = useQueryClient()
  const [escolhaOtimista, setEscolhaOtimista] = useState<string | null>(null)
  const [trocando, setTrocando] = useState(false)

  if (!usuario) return null

  const soUma = usuario.tenants_disponiveis.length <= 1 && !usuario.pode_todas
  if (soUma) return null

  async function trocar(valor: string) {
    const tenantId = valor === '' ? null : Number(valor)
    // Reflete a escolha IMEDIATAMENTE (o `/me` pode demorar).
    setEscolhaOtimista(valor)
    setTrocando(true)

    try {
      await sessaoApi.trocarTenant(tenantId)
      // Invalida as queries ativas para revisitar o novo tenant. NÃO usamos
      // `queryClient.clear()`: ele removeria a própria query da sessão, deixando
      // a recarga do `/me` sem efeito (o seletor não refletiria a nova entidade).
      await queryClient.invalidateQueries()
      await recarregar()
      // A sessão já reflete a escolha; o otimista pode ser descartado.
      setEscolhaOtimista(null)
    } catch {
      // Em falha, descarta a escolha otimista: o select volta ao valor real
      // da sessão. (A página de erro/mensagem é responsabilidade de cada tela.)
      setEscolhaOtimista(null)
    } finally {
      setTrocando(false)
    }
  }

  // A escolha otimista tem precedência enquanto a troca está em andamento;
  // caso contrário, reflete o que a sessão diz.
  const atual = escolhaOtimista ?? (usuario.tenant_ativo?.id ?? '')

  return (
    <label className="shell__entidade">
      <span className="shell__entidade-rotulo">Entidade</span>
      <select
        aria-label="Entidade"
        value={atual}
        disabled={trocando}
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