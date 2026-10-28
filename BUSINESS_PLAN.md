# Aperto Adesso — business plan

## Prodotto
“**Cosa è aperto adesso, vicino a me, in Italia.**” Farmacie (anche di notte/festivi), guardia medica, pronto soccorso, Poste, Comune, CAF/patronato, veterinario.

## Problema
Di domenica o alle 22 non esiste un posto unico nazionale. I turni farmacia sono regionali; i PS hanno cruscotti diversi; gli orari del Comune stanno sul sito del Comune. Google Maps è generico e spesso sbaglia i turni.

## Per chi
Chiunque in Italia, **anche senza account**: turista, genitore, chi sta male di notte, chi deve fare un’istanza al Comune, chi cerca il 24h veterinario.

## Promessa
Apri, autorizza la posizione (o scrivi il comune): vedi **aperti ora**, **chiusi**, **orario sconosciuto**, distanza, telefono, come arrivarci.

## Non è
- FeNeChe (eventi e gite)
- Il 118 (in emergenza si chiama il 118; disclaimer fisso)
- Un aggregatore a pagamento tipo farmaciediturno.org (opzionale in config se un giorno c’è una key)

## Come si copre TUTTA Italia
Un’unica rete: **OpenStreetMap** (stessi tag da Aosta a Lampedusa) + anagrafe **Ministero della Salute (farmacie)** dove il file è scaricabile + segnalazioni utente a breve scadenza. I tempi di attesa PS si mostrano **solo** se la Regione pubblica un JSON/CSV vivo; altrimenti “attese non disponibili, chiama o vai”.

## Modello
Gratis, ricerca pubblica. Account solo per preferiti, “casa mia”, segnalazioni, avviso email “farmacia di turno vicino a casa”. Ko-fi.

## Rischi
- OSM ha buchi di `opening_hours` → stato “orario non in mappa”, non inventiamo.
- Turni farmacia: **non esiste un open data nazionale dei turni**. Onestà in UI + segnalazioni 12 h + link “verifica in bacheca”.
- Overpass a volte è lento: race su più server, cache 15 minuti per cella.
- Dataset Ministero: l’URL del CSV è cambiato più volte; tentiamo più URL, se fallisce restiamo su OSM.
