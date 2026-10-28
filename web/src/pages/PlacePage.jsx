import { useEffect, useState } from 'react'
import { api } from '../api.js'
import { useApp } from '../App.jsx'
import { CatBadge, PhoneIcon, PlaceMap, StatusPill } from '../components.jsx'
import { directions, formatAt, km, prettyHours } from '../util.js'

const KIND_LABEL = { aperto: 'aperta ora', chiuso: 'chiusa ora', di_turno: 'di turno' }

export default function PlacePage({ placeKey }) {
  const { route, user, favKeys, toggleFav, config } = useApp()
  const { lat, lng, at } = route.query
  const [data, setData] = useState(null)
  const [err, setErr] = useState('')
  const [reload, setReload] = useState(0)

  useEffect(() => {
    setErr('')
    api('/places/' + encodeURIComponent(placeKey), { query: { lat, lng, at } })
      .then(setData).catch((e) => setErr(e.message))
  }, [placeKey, lat, lng, at, reload])

  if (err) return <div className="page narrow"><p className="alert alert-err">{err}</p><a href="#/" onClick={back}>← Torna ai risultati</a></div>
  if (!data) return <div className="page narrow"><p className="loading">Carico la scheda…</p></div>

  const p = data.place
  const from = lat && lng ? { lat: +lat, lng: +lng } : null
  const walk = directions(p, from, 'walk')
  const car = directions(p, from, 'car')
  const hours = prettyHours(p.opening_hours)
  const rep = p.reports || {}

  return (
    <div className="page place">
      <a href="#/" className="back" onClick={back}>← Risultati</a>
      <div className="place-grid">
        <div>
          <h1>{p.name}</h1>
          <div className="pmeta">
            <CatBadge cat={p.category} />
            <StatusPill p={p} />
            {from && <span className="dist">{km(p.km)}</span>}
          </div>
          <p className="small muted">Stato calcolato per {formatAt(at)}{data.day.holiday ? ` (festivo: ${data.day.holiday})` : ''}.</p>
          {p.turno && <p className="turno-box">{config?.turno_disclaimer}</p>}

          <dl className="facts">
            {p.address && <><dt>Indirizzo</dt><dd>{p.address}</dd></>}
            {p.phone && <><dt>Telefono</dt><dd><a href={'tel:' + p.phone.replace(/[^\d+]/g, '')}>{p.phone}</a></dd></>}
            {p.website && <><dt>Sito</dt><dd><a href={p.website} target="_blank" rel="noreferrer">{p.website.replace(/^https?:\/\/(www\.)?/, '').replace(/\/$/, '')}</a></dd></>}
            {p.operator && <><dt>Gestore</dt><dd>{p.operator}</dd></>}
            <dt>Orari</dt>
            <dd>
              {hours ? <ul className="hours">{hours.map((h, i) => <li key={i}>{h}</li>)}</ul> : <span className="muted">Non presenti in OpenStreetMap. Chiama prima di andare.</span>}
              {p.opening_hours && <code className="raw">{p.opening_hours}</code>}
            </dd>
            <dt>Fonte</dt>
            <dd>
              {p.source === 'openstreetmap'
                ? <>OpenStreetMap · <a href={p.osm} target="_blank" rel="noreferrer">vedi o correggi su OSM</a></>
                : <>Anagrafe farmacie, Ministero della Salute (senza orari)</>}
            </dd>
          </dl>

          <div className="actions">
            {p.phone && <a className="btn" href={'tel:' + p.phone.replace(/[^\d+]/g, '')}><PhoneIcon /> Chiama</a>}
            <a className="btn btn-ghost" href={walk.google} target="_blank" rel="noreferrer">🚶 A piedi</a>
            <a className="btn btn-ghost" href={car.google} target="_blank" rel="noreferrer">🚗 In auto</a>
            <a className="btn btn-ghost" href={walk.osm} target="_blank" rel="noreferrer">Percorso su OSM</a>
            <button className={'btn btn-ghost' + (favKeys.has(p.key) ? ' on' : '')} onClick={() => toggleFav(p)}>
              {favKeys.has(p.key) ? '★ Nei preferiti' : '☆ Preferito'}
            </button>
          </div>

          <section className="reports">
            <h2>Segnalazioni (ultime 12 ore)</h2>
            {Object.keys(rep).length ? (
              <p>{Object.entries(rep).map(([k, n]) => `${n} ${n > 1 ? 'persone' : 'persona'}: ${KIND_LABEL[k]}`).join(' · ')}</p>
            ) : <p className="muted small">Nessuna segnalazione recente.</p>}
            {data.recent_reports?.filter((r) => r.note).map((r, i) => (
              <blockquote key={i} className="small">“{r.note}” <span className="muted">— {KIND_LABEL[r.kind]}, {new Date(r.created_at.replace(' ', 'T')).toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' })}</span></blockquote>
            ))}
            {user ? <ReportForm placeKey={p.key} category={p.category} onDone={() => setReload((n) => n + 1)} />
              : <p className="small"><a href="#/entra">Entra</a> per segnalare se è aperto, chiuso o di turno.</p>}
          </section>
        </div>
        <div className="place-map">
          <PlaceMap center={from} radiusKm={Math.max(0.3, Math.min(20, p.km || 0.5))} places={[p]} selected={null} onSelect={() => {}} />
        </div>
      </div>
    </div>
  )
}

function back(e) {
  if (window.history.length > 1) { e.preventDefault(); window.history.back() }
}

function ReportForm({ placeKey, category, onDone }) {
  const [kind, setKind] = useState('aperto')
  const [note, setNote] = useState('')
  const [msg, setMsg] = useState('')
  const [busy, setBusy] = useState(false)
  const kinds = category === 'farmacia' ? ['aperto', 'chiuso', 'di_turno'] : ['aperto', 'chiuso']
  const send = async (e) => {
    e.preventDefault()
    setBusy(true); setMsg('')
    try {
      await api('/reports', { method: 'POST', body: { place_key: placeKey, kind, note } })
      setMsg('Grazie! La segnalazione resta visibile 12 ore.')
      setNote('')
      onDone()
    } catch (e) { setMsg(e.message) }
    setBusy(false)
  }
  return (
    <form className="report-form" onSubmit={send}>
      <fieldset>
        <legend>Segnala ora</legend>
        {kinds.map((k) => (
          <label key={k} className="radio"><input type="radio" name="kind" value={k} checked={kind === k} onChange={() => setKind(k)} /> {KIND_LABEL[k]}</label>
        ))}
      </fieldset>
      <input type="text" maxLength={200} placeholder="Nota (facoltativa), es. “cartello turno fino alle 22”" value={note} onChange={(e) => setNote(e.target.value)} />
      <button className="btn" disabled={busy}>Invia segnalazione</button>
      {msg && <p className="small">{msg}</p>}
    </form>
  )
}
