import { createContext, useContext, useMemo, type ReactNode } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
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