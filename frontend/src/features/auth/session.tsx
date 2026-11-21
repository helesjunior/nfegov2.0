import { createContext, useContext, useMemo, type ReactNode } from 'react'
import { useQuery, useQueryClient, type QueryClient } from '@tanstack/react-query'
import { authApi, type Usuario } from '../../lib/api'

/**
 * Sessão do usuário (fonte de verdade no cliente).
 *
 * Busca `/api/me` uma vez e compartilha o usuário por contexto. `recarregar`
 * é usado após login/logout para revolver o estado.
 */
interface SessionContextValue {
  usuario: Usuario | null
  carregando: boolean
  autenticado: boolean
  recarregar: () => Promise<void>
}

const SessionContext = createContext<SessionContextValue | null>(null)

/**
 * Descarta o cache ao sair da conta.
 *
 * NÃO use `queryClient.clear()` aqui: com o `SessionProvider` montado ele apaga a
 * query da sessão, mas o provedor continua preso à query antiga e, depois do login
 * de OUTRO usuário (sem recarregar a página), segue mostrando o usuário anterior.
 * Aqui a query da sessão é só reiniciada (o provedor continua ligado a ela) e o
 * resto do cache é removido.
 */
export function limparCacheDaSessao(queryClient: QueryClient): void {
  queryClient.removeQueries({ predicate: (query) => query.queryKey[0] !== 'sessao' })
  void queryClient.resetQueries({ queryKey: ['sessao'] })
}

export function SessionProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()

  const { data, isLoading } = useQuery({
    queryKey: ['sessao'],
    queryFn: () => authApi.me(),
    retry: false,
    staleTime: 60_000,
  })

  const value = useMemo<SessionContextValue>(
    () => ({
      usuario: data?.user ?? null,
      carregando: isLoading,
      autenticado: Boolean(data?.user),
      recarregar: async () => {
        await queryClient.invalidateQueries({ queryKey: ['sessao'] })
      },
    }),
    [data, isLoading, queryClient],
  )

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>
}

/**
 * Acessa a sessão. Deve ser usado dentro de `<SessionProvider>`.
 */
export function useSessao(): SessionContextValue {
  const ctx = useContext(SessionContext)

  if (!ctx) {
    throw new Error('useSessao deve ser usado dentro de <SessionProvider>.')
  }

  return ctx
}
/**
 * O usuário tem a permissão? Super-admin tem todas. Espelha o RBAC do backend
 * para mostrar/ocultar menus e botões; a autorização efetiva é do servidor.
 */
export function temPermissao(
  usuario: Pick<Usuario, 'papeis' | 'permissoes'> | null | undefined,
  permissao: string,
): boolean {
  if (!usuario) return false
  return usuario.papeis.includes('super-admin') || usuario.permissoes.includes(permissao)
}

/** Atalho de `temPermissao` para o usuário da sessão. */
export function usePermissao(permissao: string): boolean {
  const { usuario } = useSessao()
  return temPermissao(usuario, permissao)
}
