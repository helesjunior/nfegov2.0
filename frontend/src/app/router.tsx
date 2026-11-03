import { createBrowserRouter, Navigate, type RouteObject } from 'react-router-dom'
import { AppShell } from '../components/layout/AppShell'
import { LoginPage } from '../features/auth/LoginPage'
import { MfaPage } from '../features/auth/MfaPage'
import { useSessao } from '../features/auth/session'
import { DashboardPage } from '../features/dashboard/DashboardPage'
import { UnidadeCertificadoRoute } from '../features/certificados/UnidadeCertificadoRoute'
import { UnidadesPage } from '../features/unidades/UnidadesPage'
import { SyncPage } from '../features/sync/SyncPage'

/**
 * Guard de rota autenticada.
 *
 * Enquanto `/api/me` não responde, mostra um placeholder. Sem usuário, manda
 * para o login. Se o MFA estiver pendente, força a tela de configuração.
 */
function RotaProtegida({ children }: { children: React.ReactNode }) {
  const { carregando, autenticado, usuario } = useSessao()

  if (carregando) {
    return <div className="auth-tela">Carregando…</div>
  }

  if (!autenticado) {
    return <Navigate to="/login" replace />
  }

  if (usuario?.mfa_pendente) {
    return <Navigate to="/mfa" replace />
  }

  return <>{children}</>
}

export const routes: RouteObject[] = [
  { path: '/login', element: <LoginPage /> },
  { path: '/mfa', element: <MfaPage /> },
  {
    path: '/',
    element: (
      <RotaProtegida>
        <AppShell />
      </RotaProtegida>
    ),
    children: [
      { index: true, element: <DashboardPage /> },
      { path: 'nfes', element: <Placeholder titulo="NF-e" /> },
      { path: 'unidades', element: <UnidadesPage /> },
      { path: 'unidades/:id/certificado', element: <UnidadeCertificadoRoute /> },
      { path: 'sync', element: <SyncPage /> },
    ],
  },
  { path: '*', element: <Navigate to="/" replace /> },
]

/**
 * Placeholder das telas que chegam na próxima tarefa (lista/detalhe de NF-e).
 */
function Placeholder({ titulo }: { titulo: string }) {
  return (
    <div className="card">
      <h2 style={{ marginTop: 0 }}>{titulo}</h2>
      <p className="shell__usuario">Esta tela será construída na próxima etapa.</p>
    </div>
  )
}

export const router = createBrowserRouter(routes)