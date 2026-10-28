import { useEffect, useState } from 'react'
import { api, setToken } from '../api.js'
import { useApp } from '../App.jsx'
import { CatBadge, CatChips, LocationSearch, PhoneIcon } from '../components.jsx'
import { go } from '../util.js'

function NeedLogin({ children }) {
  const { user } = useApp()
  if (!user) {
    return <div className="page narrow"><p>Per questa pagina devi <a href="#/entra">entrare</a> o <a href="#/registrati">registrarti</a>.</p></div>
  }
  return children
}

export function Favorites() {
  return <NeedLogin><FavoritesInner /></NeedLogin>
}

function FavoritesInner() {
  const { favKeys, toggleFav, user } = useApp()
  const [list, setList] = useState(null)
  useEffect(() => { api('/favorites').then((r) => setList(r.favorites)) }, [favKeys])
  const from = user.lat ? `?lat=${user.lat}&lng=${user.lng}` : ''
  return (
    <div className="page narrow">
      <h1>Preferiti</h1>
      {list === null ? <p className="loading">Carico…</p> : list.length === 0 ? (
        <p className="muted">Nessun preferito. Tocca ☆ accanto a un luogo nei risultati.</p>
      ) : (
        <ul className="plist plain">
          {list.map((f) => (
            <li key={f.key} className="pcard">
              <div className="pcard-main">
                <a className="pname" href={`#/luogo/${encodeURIComponent(f.key)}${from}`}>{f.snapshot?.name || f.key}</a>
                <div className="pmeta">{f.snapshot?.category && <CatBadge cat={f.snapshot.category} />}<span className="small muted">apri la scheda per lo stato attuale</span></div>
                {f.snapshot?.address && <div className="small muted">{f.snapshot.address}</div>}
              </div>
              <div className="pcard-actions">
                {f.snapshot?.phone && <a className="icon-btn" href={'tel:' + f.snapshot.phone.replace(/[^\d+]/g, '')} aria-label="Chiama"><PhoneIcon /></a>}
                <button className="icon-btn star on" onClick={() => toggleFav({ key: f.key })} aria-label="Togli dai preferiti">★</button>
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

export function Alerts() {
  return <NeedLogin><AlertsInner /></NeedLogin>
}

function AlertsInner() {
  const { user, setUser } = useApp()
  const [err, setErr] = useState('')
  const toggle = async () => {
    setErr('')
    try {
      const r = await api('/me/alerts', { method: 'PUT', body: { alert_on: !user.alert_on } })
      setUser(r.user)
    } catch (e) { setErr(e.message) }
  }
  return (
    <div className="page narrow">
      <h1>Avvisi email</h1>
      <p>La domenica, nei festivi e dopo le 20, se secondo i dati c’è almeno una farmacia aperta entro {user.radius_km} km dalla tua casa{user.place_name ? ` (${user.place_name})` : ''}, ti mandiamo le 5 più vicine. Al massimo un’email al giorno.</p>
      {!user.lat && <p className="alert">Prima imposta la tua <a href="#/impostazioni">casa</a>.</p>}
      {!user.email_verified && <p className="alert">Conferma la tua email per ricevere gli avvisi.</p>}
      <label className="switch">
        <input type="checkbox" checked={user.alert_on} onChange={toggle} disabled={!user.lat} />
        <span>{user.alert_on ? 'Avviso attivo' : 'Avviso disattivato'}</span>
      </label>
      {err && <p className="err">{err}</p>}
      <p className="small muted">I dati sui turni non sono ufficiali: l’avviso indica farmacie aperte secondo gli orari su OpenStreetMap e le segnalazioni degli utenti.</p>
    </div>
  )
}

export function Settings() {
  return <NeedLogin><SettingsInner /></NeedLogin>
}

function SettingsInner() {
  const { user, setUser, logout } = useApp()
  const [home, setHome] = useState(user.lat ? { lat: user.lat, lng: user.lng, label: user.place_name } : null)
  const [radius, setRadius] = useState(user.radius_km)
  const [cats, setCats] = useState(user.cats)
  const [msg, setMsg] = useState('')
  const [name, setName] = useState(user.name)
  const [pw, setPw] = useState({ current: '', password: '' })
  const [pwMsg, setPwMsg] = useState('')

  const saveHome = async () => {
    setMsg('')
    try {
      const r = await api('/me/home', { method: 'PUT', body: { lat: home.lat, lng: home.lng, place_name: home.label, radius_km: radius, cats } })
      setUser(r.user)
      setMsg('Casa salvata.')
    } catch (e) { setMsg(e.message) }
  }
  const saveName = async (e) => {
    e.preventDefault()
    const r = await api('/me', { method: 'PUT', body: { name } })
    setUser(r.user)
  }
  const changePw = async (e) => {
    e.preventDefault()
    setPwMsg('')
    try {
      await api('/me/password', { method: 'POST', body: pw })
      setPw({ current: '', password: '' })
      setPwMsg('Password aggiornata.')
    } catch (x) { setPwMsg(x.message) }
  }
  const del = async () => {
    if (!window.confirm('Eliminare definitivamente account, preferiti e segnalazioni?')) return
    await api('/me', { method: 'DELETE' })
    setToken(null)
    await logout()
  }

  return (
    <div className="page narrow">
      <h1>Impostazioni</h1>
      <section className="card">
        <h2>La tua “casa”</h2>
        <p className="small muted">Comune o indirizzo da cui cercare con un tocco e per gli avvisi.</p>
        {home && <p>📍 <b>{home.label}</b></p>}
        <LocationSearch compact onPick={(h) => setHome({ ...h, label: h.label.replace(/^Vicino a te · /, '') })} />
        <label className="radius">Raggio <strong>{radius} km</strong>
          <input type="range" min="1" max="20" value={radius} onChange={(e) => setRadius(+e.target.value)} />
        </label>
        <p className="small">Categorie preferite</p>
        <CatChips cats={cats} onChange={(c) => c.length && setCats(c)} />
        <div className="actions">
          <button className="btn" disabled={!home} onClick={saveHome}>Salva casa</button>
          {user.lat && <button className="btn btn-ghost" onClick={() => go('/', { lat: user.lat, lng: user.lng, label: 'Casa · ' + user.place_name, r: user.radius_km, cats: user.cats.join(',') })}>Cerca vicino a casa</button>}
        </div>
        {msg && <p className="small">{msg}</p>}
      </section>

      <section className="card">
        <h2>Profilo</h2>
        <form className="form inline" onSubmit={saveName}>
          <label>Nome<input value={name} onChange={(e) => setName(e.target.value)} maxLength={80} /></label>
          <button className="btn btn-ghost">Salva</button>
        </form>
        <p className="small muted">Email: {user.email} {user.email_verified ? '(confermata)' : '(da confermare)'}</p>
        <form className="form" onSubmit={changePw}>
          <label>Password attuale<input type="password" value={pw.current} onChange={(e) => setPw({ ...pw, current: e.target.value })} autoComplete="current-password" required /></label>
          <label>Nuova password<input type="password" minLength={8} value={pw.password} onChange={(e) => setPw({ ...pw, password: e.target.value })} autoComplete="new-password" required /></label>
          <button className="btn btn-ghost">Cambia password</button>
          {pwMsg && <p className="small">{pwMsg}</p>}
        </form>
      </section>

      <section className="card danger">
        <h2>Elimina account</h2>
        <p className="small">Cancella subito account, casa, preferiti, segnalazioni e avvisi.</p>
        <button className="btn btn-danger" onClick={del}>Elimina il mio account</button>
      </section>
    </div>
  )
}
