import { useApp } from '../App.jsx'

export function Sources() {
  const { config } = useApp()
  return (
    <div className="page narrow prose">
      <h1>Fonti dei dati</h1>
      <h2>OpenStreetMap</h2>
      <p>La fonte principale: farmacie, ospedali e pronto soccorso, uffici postali, municipi, CAF e patronati, veterinari, con gli orari (<code>opening_hours</code>) inseriti dai volontari. Stessi tag da Aosta a Lampedusa. Interrogato tramite i server pubblici Overpass, con 15 minuti di cache.</p>
      <p>Licenza ODbL — © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noreferrer">OpenStreetMap contributors</a>. Se un orario è sbagliato, puoi correggerlo direttamente su OpenStreetMap: ne beneficiano tutti.</p>
      <h2>Ministero della Salute — anagrafe farmacie</h2>
      <p>L’elenco ufficiale delle farmacie aperte al pubblico (<a href="https://www.dati.salute.gov.it/it/dataset/farmacie/" target="_blank" rel="noreferrer">dati.salute.gov.it</a>, licenza IODL 2.0), aggiornato ogni 24 ore. Lo usiamo per mostrare le farmacie che mancano su OpenStreetMap. <b>Non contiene orari né turni</b>: queste farmacie appaiono come “orario non in mappa”. Escludiamo i record con coordinate approssimative (centro del comune).</p>
      <h2>Festività</h2>
      <p>Festività nazionali italiane (Pasqua calcolata), usate per le regole <code>PH</code> degli orari. Le feste patronali locali non sono incluse.</p>
      <h2>Segnalazioni degli utenti</h2>
      <p>Chi ha un account può indicare “aperta”, “chiusa” o “di turno”. Valgono 12 ore e prevalgono sugli orari di OpenStreetMap quando sono in maggioranza.</p>
      <h2>Cosa non facciamo</h2>
      <ul>
        <li><b>Turni delle farmacie:</b> in Italia non esiste un open data nazionale dei turni. Indichiamo come “probabile turno” una farmacia aperta di notte, la domenica o nei festivi secondo i dati, o segnalata dagli utenti. Verifica sempre la bacheca o il sito dell’Ordine/Federfarma della tua provincia.</li>
        <li><b>Attese dei pronto soccorso:</b> non c’è un flusso nazionale in tempo reale. {config?.ps_live ? 'In questa installazione è configurato un flusso regionale.' : 'Le mostriamo solo dove una Regione pubblica un flusso aperto.'}</li>
        <li>Non inventiamo orari: se mancano, lo diciamo.</li>
      </ul>
      {config?.farmacie_turno_provider && <p className="alert">Questa installazione usa anche un fornitore commerciale di turni farmacia: i dati sono forniti “così come sono” e vanno verificati.</p>}
      <p className="alert"><b>In emergenza chiama il 118</b> (o il 112 dove attivo il Numero Unico). Per la guardia medica in molte regioni è attivo il <b>116117</b>.</p>
    </div>
  )
}

export function Privacy() {
  return (
    <div className="page narrow prose">
      <h1>Privacy</h1>
      <p><b>Senza account</b> non salviamo nulla che ti identifichi. La posizione del browser viene usata solo per la ricerca: le coordinate (arrotondate) passano al nostro server, che interroga OpenStreetMap/Nominatim, e non vengono memorizzate insieme a dati personali. L’indirizzo IP è conservato per al massimo 24 ore a fini anti-abuso.</p>
      <p><b>Con account</b> conserviamo: nome, email, password (cifrata), la “casa” che scegli (coordinate, nome del luogo, raggio, categorie), i preferiti, le segnalazioni (12 ore di validità, poi cancellate entro 7 giorni) e il registro degli avvisi inviati.</p>
      <p>Usiamo l’email solo per conferma, recupero password e gli avvisi che attivi tu. Nessuna pubblicità, nessuna cessione a terzi.</p>
      <p>Le mappe sono caricate dai server di OpenStreetMap, che ricevono il tuo IP come per qualsiasi sito.</p>
      <p>Puoi eliminare l’account in qualsiasi momento da <a href="#/impostazioni">Impostazioni</a>: la cancellazione è immediata e totale.</p>
    </div>
  )
}
