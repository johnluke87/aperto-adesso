import { useEffect, useRef, useState } from 'react'
import L from 'leaflet'
import { api } from './api.js'
import { CAT_ICONS, km, status, timePresets, toLocalIso } from './util.js'
import { useApp } from './App.jsx'

export function StatusPill({ p }) {
  const s = status(p)
  return <span className={'pill pill-' + s.key}><span className="dot" aria-hidden="true" />{s.label}</span>
}

export function CatBadge({ cat }) {
  const { config } = useApp()
  return (
    <span className={'cat cat-' + cat}>
      <span className="cat-ico" aria-hidden="true">{CAT_ICONS[cat]}</span>
      {config?.categories?.[cat] ?? cat}
    </span>
  )
}

/** Comune/indirizzo search with Nominatim suggestions, plus "use my position". */
export function LocationSearch({ onPick, autoFocus, compact }) {
  const [q, setQ] = useState('')
  const [results, setResults] = useState([])
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const timer = useRef()

  useEffect(() => {
    clearTimeout(timer.current)
    if (q.trim().length < 3) { setResults([]); return }
    timer.current = setTimeout(async () => {
      try {
        const r = await api('/geocode', { query: { q } })
        setResults(r.results)
        setErr(r.results.length ? '' : 'Nessun risultato in Italia.')
      } catch (e) { setErr(e.message) }
    }, 550)
    return () => clearTimeout(timer.current)
  }, [q])

  const useGps = () => {
    if (!navigator.geolocation) { setErr('Il browser non supporta la geolocalizzazione.'); return }
    setBusy(true); setErr('')
    navigator.geolocation.getCurrentPosition(async (pos) => {
      const lat = +pos.coords.latitude.toFixed(5)
      const lng = +pos.coords.longitude.toFixed(5)
      let label = 'La tua posizione'
      try {
        const r = await api('/geocode', { query: { lat, lng } })
        if (r.reverse && !r.reverse.italy) { setErr('Sembri fuori dall’Italia: cerca un comune italiano.'); setBusy(false); return }
        if (r.reverse?.comune) label = 'Vicino a te · ' + r.reverse.comune
      } catch { /* label stays generic */ }
      setBusy(false)
      onPick({ lat, lng, label })
    }, (e) => {
      setBusy(false)
      setErr(e.code === 1 ? 'Posizione negata: scrivi il comune qui sopra.' : 'Posizione non disponibile, scrivi il comune.')
    }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 60000 })
  }

  const pick = (r) => {
    setQ(''); setResults([])
    onPick({ lat: +r.lat.toFixed(5), lng: +r.lng.toFixed(5), label: shortLabel(r) })
  }

  return (
    <div className={'locsearch' + (compact ? ' compact' : '')}>
      <div className="locsearch-row">
        <input
          type="search" value={q} autoFocus={autoFocus} placeholder="Comune o indirizzo (es. Bari, via Roma 1 Torino)"
          aria-label="Comune o indirizzo" onChange={(e) => setQ(e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Enter' && results[0]) pick(results[0]) }}
        />
        <button type="button" className="btn btn-gps" onClick={useGps} disabled={busy}>
          {busy ? 'Localizzo…' : <><span aria-hidden="true">◎</span> Usa la mia posizione</>}
        </button>
      </div>
      {results.length > 0 && (
        <ul className="suggest" role="listbox">
          {results.map((r, i) => (
            <li key={i}><button type="button" onClick={() => pick(r)}>{r.label}</button></li>
          ))}
        </ul>
      )}
      {err && <p className="err small">{err}</p>}
    </div>
  )
}

function shortLabel(r) {
  const parts = r.label.split(',').map((s) => s.trim())
  if (r.comune && parts[0] !== r.comune) return parts[0] + ', ' + r.comune
  return parts.slice(0, 2).join(', ')
}

export function TimePicker({ at, onChange }) {
  const presets = timePresets()
  const active = presets.find((p) => p.at === (at || ''))
  const [custom, setCustom] = useState(!active && !!at)
  return (
    <div className="timepick" role="group" aria-label="Ora di riferimento">
      {presets.map((p) => (
        <button key={p.id} type="button" className={'chip' + (!custom && active?.id === p.id ? ' on' : '')}
          onClick={() => { setCustom(false); onChange(p.at) }}>{p.label}</button>
      ))}
      <button type="button" className={'chip' + (custom ? ' on' : '')} onClick={() => setCustom(true)}>Altro…</button>
      {custom && (
        <input type="datetime-local" value={at || toLocalIso(new Date())} aria-label="Data e ora"
          onChange={(e) => onChange(e.target.value)} />
      )}
    </div>
  )
}

export function CatChips({ cats, onChange }) {
  const { config } = useApp()
  const all = Object.keys(config?.categories ?? CAT_ICONS)
  const toggle = (c) => onChange(cats.includes(c) ? cats.filter((x) => x !== c) : [...cats, c])
  return (
    <div className="catchips" role="group" aria-label="Categorie">
      {all.map((c) => (
        <button key={c} type="button" className={'chip chip-cat' + (cats.includes(c) ? ' on' : '')} aria-pressed={cats.includes(c)} onClick={() => toggle(c)}>
          <span aria-hidden="true">{CAT_ICONS[c]}</span> {config?.categories?.[c] ?? c}
        </button>
      ))}
    </div>
  )
}

const COLORS = { open: '#16a34a', turno: '#7c3aed', closed: '#dc2626', unknown: '#8a8f98' }

/** Leaflet map with one circle marker per place; selected place is enlarged. */
export function PlaceMap({ center, radiusKm, places, selected, onSelect }) {
  const el = useRef()
  const map = useRef()
  const layer = useRef()

  useEffect(() => {
    map.current = L.map(el.current, { zoomControl: true, attributionControl: true })
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(map.current)
    layer.current = L.layerGroup().addTo(map.current)
    // The map may start hidden (mobile list view): recompute its size whenever it becomes visible.
    let wasHidden = el.current.offsetWidth === 0
    const ro = new ResizeObserver(() => {
      const hidden = el.current.offsetWidth === 0
      if (hidden) { wasHidden = true; return }
      map.current.invalidateSize()
      if (wasHidden && fitRef.current) fitRef.current()
      wasHidden = false
    })
    ro.observe(el.current)
    return () => { ro.disconnect(); map.current.remove() }
  }, [])

  const fitRef = useRef()
  fitRef.current = center ? () => map.current.fitBounds(L.latLng(center.lat, center.lng).toBounds(radiusKm * 2000), { padding: [10, 10] }) : null

  useEffect(() => {
    const m = map.current
    layer.current.clearLayers()
    if (center) {
      L.circle([center.lat, center.lng], { radius: radiusKm * 1000, color: '#0f6b4f', weight: 1, fillOpacity: 0.04, interactive: false }).addTo(layer.current)
      L.circleMarker([center.lat, center.lng], { radius: 7, color: '#fff', weight: 3, fillColor: '#2563eb', fillOpacity: 1 })
        .bindTooltip('Punto di ricerca').addTo(layer.current)
    }
    places.forEach((p) => {
      const s = status(p).key
      const isSel = selected === p.key
      L.circleMarker([p.lat, p.lng], {
        radius: isSel ? 11 : 7, color: isSel ? '#111' : '#fff', weight: 2, fillColor: COLORS[s], fillOpacity: 0.95,
      })
        .bindTooltip(`${p.name} · ${km(p.km)}`)
        .on('click', () => onSelect(p.key))
        .addTo(layer.current)
    })
    if (center && !selected) {
      m.fitBounds(L.latLng(center.lat, center.lng).toBounds(radiusKm * 2000), { padding: [10, 10] })
    } else if (!center && places[0]) {
      m.setView([places[0].lat, places[0].lng], 16)
    }
  }, [center?.lat, center?.lng, radiusKm, places, selected])

  useEffect(() => {
    const p = places.find((x) => x.key === selected)
    if (p) map.current.panTo([p.lat, p.lng])
  }, [selected])

  return <div ref={el} className="map" role="region" aria-label="Mappa dei risultati" />
}

export function PhoneIcon() {
  return (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z" />
    </svg>
  )
}
