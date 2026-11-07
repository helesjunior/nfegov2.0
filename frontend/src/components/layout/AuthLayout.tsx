import type { ReactNode } from 'react'
import { Icone, type NomeIcone } from '../ui/Icone'
import { Logo } from '../ui/Logo'

const DESTAQUES: { icone: NomeIcone; texto: string }[] = [
  { icone: 'sincronizar', texto: 'Recepção automática de NF-e (SEFAZ) e NFS-e (ambiente nacional)' },
  { icone: 'documento', texto: 'Consulta, DANFE, DANFSe e XML em um só lugar' },
  { icone: 'cadeado', texto: 'Acesso seguro com verificação em duas etapas' },
]

/**
 * Moldura das telas de autenticação (login e MFA): painel de marca à
 * esquerda e o formulário à direita. Em telas estreitas, só o formulário.
 */
export function AuthLayout({ children }: { children: ReactNode }) {
  return (
    <div className="auth-tela">
      <section className="auth-marca" aria-hidden="true">
        <div className="auth-marca__topo">
          <Logo tamanho={44} />
        </div>
        <div className="auth-marca__corpo">
          <h2 className="auth-marca__titulo">
            Notas fiscais do seu órgão, <span>organizadas e seguras.</span>
          </h2>
          <ul className="auth-marca__lista">
            {DESTAQUES.map((d) => (
              <li key={d.texto}>
                <span className="auth-marca__icone">
                  <Icone nome={d.icone} />
                </span>
                {d.texto}
              </li>
            ))}
          </ul>
        </div>
        <div className="auth-marca__rodape">NFeGov 2.0 · Recepção e consulta de NF-e e NFS-e</div>
      </section>

      <main className="auth-formulario">
        <div className="auth-card">
          <div className="auth-logo-movel">
            <Logo tamanho={40} />
          </div>
          {children}
        </div>
      </main>
    </div>
  )
}
