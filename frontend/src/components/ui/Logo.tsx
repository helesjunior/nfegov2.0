import { useId } from 'react'

/**
 * Logomarca do NFeGov.
 *
 * Símbolo: um documento fiscal (com a dobra no canto) sobre um quadrado em
 * degradê azul → verde-água, com um selo de "validado" no canto inferior.
 * O texto ao lado é opcional (no menu recolhido ou no favicon só o símbolo).
 */
export function LogoSimbolo({ tamanho = 36 }: { tamanho?: number }) {
  // ids únicos: a mesma página pode ter mais de um logo (menu + login).
  const id = useId().replace(/:/g, '')
  const fundo = `logo-fundo-${id}`
  const linha = `logo-linha-${id}`

  return (
    <svg
      width={tamanho}
      height={tamanho}
      viewBox="0 0 48 48"
      fill="none"
      aria-hidden="true"
      focusable="false"
    >
      <defs>
        <linearGradient id={fundo} x1="4" y1="2" x2="46" y2="46" gradientUnits="userSpaceOnUse">
          <stop offset="0" stopColor="#3b5bfd" />
          <stop offset="0.55" stopColor="#2f7df0" />
          <stop offset="1" stopColor="#12b8a6" />
        </linearGradient>
        <linearGradient id={linha} x1="18" y1="0" x2="32" y2="0" gradientUnits="userSpaceOnUse">
          <stop offset="0" stopColor="#3b5bfd" />
          <stop offset="1" stopColor="#2f7df0" />
        </linearGradient>
      </defs>
      <rect width="48" height="48" rx="13" fill={`url(#${fundo})`} />
      {/* brilho sutil no topo */}
      <path d="M0 13C0 5.8 5.8 0 13 0h22c7.2 0 13 5.8 13 13v3C34 10 16 22 0 18z" fill="#fff" opacity="0.09" />
      {/* documento */}
      <path
        d="M16 9.5h12.5l8 8V37a2.5 2.5 0 0 1-2.5 2.5H16a2.5 2.5 0 0 1-2.5-2.5V12a2.5 2.5 0 0 1 2.5-2.5z"
        fill="#fff"
      />
      <path d="M28.5 9.5V15a2.5 2.5 0 0 0 2.5 2.5h5.5z" fill="#cfe0ff" />
      <rect x="18" y="21" width="11" height="2.6" rx="1.3" fill={`url(#${linha})`} opacity="0.85" />
      <rect x="18" y="26.4" width="14" height="2.6" rx="1.3" fill={`url(#${linha})`} opacity="0.45" />
      <rect x="18" y="31.8" width="7" height="2.6" rx="1.3" fill={`url(#${linha})`} opacity="0.45" />
      {/* selo de validado */}
      <circle cx="35" cy="35.5" r="7.5" fill="#12b8a6" stroke="#fff" strokeWidth="2.5" />
      <path
        d="m31.6 35.6 2.3 2.3 4.3-4.6"
        stroke="#fff"
        strokeWidth="2.3"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  )
}

export function Logo({
  tamanho = 36,
  subtitulo,
}: {
  tamanho?: number
  subtitulo?: string
}) {
  return (
    <span className="logo">
      <LogoSimbolo tamanho={tamanho} />
      <span className="logo__texto">
        <span className="logo__nome">
          NFe<span className="logo__destaque">Gov</span>
          <span className="logo__versao">2.0</span>
        </span>
        {subtitulo && <span className="logo__subtitulo">{subtitulo}</span>}
      </span>
    </span>
  )
}
