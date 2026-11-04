import { Outlet } from 'react-router-dom'
import { useSessao } from '../../features/auth/session'
import { Sidebar } from './Sidebar'
import { Topbar } from './Topbar'

/**
 * Estrutura da aplicação autenticada: menu lateral + barra superior + conteúdo.
 *
 * Assume que o usuário já está autenticado (o guard de rota cuida disso antes).
 */
export function AppShell() {
  const { usuario } = useSessao()

  // Enquanto a sessão não resolve, não renderiza o shell.
  if (!usuario) return null

  return (
    <div className="shell">
      <Sidebar permissoes={usuario.permissoes} podeTodas={usuario.pode_todas} />
      <div className="shell__principal">
        <Topbar usuario={usuario} />
        <main className="shell__conteudo">
          <Outlet />
        </main>
      </div>
    </div>
  )
}