// Gera o `build.json` do backend (timestamp da build) antes do `vite build`.
//
// Por que existe: o rodapé do menu exibe `NFeGov2.0 - v. <versão>-<YYYYMMDD.HHMMSS>`.
// A versão semântica vive em `backend/VERSION` (fonte de verdade única) e o
// timestamp é gravado AQUI, no momento do build — nunca em runtime. Assim o que
// aparece na tela é, de fato, quando aquele bundle foi construído.
//
// Saída: `backend/build.json` com `{ "build_at": "YYYYMMDD.HHMMSS" }`, no fuso
// local da máquina que executa o build.

import { writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const aqui = dirname(fileURLToPath(import.meta.url))
const destino = resolve(aqui, '..', '..', 'backend', 'build.json')

function doisDigitos(n) {
  return String(n).padStart(2, '0')
}

const agora = new Date()
const buildAt =
  `${agora.getFullYear()}${doisDigitos(agora.getMonth() + 1)}${doisDigitos(agora.getDate())}` +
  `.${doisDigitos(agora.getHours())}${doisDigitos(agora.getMinutes())}${doisDigitos(agora.getSeconds())}`

writeFileSync(destino, JSON.stringify({ build_at: buildAt }, null, 2) + '\n', 'utf8')

console.log(`build.json gerado: ${destino} (build_at=${buildAt})`)