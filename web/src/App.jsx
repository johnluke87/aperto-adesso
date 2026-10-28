import { createContext, useCallback, useContext, useEffect, useState } from 'react'
import { api, getToken, setToken } from './api.js'
import { parseHash } from './util.js'
import Home from './pages/Home.jsx'
import PlacePage from './pages/PlacePage.jsx'
import { Login, Register, Forgot, Reset, Verify } from './pages/Auth.jsx'
import { Favorites, Alerts, Settings } from './pages/Account.jsx'
import { Sources, Privacy } from './pages/Static.jsx'

const Ctx = createContext(null)
export const useApp = () => useContext(Ctx)

function useHashRoute() {
  const [route, setRoute] = useState(parseHash)
  useEffect(() => {
    const on = () => { setRoute(parseHash()); window.scrollTo(0, 0) }
    window.addEventListener('hashchange', on)
    return () => window.removeEventListener('hashchange', on)
  }, [])
  return route
}

export default function App() {
  const route = useHashRoute()
  const [config, setConfig] = useState(null)
  const [user, setUser] = useState(null)
  const [favKeys, setFavKeys] = useState(new Set())
  const [menu, setMenu] = useState(false)

  useEffect(() => { api('/config').then(setConfig).catch(() => {}) }, [])

  const refreshUser = useCallback(async () => {
    if (!getToken()) { setUser(null); setFavKeys(new Set()); return }
    try {
      const [{ user }, { favorites }] = await Promise.all([api('/me'), api('/favorites')])
      setUser(user)
      setFavKeys(new Set(favorites.map((f) => f.key)))
    } catch (e) {
      if (e.status === 401) { setToken(null); setUser(null) }
    }
  }, [])

  useEffect(() => { refreshUser() }, [refreshUser])
  useEffect(() => setMenu(false), [route.path])

  const login = (token, u) => { setToken(token); setUser(u); refreshUser() }
  const logout = async () => {
    try { await api('/auth/logout', { method: 'POST' }) } catch { /* already gone */ }
    setToken(null); setUser(null); setFavKeys(new Set())
    window.location.hash = '/'
  }

  const toggleFav = async (p) => {
    if (!user) { window.location.hash = '/entra'; return }
    const has = favKeys.has(p.key)
    const next = new Set(favKeys)
    if (has) {
      next.delete(p.key)
      setFavKeys(next)
      await api('/favorites/' + encodeURIComponent(p.key), { method: 'DELETE' })
    } else {
      next.add(p.key)
      setFavKeys(next)
      const { name, category, address, phone, lat, lng, opening_hours } = p
      await api('/favorites', { method: 'POST', body: { key: p.key, snapshot: { name, category, address, phone, lat, lng, opening_hours } } })
    }
  }

  const ctx = { config, user, setUser, login, logout, refreshUser, favKeys, toggleFav, route }
  const { path } = route
  let page
  if (path === '/' || path === '') page = <Home />
  else if (path.startsWith('/luogo/')) page = <PlacePage placeKey={decodeURIComponent(path.slice(7))} />
  else if (path === '/entra') page = <Login />
  else if (path === '/registrati') page = <Register />
  else if (path === '/password-dimenticata') page = <Forgot />
  else if (path.startsWith('/reset/')) page = <Reset token={path.slice(7)} />
  else if (path.startsWith('/verifica/')) page = <Verify token={path.slice(10)} />
  else if (path === '/preferiti') page = <Favorites />
  else if (path === '/avvisi') page = <Alerts />
  else if (path === '/impostazioni') page = <Settings />
  else if (path === '/fonti') page = <Sources />
  else if (path === '/privacy') page = <Privacy />
  else page = <div className="page narrow"><h1>Pagina non trovata</h1><p><a href="#/">Torna alla ricerca</a></p></div>

  return (
    <Ctx.Provider value={ctx}>
      <div className="emergency" role="note">
        <strong>In emergenza chiama il 118.</strong> Orari soggetti a variazione: controlla la bacheca.
      </div>
      <header className="topbar">
        <a href="#/" className="brand"><span className="brand-dot" aria-hidden="true" />Aperto Adesso</a>
        <button className="menu-btn" aria-expanded={menu} aria-label="Menu" onClick={() => setMenu(!menu)}>☰</button>
        <nav className={menu ? 'nav open' : 'nav'}>
          <a href="#/">Cerca</a>
          {user ? (
            <>
              <a href="#/preferiti">Preferiti</a>
              <a href="#/avvisi">Avvisi</a>
              <a href="#/impostazioni">Impostazioni</a>
              <button className="linklike" onClick={logout}>Esci</button>
            </>
          ) : (
            <>
              <a href="#/entra">Entra</a>
              <a href="#/registrati" className="nav-cta">Registrati</a>
            </>
          )}
        </nav>
      </header>
      {user && !user.email_verified && <VerifyBanner />}
      <main>{page}</main>
      <footer className="footer">
        <span>Dati © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noreferrer">OpenStreetMap contributors</a> (ODbL) · Anagrafe farmacie Ministero della Salute (IODL 2.0)</span>
        <span><a href="#/fonti">Fonti</a> · <a href="#/privacy">Privacy</a>{config?.donate_url && <> · <a href={config.donate_url} target="_blank" rel="noreferrer">Offrimi un caffè</a></>}</span>
      </footer>
    </Ctx.Provider>
  )
}

function VerifyBanner() {
  const [msg, setMsg] = useState('')
  const resend = async () => {
    try {
      const r = await api('/auth/resend', { method: 'POST' })
      setMsg(r.dev_verify_url ? 'Inviata (sviluppo): ' + r.dev_verify_url : 'Email inviata, controlla la posta.')
    } catch (e) { setMsg(e.message) }
  }
  return (
    <div className="verify-banner">
      Conferma la tua email per attivare gli avvisi. <button className="linklike" onClick={resend}>Invia di nuovo</button>
      {msg && <span className="small"> {msg}</span>}
    </div>
  )
}
