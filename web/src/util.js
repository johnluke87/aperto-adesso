export const CAT_ICONS = {
  farmacia: '✚',
  farmacia_turno: '☾',
  guardia_medica: '⚕',
  pronto_soccorso: 'PS',
  poste: '✉',
  comune: '⌂',
  caf: '§',
  veterinario: '🐾',
}

export const DEFAULT_CATS = ['farmacia', 'guardia_medica', 'pronto_soccorso', 'poste', 'comune', 'caf', 'veterinario']

export function status(p) {
  if (p.turno) return { key: 'turno', label: 'Di turno (probabile)' }
  if (p.open === true) return { key: 'open', label: 'Aperto' }
  if (p.open === false) return { key: 'closed', label: 'Chiuso' }
  return { key: 'unknown', label: 'Orario non in mappa' }
}

export function km(v) {
  if (v < 1) return Math.round(v * 1000) + ' m'
  return v.toFixed(1).replace('.', ',') + ' km'
}

const pad = (n) => String(n).padStart(2, '0')

export function toLocalIso(d) {
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

/** Preset reference times: "adesso", "stasera 22:00", "domani 09:00", "domenica 10:00". */
export function timePresets(now = new Date()) {
  const tonight = new Date(now); tonight.setHours(22, 0, 0, 0)
  const tomorrow = new Date(now); tomorrow.setDate(now.getDate() + 1); tomorrow.setHours(9, 0, 0, 0)
  const sunday = new Date(now); sunday.setDate(now.getDate() + ((7 - now.getDay()) % 7 || 7)); sunday.setHours(10, 0, 0, 0)
  return [
    { id: 'now', label: 'Adesso', at: '' },
    { id: 'tonight', label: 'Stasera 22:00', at: toLocalIso(tonight) },
    { id: 'tomorrow', label: 'Domani 09:00', at: toLocalIso(tomorrow) },
    { id: 'sunday', label: 'Domenica 10:00', at: toLocalIso(sunday) },
  ]
}

export function formatAt(at) {
  if (!at) return 'adesso'
  const d = new Date(at)
  return d.toLocaleString('it-IT', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' })
}

export function directions(p, from, mode = 'walk') {
  const dest = `${p.lat},${p.lng}`
  return {
    google: `https://www.google.com/maps/dir/?api=1&destination=${dest}&travelmode=${mode === 'walk' ? 'walking' : 'driving'}`,
    osm: `https://www.openstreetmap.org/directions?engine=${mode === 'walk' ? 'fossgis_osrm_foot' : 'fossgis_osrm_car'}&route=${from ? `${from.lat},${from.lng}` : ''};${dest}`,
  }
}

/** Human-readable Italian rendering of the raw OSM opening_hours. */
export function prettyHours(oh) {
  if (!oh) return null
  if (/^24\/7$/.test(oh.trim())) return ['Sempre aperto (24 ore su 24)']
  const days = { Mo: 'lun', Tu: 'mar', We: 'mer', Th: 'gio', Fr: 'ven', Sa: 'sab', Su: 'dom', PH: 'festivi' }
  return oh.split(/\s*;\s*/).filter(Boolean).map((r) =>
    r.replace(/\b(Mo|Tu|We|Th|Fr|Sa|Su|PH)\b/g, (m) => days[m])
      .replace(/\boff\b|\bclosed\b/g, 'chiuso')
      .replace(/,(?=\d)/g, ', ')
      .replace(/-(?=\d)/g, '–'))
}

export function parseHash() {
  const h = window.location.hash.replace(/^#/, '') || '/'
  const [path, qs] = h.split('?')
  return { path, query: Object.fromEntries(new URLSearchParams(qs || '')) }
}

export function go(path, query) {
  const qs = query ? '?' + new URLSearchParams(Object.entries(query).filter(([, v]) => v !== undefined && v !== null && v !== '')) : ''
  window.location.hash = path + (qs === '?' ? '' : qs)
}
