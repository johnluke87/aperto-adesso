# Aperto Adesso — specifica esaustiva

Stack: React (Vite) + PHP 8, MySQL, XAMPP. URL: `http://localhost/aperto-adesso/`

Timezone: `Europe/Rome`. Copertura: **tutti i comuni d’Italia** (OSM + Nominatim `countrycodes=it`).

---

## 1. Senza account (uso principale)

1. Landing: “Usa la mia posizione” (geolocation browser) **oppure** cerca comune/indirizzo.
2. Geocoding: Nominatim, solo IT, User-Agent `ApertoAdesso/1.0`.
3. Raggio default 5 km, slider 1–20 km.
4. Ora di riferimento: adesso, oppure “stasera 22:00”, “domani 09:00”, “domenica”.
5. Filtri categoria (multi):
   - farmacia
   - farmacia_turno (euristica, vedi §4)
   - guardia_medica
   - pronto_soccorso
   - poste
   - comune
   - caf
   - veterinario
6. Lista ordinata per distanza, con pallino: aperto / chiuso / sconosciuto / di turno (euristica).
7. Mappa Leaflet (OSM tiles).
8. Scheda: nome, indirizzo, telefono (`tel:`), sito, orari grezzi OSM, fonte, “Segnala”, indicazione a piedi/auto (link OSM/Google).
9. Banner: “In emergenza chiama 118. Orari soggetti a variazione: controlla la bacheca.”

---

## 2. Registrazione (opzionale, tutti i passaggi)

Uguale a Scadenze Italia come meccanica:

1. Registrati: nome, email, password ≥ 8, privacy.
2. Email di verifica (`api/data/outbox/` in locale).
3. Login, forgot, reset, logout, delete account.
4. Onboarding: salva “casa” (comune + raggio + categorie preferite).
5. Preferiti (luoghi OSM id).
6. Segnalazioni (vedi §5).
7. Avviso email: “se nel raggio di casa, alla domenica / dopo le 20, c’è almeno una farmacia aperta secondo i dati, mandami gli N più vicini” (cron). Richiede email verificata.

Rate limit come Scadenze. Token Bearer 30 giorni.

---

## 3. Fonti dati — tutta Italia

### 3.1 OpenStreetMap / Overpass (principale)
Query `nwr(around:R,lat,lng)` in parallelo su:
- `https://overpass.openstreetmap.fr/api/interpreter`
- `https://overpass-api.de/api/interpreter`
- `https://overpass.kumi.systems/api/interpreter`
- `https://overpass.private.coffee/api/interpreter`

Vince la prima risposta valida. Timeout ~28 s. Cache disco 15 min per cella `round(lat,3),round(lng,3),raggio,categorie`.

| Categoria app | Tag OSM / euristica nome |
|---|---|
| Farmacia | `amenity=pharmacy` |
| Pronto soccorso | `amenity=hospital` + `emergency=yes` **oppure** `emergency=emergency_ward_entrance` **oppure** nome ~ “pronto soccorso” |
| Guardia medica | nome ~ “guardia medica”, “continuità assistenziale”, “ex guardia”; `healthcare:speciality` emergency / `amenity=clinic` con nome |
| Poste | `amenity=post_office` (esclusi solo locker se taggati `parcel_locker`) |
| Comune | `amenity=townhall` ; anche `office=government` + nome ~ “Comune\|Municipio” |
| CAF | `name` ~ CAF, patronato, INAS, INCA, ITAL, ACLI, CISL patronato, CGIL patronato |
| Veterinario | `amenity=veterinary` |

Si chiedono tag: `name`, `name:it`, `opening_hours`, `phone`, `contact:phone`, `website`, `addr:*`, `emergency`, `operator`.

Licenza: ODbL, attribuzione “© OpenStreetMap contributors”.

### 3.2 Ministero della Salute — anagrafe farmacie
- Pagina: https://www.dati.salute.gov.it/it/dataset/farmacie/
- Licenza IODL 2.0
- Contenuto: tutte le farmacie aperte al pubblico (codice ministeriale, indirizzo, comune, provincia, regione, coordinate se presenti). **Non contiene i turni.**
- Download: si provano in ordine gli URL storici `C_17_dataset_5_...CSV` e `C_17_dataset_7_...csv` (il Ministero li ha già spostati). Cache 24 h in `api/cache/farmacie.csv`.
- Uso: arricchire/completare i punti OSM nel raggio (match per distanza < 80 m o stesso comune + nome). Se OSM manca la farmacia, si mostra comunque il record ministeriale con orario “sconosciuto” finché non c’è OSM.

### 3.3 Nominatim
Geocoding e reverse. Policy: 1 richiesta/secondo, cache 7 giorni.

### 3.4 Festivi nazionali
Stesso elenco di Scadenze Italia (Pasqua calcolata). Servono per `PH` in `opening_hours` e per marcare “oggi è festa”.

### 3.5 Tempi di attesa PS
**Non esiste un feed AGENAS nazionale dei PS in tempo reale** (AGENAS fa le liste d’attesa specialistica, altra cosa).

Adapter in `api/lib/ps_attese.php`:
- Interfaccia: `fetch_wait(lat,lng) → [{ospedale, codice, in_attesa, fonte, aggiornato}]` o vuoto.
- v1: nessun scraping di portali regionali (si rompono in continuazione). Se in `config.local.php` c’è un URL JSON regionale, lo si legge.
- UI: se vuoto → “Attese live non pubblicate per questa zona”.

### 3.6 Turni farmacia commerciali
`farmaciediturno.org` ha un’API **con key, non open data**. Se `config.local.php` contiene `farmacie_turno_key`, si usa come strato extra e si mostra il disclaimer obbligatorio del fornitore. Senza key: solo OSM + euristica + segnalazioni.

### 3.7 Cosa NON si fa
Scraping dei 20 siti regionali Federfarma, scraping Google, inventare orari, mostrare “aperto” se manca `opening_hours`.

---

## 4. “Aperto ora” e “di turno”

Parser `opening_hours` OSM (sottoinsieme reale italiano):
- `24/7`
- `Mo-Fr 08:30-12:30,15:30-19:30; Sa 08:30-12:30`
- `Su off`, `PH off`
- overnight `20:00-08:00`
- Esito: `open` / `closed` / `unknown`

**Farmacia di turno (euristica, etichettata come tale):**
- aperta secondo orari **e** (festivo o domenica o ora ≥ 20 o ora < 8), oppure
- `24/7`, oppure
- segnalazione utente “di_turno” non scaduta.

Testo UI: “Probabile turno dai dati in mappa — verifica la bacheca”.

---

## 5. Segnalazioni utente

`POST /api/reports` (login): `place_key`, `kind` = `aperto` | `chiuso` | `di_turno`, `note` opzionale max 200.
Validità 12 ore. Max 20/giorno per utente. Si sommano in scheda: “3 persone: aperta ora”.

---

## 6. API

| Metodo | Percorso | Auth | Cosa |
|---|---|---|---|
| GET | `/config` | no | donate, disclaimer |
| GET | `/geocode?q=` | no | comuni IT |
| GET | `/places?lat&lng&r&cats&at` | no | luoghi + stato |
| GET | `/places/{key}` | no | dettaglio |
| POST | `/auth/register` | no | |
| POST | `/auth/login` | no | |
| POST | `/auth/verify` | no | |
| POST | `/auth/forgot` | no | |
| POST | `/auth/reset` | no | |
| GET | `/me` | sì | |
| PUT | `/me/home` | sì | |
| GET/POST/DELETE | `/favorites` | sì | |
| POST | `/reports` | sì | |
| PUT | `/me/alerts` | sì | |
| GET | `/cron?token=` | token | avvisi + refresh farmacie CSV |

---

## 7. Pagine UI
Home pubblica, risultati (lista+mappa), scheda, Entra/Registrati, Preferiti, Avvisi, Impostazioni, Fonti, Privacy.

---

## 8. Fuori v1
App nativa, attese PS di tutte le Regioni, navigazione turn-by-turn in-app, recensioni, chat.
