import { useCallback, useState } from 'react'

/**
 * Tema da interface: claro por padrão, escuro opcional.
 *
 * A escolha fica no `localStorage` e é aplicada em `<html data-tema>`. O
 * `index.html` aplica o tema salvo antes do primeiro paint (evita "piscar").
 */
export type Tema = 'claro' | 'escuro'

const CHAVE = 'nfegov.tema'

export function lerTema(): Tema {
  try {
    return localStorage.getItem(CHAVE) === 'escuro' ? 'escuro' : 'claro'
  } catch {
    return 'claro'
  }
}

export function aplicarTema(tema: Tema) {
  document.documentElement.dataset.tema = tema
  try {
    localStorage.setItem(CHAVE, tema)
  } catch {
    // Sem storage (modo privado): o tema vale só nesta aba.
  }
}

export function useTema() {
  const [tema, setTema] = useState<Tema>(lerTema)

  const alternar = useCallback(() => {
    const novo: Tema = tema === 'escuro' ? 'claro' : 'escuro'
    aplicarTema(novo)
    setTema(novo)
  }, [tema])

  return { tema, alternar }
}
