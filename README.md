# Aperto Adesso

Cosa è aperto adesso vicino a te, in tutta Italia. Vedi [SPEC.md](SPEC.md) e [BUSINESS_PLAN.md](BUSINESS_PLAN.md).

## Struttura

- `api/` — PHP 8 + MySQL (router in `api/index.php`, librerie in `api/lib/`). Lo schema DB si crea da solo al primo accesso.
- `web/` — sorgenti React (Vite). La build finisce nella root del progetto (`index.html` + `assets/`), servita da XAMPP.
- `api/cache/` (Overpass, Nominatim, CSV Ministero) e `api/data/outbox/` (email in locale) non sono raggiungibili dal web.

## Avvio in locale

1. XAMPP con Apache (mod_rewrite) e MySQL attivi. L’app è su `http://localhost/aperto-adesso/`.
2. Override facoltativi in `api/config.local.php` (stesso formato di `config.php`): credenziali DB, `debug => false`, `cron_token`, `ps_json_url`, `farmacie_turno_key`.
3. Frontend:

```bash
cd web && npm install && npm run build
```

Per sviluppare con hot reload: `npm run dev` in `web/` (il proxy inoltra `/aperto-adesso/api` ad Apache).

## Cron

Ogni ora (aggiorna il CSV farmacie ogni 24 h, pulisce dati scaduti, invia gli avvisi la domenica, nei festivi e dopo le 20):

```bash
curl -s "http://localhost/aperto-adesso/api/cron?token=aperto-adesso-cron-locale"
```

Aggiungi `&force=1` per provare l’invio degli avvisi fuori finestra. In locale le email sono file HTML in `api/data/outbox/`.

## Note sui dati

- **Overpass**: i mirror pubblici rifiutano le query con regex sul nome e quelle `around:` grandi; usiamo un `bbox` con soli filtri chiave/valore e classifichiamo per nome in PHP (`classify_place`). La prima ricerca in una zona può richiedere 10–25 s, poi c’è cache di 15 minuti per categoria.
- **CSV Ministero**: il file cambia nome ogni giorno (`FRM_FARMA_5_AAAAMMGG.csv`); l’URL viene letto dalla pagina del dataset. Si scartano le farmacie chiuse e quelle con coordinate segnaposto (centroide del comune condiviso da 3+ righe).
- **Turni farmacia**: euristica (aperta di notte/domenica/festivi secondo OSM, `24/7`, o segnalazione utente). Nessun open data nazionale.
