# Sincronizzazione Pokecardex ↔ CardTrader — Design

Data: 2026-11-02
Progetto: ctalert (cttools)
Stato: in attesa di approvazione

## 1. Obiettivo

Pokecardex diventa il client da telefono della collezione che vive su CardTrader. L'utente
registra, modifica e toglie carte su Pokecardex (app o sito); il pannello porta quelle modifiche
su CardTrader. Allo stesso modo, ciò che cambia su CardTrader (vendite, modifiche dal pannello o
dal sito CardTrader) arriva su Pokecardex.

CardTrader resta il punto di riferimento: inserzioni, prezzi e vendite vivono lì. Una carta
venduta su CardTrader esce dalla collezione anche su Pokecardex.

### 1.1 Obiettivi

- Allineamento in tutte e due le direzioni: aggiunte, quantità, rimozioni, vendite.
- Le carte nuove arrivano su CardTrader **ferme** e da lì decide la vendita dinamica
  (`keep_quantity`, `ApplyDynamicSale`).
- Giro automatico ogni 15 minuti più un bottone "Sincronizza ora".
- Il primo allineamento passa da un'anteprima che l'utente approva.
- Dopo il primo allineamento il pannello scrive da solo ciò di cui è sicuro (§ 6) e mette il
  resto in una lista "Da rivedere".

### 1.2 Fuori scopo

- Prodotti CardTrader diversi dalle carte Pokémon singole (sigillati, altri giochi): ignorati.
- Prezzi: il pannello non decide prezzi in questo flusso. Le carte nascono ferme; il prezzo di
  vendita lo risolve `ApplyDynamicSale` come oggi.
- Liste desideri, scambi, negozio di Pokecardex.

### 1.3 Cosa sarebbe un fallimento anche se "funziona"

- Una lettura vuota o parziale (sessione scaduta, sito cambiato) interpretata come "ho tolto
  tutto", con CardTrader svuotato di conseguenza.
- Copie pubblicate due volte o perse per una modifica avvenuta su tutti e due i lati.
- Una carta abbinata alla versione sbagliata (Cosmos, timbrata, Ball) e messa in vendita.

## 2. Cosa sappiamo di Pokecardex (verificato il 2026-11-02)

Pokecardex non ha un'API pubblica. Quanto segue è l'API interna del sito, verificata con la
sessione reale dell'utente nel browser, solo in lettura. Può cambiare senza preavviso.

### 2.1 Autenticazione

- Modulo classico: `POST https://www.pokecardex.com/connexion`, campi `csrf_test_name`,
  `redirect`, `username`, `password`, `autologin=1`. Il valore di `csrf_test_name` si legge dalla
  pagina `/connexion` (stesso cookie). Il nome suggerisce CodeIgniter.
- Nessun captcha nel modulo né nel bundle. Il sito segnala "troppi tentativi di accesso", quindi
  esiste un limite: mai ritentare il login in ciclo.
- Il sito sta dietro Cloudflare. **Non verificato:** se Cloudflare lascia passare richieste da
  server (PHP, Guzzle). È il primo passo del lavoro (§ 10).
- **Non verificati:** nomi e durata dei cookie di sessione e di `autologin`.
- Le chiamate API vogliono l'intestazione `X-CSRF-TOKEN` col valore di
  `<meta name="csrf-token">` di una qualunque pagina del sito, più `X-Requested-With: XMLHttpRequest`.

### 2.2 Chiamate

Tutte `POST https://www.pokecardex.com/api/collection/<azione>`, corpo
`application/x-www-form-urlencoded`, intestazione `X-CSRF-TOKEN` col valore del meta
`csrf-token` della pagina. Ogni azione accetta anche `lang` (lingua dell'interfaccia, non della
carta). Le scritture sono state osservate dal browser il 2026-11-12 su una carta di prova
(Scarhino 001/103 di Règne Delta, `id_card=26642`, versione Standard) e annullate subito dopo.

| Azione | Parametri | Risposta | Stato |
|---|---|---|---|
| `series` (`lang=en`) | | anagrafiche: `blocs[].series[]` (con `id_serie`, `nom_court`, `id_cardmarket`, `count`), `etats`, `langues`, `versions` | verificata |
| `get_all_collection` (`lang=en`) | | tutta la collezione: `cartes[]`, `collection[]`, prezzi | verificata anche da server il 2026-11-12: 0,7 s, 7,4 MB, 2222 carte, 2703 righe, 6139 copie |
| `get_carte_details` | `id_card` | cifrata: dettaglio di una carta con le sue righe | osservata |
| `get_cartes` (`id`, `lang`, `masterSetMode=false`) | | una serie | presente nel bundle, non riprovata |
| `add_carte_collection` | `id_card`, `quantite`, `id_etat`, `id_version`, `id_langue` | `{"id_ajout": <id riga>}` in chiaro, 200 | osservata |
| `update_carte_collection` | `id_collection` (id riga), `id_card`, `quantite`, `id_etat`, `id_version`, `id_langue`, `prix_achat`, `date_achat`, `note` (vuoti ammessi) | vuota, 204 | osservata: cambia quantità e condizione insieme |
| `plus_carte_collection` | `id_collection` | vuota, 204 | osservata: +1 sulla riga |
| `delete_carte_collection` | `id_collection` | vuota, 204 | osservata: cancella **una** riga |
| `delete_normale_carte_collection` | `id_card` | vuota, 204 | osservata: toglie **tutte** le righe Standard della carta (interruttore rapido della griglia). Il client non la usa |
| `delete_reverse_carte_collection`, `delete_version_carte_collection` | | | presenti nel bundle, stesso rischio della precedente: il client non le usa |
| `delete_all` | | azzera la collezione | **vietata**: il client non deve poterla chiamare |

Il client scrive solo con `add_carte_collection`, `update_carte_collection` e
`delete_carte_collection`, sempre per singola riga (`id_collection`). Valori osservati:
`id_etat` 1 Neuve, 6 Excellent (§ 5.1); `id_version` 1 Standard; `id_langue` 1 francese.

La giapponese si legge con `lang=jp`: **da verificare** se `get_all_collection` con `lang=jp`
restituisce la collezione giapponese separata.

### 2.3 Cifratura

Le risposte con dati arrivano come `{ "iv": base64, "data": base64 }`. Si decifrano con
AES-256-CBC (la chiave ha 32 caratteri), risultato JSON. La chiave è una costante nel bundle
`/react/<sezione>/assets/index-<hash>.js`; oggi sta in quello della home
(`/react/accueil/...`), dentro la funzione che chiama `crypto.subtle.importKey("raw", ...)`.
Il sito è stato ristrutturato rispetto al documento di ottobre (bundle e nomi diversi), quindi
la chiave va cercata a ogni sincronizzazione e messa in cache finché la decifratura funziona.

Ricerca della chiave: scaricare la home, prendere lo script `index-*.js`, cercare la costante
stringa di 32 caratteri assegnata subito prima della funzione con `AES-CBC`. Se la decifratura
fallisce: rileggere il bundle una volta; se fallisce ancora, il giro si ferma (§ 7).

```php
$plain = openssl_decrypt(
    base64_decode($response['data']),
    'aes-256-cbc',
    $key,
    OPENSSL_RAW_DATA,
    base64_decode($response['iv'])
);
```

### 2.4 Dati

Carta (`cartes[]`): `id_card`, `id_serie`, `nom_court`, `num_card`, `name_card_en`,
`id_cardmarket`, `id_tcgplayer`, `versions[]` (`id_version`, `nom_version_us`, `only_english`,
`langues[]` con le lingue in cui quella versione esiste).

Riga (`collection[]`): `id_collection`, `id_card`, `id_version`, `id_etat`, `id_langue`,
`quantite`.

Le lingue per versione permettono di evitare in partenza l'errore 402 (lingua inesistente per
quella carta) quando si scrive su Pokecardex.

## 3. Cosa c'è già nel pannello

- `CTApiClient`: client generico (`get`, `post`, `put`, `delete`) con chiave API per utente.
- `products`: copia locale delle inserzioni CardTrader della categoria 73 (Pokémon singole),
  aggiornata da `SyncProducts` (oggi solo col bottone in `ListProducts`), che a fine giro
  accoda `ApplyDynamicSale`.
- `ApplyDynamicSale`: tiene ferme `keep_quantity` copie per gruppo e mette in vendita
  l'eccedenza. Una carta ferma ha prezzo `config('app.ct_disable_price')` e tag
  `config('app.ct_vacation_tag')`. Divide le inserzioni di confine: **la stessa carta può
  quindi stare su più inserzioni CardTrader** (una ferma, una in vendita).
- Creazione prodotto CardTrader (`ApplyDynamicSale::splitListing`): `POST /products` con
  `blueprint_id`, `price`, `quantity` e `properties` `condition` (etichetta API, es. "Near Mint"),
  `pokemon_language`, `pokemon_reverse`, `signed`, `altered`, `first_edition`.
- `ct_blueprints` con `cardmarket_product_id`, aggiornata ogni giorno da
  `SyncCardtraderBlueprints`.
- Enum `ConditionType` (nm, sp, mp, pl, po) e `Language` (it, en, fr, de, es, pt, nl, ru, pl,
  sv, jp).
- Impostazioni utente come componenti Livewire nel profilo Filament
  (`MyProfileCtApiKey`, `MyProfileTelegram`, `MyProfileDynamicSale`).
- Notifiche Telegram per utente (`telegram_chat_id`).

## 4. Il cuore: confronto a tre

### 4.1 La chiave carta

Le due parti si confrontano su una **chiave carta** comune:

```
(blueprint_id, condition, language, reverse, first_edition)
```

- Lato CardTrader: somma delle `quantity` di tutte le inserzioni (ferme e in vendita) con quella
  chiave, escluse quelle `signed` o `altered` (Pokecardex non le rappresenta).
- Lato Pokecardex: somma delle `quantite` delle righe che, tradotte (§ 5), danno quella chiave.
  Più righe Pokecardex possono finire sulla stessa chiave (es. Neuve ed Excellent sono entrambe NM).

### 4.2 Lo stato concordato

Per ogni chiave il pannello salva la quantità concordata all'ultimo giro riuscito
(`pokecardex_sync_states.quantity`). A ogni giro, per ogni chiave:

P è la quantità su Pokecardex, C quella su CardTrader.

| Caso | Azione |
|---|---|
| P = C | nessuna scrittura, la base diventa P |
| P ≠ base, C = base | porta P su CardTrader |
| P = base, C ≠ base | porta C su Pokecardex |
| P ≠ base, C ≠ base, P ≠ C | conflitto → "Da rivedere" |

Dopo ogni scrittura riuscita la base diventa il nuovo valore. Una scrittura fallita lascia la
base com'era: il giro dopo la riprova.

Il primo giro non ha base: tutte le differenze vanno in anteprima (§ 8.1) e l'approvazione
dell'utente crea la base. Per ogni chiave con P ≠ C l'anteprima mostra entrambi i valori e
propone come predefinito il valore di CardTrader (decisione dell'utente); l'utente può invertire
la direzione riga per riga, o per gruppi (per esempio tutta un'espansione), prima di applicare.
Il freno sulle rimozioni (§ 7) non si applica a questa anteprima, perché è già una revisione
completa.

### 4.3 Scrivere su CardTrader

- **Aumento:** se esiste un'inserzione ferma con quella chiave, aumentarne `quantity`;
  altrimenti crearne una ferma (prezzo di disattivazione, tag vacanza). CardTrader può fondere la
  nuova inserzione con una identica: allineare la riga locale alla risorsa restituita, come fa
  già `splitListing`.
- **Diminuzione:** togliere prima dalle inserzioni in vendita, poi da quelle ferme (decisione
  dell'utente). Un'inserzione che arriva a zero si cancella. Dopo il giro la vendita dinamica
  riequilibra.
- **Inserzioni `sale_locked`:** nessun trattamento speciale (decisione dell'utente). Contano in C
  e si riducono come le altre.
- **Carte nuove:** nascono ferme, senza `sale_locked`, e partecipano sempre alla vendita
  dinamica (decisione dell'utente): a fine giro `ApplyDynamicSale` decide quante copie mettere in
  vendita. Valgono le stesse `keep_quantity` predefinite dell'utente (per tipo carta) di
  qualunque altra carta del pannello; nessuna impostazione dedicata alle carte da Pokecardex.
- A fine giro, se c'è stata almeno una scrittura su CardTrader, accodare `ApplyDynamicSale`.
- Dopo le scritture aggiornare la tabella locale `products` (riusare la logica di `SyncProducts`
  o rileggere `/products/export` al giro successivo, a scelta di chi costruisce).

### 4.4 Scrivere su Pokecardex

- **Aumento:** se c'è una riga con quella traduzione esatta (`id_card`, `id_version`, `id_etat`,
  `id_langue`), aumentarne la quantità; altrimenti `add_carte_collection` con i valori del § 5.
  Prima di scrivere controllare che la lingua esista per quella versione (`versions[].langues`);
  se manca → "Da rivedere".
- **Diminuzione:** togliere dalla riga con `id_collection` più alto (la più recente) e poi via via
  dalle altre. Una riga che arriva a zero si cancella.
- Ritmo: al massimo una chiamata al secondo.

## 5. Traduzioni

### 5.1 Condizioni

| Pokecardex `id_etat` | CardTrader | Da CardTrader a Pokecardex |
|---|---|---|
| 1 Neuve | nm | |
| 6 Excellent | nm | 6 (decisione dell'utente) |
| 2 Très bon | sp | 2 |
| 3 Bon | mp | 3 |
| 4 Moyen | pl | 4 |
| 5 Abîmée | po | 5 |

### 5.2 Lingue

| `id_langue` | 4 | 2 | 5 | 1 | 3 | 6 | 7 | 8 | 12 |
|---|---|---|---|---|---|---|---|---|---|
| Pokecardex | italiano | inglese | giapponese | francese | tedesco | spagnolo | portoghese | coreano | cinese s. |
| `Language` | it | en | jp | fr | de | es | pt | — | — |

Coreano e cinese non esistono nell'enum `Language`: queste righe restano "solo Pokecardex" e
compaiono in "Da rivedere" una volta sola, senza scritture.

### 5.3 Versioni

| `id_version` | Traduzione | Sicura? |
|---|---|---|
| 1 Standard | blueprint della carta, reverse 0, first_edition 0 | sì |
| 2 Reverse | blueprint della carta, reverse 1 | sì |
| 6 1st Edition | blueprint della carta, first_edition 1 | sì |
| tutte le altre (Ball, Masterball, Prize Pack, Cosmos, deck exclusives, Mirror, Cracked Ice) | regola salvata dall'utente | no, finché l'utente non conferma |

Forma delle regole per le versioni speciali (scelta mia):
- l'abbinamento definitivo è sempre per coppia (`id_card`, `id_version`) → blueprint, salvato in
  `pokecardex_card_matches`;
- per non sceglierle una a una, l'utente può confermare una **regola di serie**: "versione X della
  serie Pokecardex S → espansione CardTrader E, stesso numero di collezione" (esempio: 94 su PRE →
  `p-pre`). Il pannello applica la regola alle carte di quella serie e propone gli abbinamenti; se
  il numero trova esattamente un blueprint l'abbinamento è sicuro, altrimenti va in revisione;
- le versioni senza regola restano in revisione, con la scelta del blueprint a mano.

### 5.4 Dalla carta Pokecardex al blueprint

In ordine:

1. `id_cardmarket` → `ct_blueprints.cardmarket_product_id`. Se il risultato è uno solo, sicuro.
2. Se non è in `ct_blueprints`: export dei blueprint dell'espansione CardTrader corrispondente
   (`/blueprints/export?expansion_id=`), cerca in `card_market_ids`, salva in `ct_blueprints`.
3. Abbinamento salvato a mano dall'utente (§ 8.2).
4. Niente: la riga resta **in attesa** (§ 6).

Corrispondenza tra serie Pokecardex ed espansione CardTrader (scelta mia): la tabella
`expansions` non ha l'ID Cardmarket, quindi l'abbinamento automatico confronta `nom_us` con
`expansions.name_en` / `name` (esempio: "Delta Reign") e `nom_court` con `expansions.code` senza
distinzione tra maiuscole e minuscole. Se trova esattamente un'espansione la salva; altrimenti la
serie si abbina a mano nella revisione. Serve al passo 2, alle regole di serie (§ 5.3) e al
ritentativo automatico delle carte in attesa.

Dal blueprint alla carta Pokecardex (verso opposto): il pannello tiene il **catalogo completo** di
Pokecardex (decisione dell'utente: una carta presente solo su CardTrader va aggiunta anche su
Pokecardex). `get_all_collection` restituisce solo le carte possedute, quindi una volta al giorno
il pannello scarica il catalogo serie per serie con `get_cartes` (circa 250 serie, una chiamata al
secondo, più `lang=jp` per le giapponesi) e lo salva in `pokecardex_cards`. Da lì il verso
opposto usa lo stesso abbinamento per ID Cardmarket: blueprint → `cardmarket_product_id` →
`id_card`. Un prodotto CardTrader che non trova nessuna carta nel catalogo va in revisione una volta.

## 6. Sicuro, da rivedere, in attesa

**Scrive da solo:**
- abbinamento tramite ID Cardmarket a un solo blueprint, oppure abbinamento confermato a mano;
- versioni 1, 2, 6 o versioni con regola confermata;
- modifica avvenuta su un solo lato.

**"Da rivedere"** (nessuna scrittura per quella chiave finché l'utente non decide):
- versioni speciali senza regola;
- ID Cardmarket che porta a più blueprint, o a un blueprint di versione diversa (casi noti:
  Togekiss SSP 72, Fuecoco SSP 29);
- conflitti (§ 4.2);
- lingua inesistente sulla carta Pokecardex di destinazione;
- righe "solo Pokecardex" e "solo CardTrader".

**In attesa**, senza notifica: carte Pokecardex il cui blueprint CardTrader non esiste ancora. Caso
reale oggi: Delta Reign (DLR, serie 251, uscita 6 novembre 2026), 92 carte, 120 righe, 252 copie,
tutte con ID Cardmarket; su CardTrader l'espansione `dlr` ha solo 18 prodotti sigillati. A ogni
giro il pannello ritenta l'abbinamento; quando riesce, la carta segue il percorso normale.

## 7. Freni di sicurezza

- Se la lettura di uno dei due lati fallisce (errore HTTP, login fallito, decifratura fallita,
  JSON inatteso) il giro si ferma **prima di qualunque scrittura** e manda una notifica.
- Se un lato restituisce meno del 90% delle copie del giro precedente (soglia configurabile), il
  giro si ferma e manda una notifica. Copre sessioni scadute che rispondono con collezioni vuote.
- Se un giro automatico vuole togliere più di 20 copie in totale (soglia configurabile), non
  scrive e prepara un'anteprima da approvare.
- Un solo giro alla volta (`withoutOverlapping`, lock per utente).
- Il client Pokecardex non espone `delete_all` né altre azioni di massa.
- Login: al massimo un tentativo per giro; dopo un rifiuto per credenziali, niente più tentativi
  finché l'utente non salva di nuovo le credenziali.

## 8. Interfaccia

### 8.1 Pagina "Pokecardex" (Filament)

- Stato: ultimo giro, esito, prossimo giro, eventuale blocco attivo (§ 7) con il motivo.
- Bottone **Sincronizza ora**.
- **Anteprima**: elenco delle scritture proposte, raggruppate per direzione (verso CardTrader,
  verso Pokecardex) con nome carta, espansione, numero, condizione, lingua, versione, quantità
  prima e dopo. Bottoni "Applica" e "Annulla". Compare al primo giro e quando scatta la soglia del
  § 7.
- **Da rivedere**: una riga per problema, con l'azione per risolverlo (scegliere il blueprint,
  confermare una regola di versione, scegliere quale lato vince in un conflitto, ignorare per
  sempre).
- **In attesa**: elenco informativo, raggruppato per espansione.
- **Storico**: cosa ha scritto ogni giro, con direzione, carta, quantità, esito, errore.

### 8.2 Profilo

Componente `MyProfilePokecardex` accanto agli altri (fatto): username e password, salvati per
account nella tabella `pokecardex_accounts`, password cifrata (cast `encrypted`) e mai rimandata
al modulo. L'interruttore "sincronizzazione automatica attiva" arriva con la sincronizzazione.

## 9. Modello dati (indicativo)

```
pokecardex_accounts          user_id, username, password (encrypted), session_cookies (encrypted, json),
                             decrypt_key (nullable), enabled, last_run_at, blocked_reason (nullable)
pokecardex_series            id_serie (PK), lang (en|jp), code, name_us, id_cardmarket, expansion_id (nullable)
pokecardex_cards             id_card (PK), id_serie, num_card, name_en, id_cardmarket, versions (json)
                             (catalogo completo, aggiornato ogni giorno)
pokecardex_series_rules      user_id, id_serie, id_version → ct expansion_id (regole di serie, § 5.3)
pokecardex_card_matches      user_id?, id_card, id_version → blueprint_id, reverse, first_edition,
                             source (cardmarket|export|manual)
pokecardex_sync_states       user_id, blueprint_id, condition, language, reverse, first_edition,
                             quantity (la base concordata)
pokecardex_review_items      user_id, kind, payload (json), status (open|resolved|ignored)
pokecardex_sync_runs         user_id, started_at, finished_at, outcome, pcx_copies, ct_copies, message
pokecardex_sync_log          run_id, direction, key (json), quantity_before, quantity_after, outcome, message
```

Nomi e dettagli delle tabelle sono a discrezione di chi costruisce. L'unico vincolo è che la base
del § 4.2 sia per utente e per chiave carta.

## 10. Ordine di lavoro

1. **Prova da server** (fatto il 2026-11-12, Cloudflare non blocca; usa e getta): comando artisan che fa login, legge `get_all_collection`,
   decifra e stampa i conteggi. Legge le credenziali dal profilo dell'utente indicato
   (`php artisan pokecardex:probe <email>`), nessuna credenziale nel `.env`. Se
   Cloudflare blocca le richieste da server, ci si ferma e si ridiscute il progetto.
2. **Scoperta delle scritture** (fatto il 2026-11-12, risultati nel § 2.2): dal browser dell'utente, con la rete sotto osservazione, una
   aggiunta, una modifica di quantità e una cancellazione su una carta di prova, per avere nomi
   e parametri esatti delle azioni (§ 2.2). Annullare subito dopo.
3. Client Pokecardex (login, sessione, chiave, lettura, scrittura) con test su risposte registrate.
4. Catalogo giornaliero di Pokecardex, traduzioni e abbinamenti (§ 5).
5. Confronto a tre e freni (§ 4, § 7), con test.
6. Scritture su CardTrader e Pokecardex.
7. Interfaccia (§ 8) e programmazione ogni 15 minuti.
8. Primo allineamento reale con anteprima.

## 11. Rischi

- API interna di Pokecardex: percorsi, cifratura e chiave possono cambiare. I freni del § 7
  trasformano un cambiamento in un blocco con notifica, mai in scritture sbagliate.
- Cloudflare può bloccare le richieste da server in qualunque momento.
- Termini di servizio di Pokecardex non verificati per l'uso automatizzato.
- Le versioni speciali chiedono manutenzione a ogni espansione con varianti nuove.
- Ritardo: una carta venduta su CardTrader resta su Pokecardex fino a 15 minuti.

## 12. Decisioni

Dell'utente:
- primo giro: anteprima con entrambi i valori, CardTrader predefinito, direzione invertibile;
- le carte presenti solo su CardTrader si aggiungono su Pokecardex (serve il catalogo completo);
- NM da CardTrader verso Pokecardex: Excellent (6);
- diminuzioni prima dalle inserzioni in vendita, poi da quelle ferme (§ 4.3);
- `sale_locked` senza trattamento speciale (§ 4.3);
- carte nuove sempre soggette alla vendita dinamica (§ 4.3).

Mie, da controllare:
- regole di serie per le versioni speciali, abbinamento finale per carta (§ 5.3);
- abbinamento serie ↔ espansione per nome e codice, poi a mano (§ 5.4);
- soglie dei freni: 90% delle copie, 20 copie rimosse per giro (§ 7).
