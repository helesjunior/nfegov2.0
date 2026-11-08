import { NavLink } from 'react-router-dom'
import { Icone, type NomeIcone } from '../ui/Icone'
import { Logo } from '../ui/Logo'

/**
 * Navegação lateral.
 *
 * Os itens são filtrados por permissão: cada link declara a permissão exigida
 * (nomes iguais aos do backend, ex.: `nfe.view`) e só aparece se o usuário
 * tiver alguma delas. Isso espelha o RBAC do servidor no cliente.
 *
 * Itens marcados com `soSuperAdmin` só aparecem quando `podeTodas` é verdadeiro
 * (super-admin, ex.: administração de entidades).
 */
export interface ItemMenu {
  para: string
  rotulo: string
  icone: NomeIcone
  permissoes?: string[]
  soSuperAdmin?: boolean
  exato?: boolean
}

const ITENS: ItemMenu[] = [
  { para: '/', rotulo: 'Painel', icone: 'painel', exato: true },
  { para: '/nfes', rotulo: 'NF-e', icone: 'documento', permissoes: ['nfe.view'] },
  { para: '/nfses', rotulo: 'NFS-e', icone: 'documento', permissoes: ['nfe.view'] },
  { para: '/unidades', rotulo: 'Unidades', icone: 'predio', permissoes: ['unidade.view'] },
  { para: '/sync', rotulo: 'Sincronizações', icone: 'sincronizar', permissoes: ['nfe.view'] },
  { para: '/notificacoes', rotulo: 'Notificações', icone: 'sino' },
  { para: '/usuarios', rotulo: 'Usuários', icone: 'usuarios', permissoes: ['usuario.gerenciar'] },
  { para: '/entidades', rotulo: 'Entidades', icone: 'instituicao', soSuperAdmin: true },
]

export function Sidebar({
  permissoes,
  podeTodas = false,
  versao,
}: {
  permissoes: string[]
  podeTodas?: boolean
  versao?: string | null
}) {
  const visiveis = ITENS.filter(
    (item) =>
      (!item.permissoes || item.permissoes.some((p) => permissoes.includes(p))) &&
      (!item.soSuperAdmin || podeTodas),
  )

  return (
    <aside className="shell__menu">
      <div className="shell__marca">
        <Logo subtitulo="Recepção de NF-e e NFS-e" />
      </div>
      <div className="shell__secao">Menu</div>
      <nav className="shell__nav">
        {visiveis.map((item) => (
          <NavLink
            key={item.para}
            to={item.para}
            end={item.exato}
            className={({ isActive }) =>
              `shell__link${isActive ? ' shell__link--ativo' : ''}`
            }
          >
            <Icone nome={item.icone} />
            <span>{item.rotulo}</span>
          </NavLink>
        ))}
      </nav>
      {versao ? (
        <div className="shell__versao" data-testid="versao-app">
          NFeGov2.0 - v. {versao}
        </div>
      ) : null}
    </aside>
  )
}
