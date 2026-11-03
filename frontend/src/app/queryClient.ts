import { QueryClient } from '@tanstack/react-query'

/**
 * Cliente do TanStack Query.
 *
 * - `staleTime` curto: dados fiscais são receptivos à mudança (novas NF-e chegam).
 * - Sem retry para erros 4xx (ex.: 401/403/404) — repetir não adianta; mas
 *   toleramos uma tentativa extra em falhas de rede/servidor.
 */
export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      refetchOnWindowFocus: false,
      retry: (failureCount, error) => {
        const status = (error as { response?: { status?: number } })?.response?.status
        if (status && status < 500) return false
        return failureCount < 2
      },
    },
    mutations: {
      retry: false,
    },
  },
})