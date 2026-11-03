import { NavLink } from 'react-router-dom'

/**
 * Navegação lateral.
 *
 * Os itens são filtrados por permissão: cada link declara a permissão exigida
 * (nomes iguais aos do backend, ex.: `nfe.view`) e só aparece se o usuário
 * tiver alguma delas. Isso espelha o RBAC do servidor no cliente.
 */
export interface ItemMenu {
  para: string
  rotulo: string
  permissoes?: string[]
  exato?: boolean
}

const ITENS: ItemMenu[] = [
  { para: '/', rotulo: 'Painel', exato: true },
  { para: '/nfes', rotulo: 'NF-e', permissoes: ['nfe.view'] },
  { para: '/unidades', rotulo: 'Unidades', permissoes: ['nfe.view'] },
  { para: '/sync', rotulo: 'Sincronizações', permissoes: ['nfe.view'] },
]

export function Sidebar({ permissoes }: { permissoes: string[] }) {
  const visiveis = ITENS.filter(
    (item) => !item.permissoes || item.permissoes.some((p) => permissoes.includes(p)),
  )

  return (
    <aside className="shell__menu">
      <div className="shell__marca">NF-e Gov</div>
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
            {item.rotulo}
          </NavLink>
        ))}
      </nav>
    </aside>
  )
}