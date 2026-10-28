const BASE = import.meta.env.BASE_URL + 'api'

export function getToken() {
  try { return localStorage.getItem('aa_token') } catch { return null }
}

export function setToken(t) {
  try {
    if (t) localStorage.setItem('aa_token', t)
    else localStorage.removeItem('aa_token')
  } catch { /* private mode */ }
}

export async function api(path, { method = 'GET', body, query } = {}) {
  let url = BASE + path
  if (query) {
    const q = new URLSearchParams(Object.entries(query).filter(([, v]) => v !== undefined && v !== null && v !== ''))
    url += '?' + q
  }
  const headers = { 'Content-Type': 'application/json' }
  const tok = getToken()
  if (tok) headers.Authorization = 'Bearer ' + tok
  let res
  try {
    res = await fetch(url, { method, headers, body: body ? JSON.stringify(body) : undefined })
  } catch {
    throw new Error('Connessione non riuscita. Riprova.')
  }
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const err = new Error(data.error || 'Errore ' + res.status)
    err.status = res.status
    throw err
  }
  return data
}
