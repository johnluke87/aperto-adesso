import { api } from './api.js'
import { DEFAULT_CATS } from './util.js'

// Filtri iniziali della ricerca (categorie, raggio, solo aperti).
// Con l'account stanno nel database (valgono su ogni dispositivo); senza, nel browser (localStorage).
const KEY = 'aa-prefs'
export const BASE_PREFS = { cats: DEFAULT_CATS, r: 5, only_open: false }

function fromBrowser() {
  try {
    const p = JSON.parse(localStorage.getItem(KEY) || 'null')
    return p && Array.isArray(p.cats) ? p : null
  } catch {
    return null
  }
}

export function loadPrefs(user) {
  const p = user?.prefs || fromBrowser() || {}
  return {
    cats: p.cats?.length ? p.cats : BASE_PREFS.cats,
    r: Math.max(1, Math.min(20, +p.r || BASE_PREFS.r)),
    only_open: !!p.only_open,
  }
}

let timer = null

/** Salva subito nel browser e, con l'account, nel database (dopo 600 ms senza altre modifiche). */
export function savePrefs(prefs, user, setUser) {
  try {
    localStorage.setItem(KEY, JSON.stringify(prefs))
  } catch {
    /* browser senza spazio o in incognito: restano solo per questa visita */
  }
  if (!user) return
  clearTimeout(timer)
  timer = setTimeout(() => {
    api('/me/prefs', { method: 'PUT', body: prefs })
      .then((r) => setUser?.(r.user))
      .catch(() => { /* riprova alla prossima modifica */ })
  }, 600)
}
