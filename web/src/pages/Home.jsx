import { useEffect, useMemo, useState } from 'react'
import { api } from '../api.js'
import { useApp } from '../App.jsx'
import { CatBadge, CatChips, LocationSearch, PhoneIcon, PlaceMap, StatusPill, TimePicker } from '../components.jsx'
import { DEFAULT_CATS, formatAt, go, km, status } from '../util.js'

export default function Home() {
  const { route } = useApp()
  const q = route.query
  if (!q.lat || !q.lng) return <Landing />
  return <Results q={q} />
}

function searchQuery(base, patch) {
  return { lat: base.lat, lng: base.lng, label: base.label, r: base.r, cats: base.cats, at: base.at, ...patch }
}

function Landing() {
  const { user, config } = useApp()
  const pick = ({ lat, lng, label }) => go('/', { lat, lng, label, r: 5, cats: DEFAULT_CATS.join(',') })
  const home = user?.lat ? () => go('/', { lat: user.lat, lng: user.lng, label: 'Casa · ' + (user.place_name || ''), r: user.radius_km, cats: user.cats.join(',') }) : null
  const today = config?.today
  return (
    <div className="landing">
      <section className="hero">
        <p className="eyebrow">{today?.holiday ? `Oggi è ${today.holiday}` : today?.sunday ? 'Oggi è domenica' : 'In tutta Italia'}</p>
        <h1>Cosa è <em>aperto adesso</em>,<br />vicino a te?</h1>
        <p className="lead">Farmacie (anche di notte e nei festivi), guardia medica, pronto soccorso, Poste, Comune, CAF e veterinari. Senza account.</p>
        <LocationSearch onPick={pick} autoFocus />
        {home && <button className="btn btn-ghost home-btn" onClick={home}>⌂ Cerca vicino a casa ({user.place_name})</button>}
        <ul className="quick">
          {[['farmacia,farmacia_turno', 'Farmacie di turno'], ['guardia_medica', 'Guardia medica'], ['pronto_soccorso', 'Pronto soccorso'], ['veterinario', 'Veterinari']].map(([c, l]) => (
            <li key={c}>
              <LocationQuick cats={c} label={l} />
            </li>
          ))}
        </ul>
      </section>
      <section className="legend-block">
        <h2>Come leggere i risultati</h2>
        <ul className="legend">
          <li><StatusPill p={{ open: true }} /> secondo gli orari pubblicati su OpenStreetMap</li>
          <li><StatusPill p={{ turno: true }} /> farmacia aperta di notte, la domenica o nei festivi: probabile turno, da verificare</li>
          <li><StatusPill p={{ open: false }} /> fuori orario</li>
          <li><StatusPill p={{ open: null }} /> non inventiamo: chiama prima di andare</li>
        </ul>
        <p className="small muted">Non esiste un elenco nazionale aperto dei turni delle farmacie: incrociamo OpenStreetMap, l’anagrafe del Ministero della Salute e le segnalazioni degli utenti (valide 12 ore). <a href="#/fonti">Scopri le fonti</a>.</p>
      </section>
    </div>
  )
}

function LocationQuick({ cats, label }) {
  const [busy, setBusy] = useState(false)
  const run = () => {
    if (!navigator.geolocation) return
    setBusy(true)
    navigator.geolocation.getCurrentPosition(
      (pos) => go('/', { lat: pos.coords.latitude.toFixed(5), lng: pos.coords.longitude.toFixed(5), label: 'Vicino a te', r: 5, cats }),
      () => { setBusy(false); alert('Posizione non disponibile: scrivi il comune nella casella.') },
      { timeout: 12000, maximumAge: 60000 })
  }
  return <button className="quick-btn" onClick={run} disabled={busy}>{busy ? 'Localizzo…' : label + ' vicino a me'}</button>
}

function Results({ q }) {
  const { favKeys, toggleFav, user, config } = useApp()
  const center = useMemo(() => ({ lat: +q.lat, lng: +q.lng }), [q.lat, q.lng])
  const r = Math.max(1, Math.min(20, +(q.r || 5)))
  const cats = q.cats ? q.cats.split(',').filter(Boolean) : DEFAULT_CATS
  const at = q.at || ''
  const [radius, setRadius] = useState(r)
  const [data, setData] = useState(null)
  const [err, setErr] = useState('')
  const [loading, setLoading] = useState(false)
  const [onlyOpen, setOnlyOpen] = useState(false)
  const [selected, setSelected] = useState(null)
  const [view, setView] = useState('list')
  const [showFilters, setShowFilters] = useState(false)

  useEffect(() => setRadius(r), [r])

  useEffect(() => {
    let alive = true
    setLoading(true); setErr(''); setSelected(null)
    api('/places', { query: { lat: q.lat, lng: q.lng, r, cats: cats.join(','), at } })
      .then((d) => { if (alive) setData(d) })
      .catch((e) => { if (alive) setErr(e.message) })
      .finally(() => { if (alive) setLoading(false) })
    return () => { alive = false }
  }, [q.lat, q.lng, r, q.cats, at])

  const update = (patch) => go('/', searchQuery({ ...q, r, cats: cats.join(','), at }, patch))
  const places = (data?.places ?? []).filter((p) => !onlyOpen || p.open === true)
  const counts = (data?.places ?? []).reduce((a, p) => { a[status(p).key]++; return a }, { open: 0, turno: 0, closed: 0, unknown: 0 })
  const showPs = cats.includes('pronto_soccorso')

  return (
    <div className="results">
      <div className="toolbar">
        <div className="where">
          <span className="where-label">📍 {q.label || 'Posizione scelta'}</span>
          <span className="muted small"> · entro {r} km · {formatAt(at)}</span>
          {data?.day?.holiday && <span className="tag-holiday">Festivo: {data.day.holiday}</span>}
        </div>
        <button className="btn btn-ghost btn-sm" onClick={() => setShowFilters(!showFilters)} aria-expanded={showFilters}>
          {showFilters ? 'Chiudi filtri' : 'Cambia ricerca'}
        </button>
      </div>
      {showFilters && (
        <div className="filters">
          <LocationSearch compact onPick={({ lat, lng, label }) => update({ lat, lng, label })} />
          <div className="filter-row">
            <label className="radius">Raggio <strong>{radius} km</strong>
              <input type="range" min="1" max="20" value={radius} onChange={(e) => setRadius(+e.target.value)}
                onMouseUp={() => update({ r: radius })} onTouchEnd={() => update({ r: radius })} onKeyUp={() => update({ r: radius })} />
            </label>
          </div>
          <TimePicker at={at} onChange={(v) => update({ at: v })} />
          <CatChips cats={cats} onChange={(c) => c.length && update({ cats: c.join(',') })} />
        </div>
      )}

      {err && <div className="alert alert-err">{err}</div>}
      {data && !data.osm_ok && <div className="alert">OpenStreetMap non risponde in questo momento: mostriamo solo l’anagrafe farmacie del Ministero e i dati in cache. Riprova tra poco.</div>}

      <div className="summary">
        {loading ? <span className="loading">Cerco nei dati OpenStreetMap… può richiedere qualche secondo</span> : data && (
          <>
            <span><b>{counts.open + counts.turno}</b> aperti</span>
            {counts.turno > 0 && <span className="c-turno"><b>{counts.turno}</b> di turno</span>}
            <span><b>{counts.closed}</b> chiusi</span>
            <span className="muted"><b>{counts.unknown}</b> senza orario</span>
            <label className="only-open"><input type="checkbox" checked={onlyOpen} onChange={(e) => setOnlyOpen(e.target.checked)} /> Solo aperti</label>
          </>
        )}
        <div className="view-toggle" role="tablist">
          <button role="tab" aria-selected={view === 'list'} className={view === 'list' ? 'on' : ''} onClick={() => setView('list')}>Lista</button>
          <button role="tab" aria-selected={view === 'map'} className={view === 'map' ? 'on' : ''} onClick={() => setView('map')}>Mappa</button>
        </div>
      </div>

      {showPs && data && (
        data.ps_wait?.length ? (
          <div className="ps-wait">
            <h3>Attese in pronto soccorso</h3>
            <ul>{data.ps_wait.map((w, i) => <li key={i}><b>{w.ospedale}</b> · {w.in_attesa ?? '?'} in attesa {w.codice && `(${w.codice})`} <span className="muted small">{w.fonte} {w.aggiornato}</span></li>)}</ul>
          </div>
        ) : <p className="small muted ps-none">Attese live dei pronto soccorso non pubblicate per questa zona.</p>
      )}

      <div className={'split view-' + view}>
        <ol className="plist" aria-busy={loading}>
          {!loading && data && places.length === 0 && (
            <li className="empty">Nessun risultato{onlyOpen ? ' aperto' : ''} entro {r} km. Prova ad allargare il raggio.</li>
          )}
          {places.map((p) => (
            <li key={p.key} className={'pcard' + (selected === p.key ? ' sel' : '')} onMouseEnter={() => setSelected(p.key)}>
              <div className="pcard-main">
                <a className="pname" href={`#/luogo/${encodeURIComponent(p.key)}?lat=${q.lat}&lng=${q.lng}${at ? '&at=' + at : ''}`}>{p.name}</a>
                <div className="pmeta">
                  <CatBadge cat={p.category} />
                  <StatusPill p={p} />
                  <span className="dist">{km(p.km)}</span>
                </div>
                {p.address && <div className="addr small muted">{p.address}</div>}
                {p.turno && <div className="small turno-note">{p.reports?.di_turno ? `${p.reports.di_turno} segnalazion${p.reports.di_turno > 1 ? 'i' : 'e'}: di turno` : config?.turno_disclaimer}</div>}
              </div>
              <div className="pcard-actions">
                {p.phone && <a className="icon-btn" href={'tel:' + p.phone.replace(/[^\d+]/g, '')} aria-label={'Chiama ' + p.name}><PhoneIcon /></a>}
                <button className={'icon-btn star' + (favKeys.has(p.key) ? ' on' : '')} onClick={() => toggleFav(p)}
                  aria-label={favKeys.has(p.key) ? 'Togli dai preferiti' : 'Aggiungi ai preferiti'} title={user ? '' : 'Entra per salvare i preferiti'}>
                  {favKeys.has(p.key) ? '★' : '☆'}
                </button>
              </div>
            </li>
          ))}
        </ol>
        <div className="mapwrap">
          <PlaceMap center={center} radiusKm={r} places={places} selected={selected} onSelect={(k) => {
            setSelected(k)
            setTimeout(() => document.querySelector('.pcard.sel')?.scrollIntoView({ block: 'nearest', behavior: 'smooth' }), 30)
          }} />
        </div>
      </div>
    </div>
  )
}
