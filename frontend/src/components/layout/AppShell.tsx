import { Outlet } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useSessao } from '../../features/auth/session'
import { versaoApi } from '../../lib/api'
import { Sidebar } from './Sidebar'
import { Topbar } from './Topbar'

/**
 * Estrutura da aplicação autenticada: menu lateral + barra superior + conteúdo.
 *
 * Assume que o usuário já está autenticado (o guard de rota cuida disso antes).
 */
export function AppShell() {
  const { usuario } = useSessao()

  // Versão/build para o rodapé do menu. `staleTime` infinito: só muda num
  // novo build (basta um fetch por sessão). Falha silenciosa — o rodapé é
  // acessório e não deve quebrar a tela.
  const { data: versao } = useQuery({
    queryKey: ['versao'],
    queryFn: () => versaoApi.obter(),
    staleTime: Infinity,
    retry: false,
  })

  // Enquanto a sessão não resolve, não renderiza o shell.
  if (!usuario) return null

  return (
    <div className="shell">
      <Sidebar
        permissoes={usuario.permissoes}
        podeTodas={usuario.pode_todas}
        versao={versao?.rotulo ?? null}
      />
      <div className="shell__principal">
        <Topbar usuario={usuario} />
        <main className="shell__conteudo">
          <Outlet />
        </main>
      </div>
    </div>
  )
}