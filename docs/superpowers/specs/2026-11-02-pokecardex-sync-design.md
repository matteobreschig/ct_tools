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
- Nessun giro automatico: la sincronizzazione parte solo dal bottone "Sincronizza ora"
  (decisione dell'utente, 2026-11-12). Niente programmazione e niente traffico verso
  Pokecardex quando l'utente non lo chiede.
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
propone come predefinito il valore di **Pokecardex** (decisione dell'utente, 2026-11-12, che
sostituisce quella iniziale "vince CardTrader"): le carte si inseriscono e modificano su
Pokecardex, si vendono di rado su CardTrader, e dopo aver riletto CardTrader tutte le 514 chiavi
diverse avevano più copie su Pokecardex (881 copie mai portate su CardTrader, nessun caso
opposto). L'utente può invertire la direzione riga per riga, o per gruppi (per esempio tutta
un'espansione), prima di applicare. Il freno sulle rimozioni (§ 7) non si applica a questa
anteprima, perché è già una revisione completa.

**Copie vendute prima del primo giro** (decisione dell'utente, 2026-11-12). Senza base, una copia
venduta su CardTrader e rimasta su Pokecardex sembra una copia mai caricata, e verrebbe
reinserita (e magari rimessa in vendita). Il primo giro legge quindi tutto lo storico vendite
CardTrader (`/orders?order_as=seller`, `CardTraderSales`), escludendo ordini annullati e carte
firmate o alterate, e lo raggruppa per chiave. Per ogni chiave con P > C le copie vendute, fino
alla differenza, si **tolgono da Pokecardex**; il resto si aggiunge su CardTrader. L'anteprima
mostra le date di vendita. Limite noto: una carta venduta e poi ricomprata e reinserita su
Pokecardex verrebbe proposta per la rimozione; l'anteprima permette di invertire. Dal secondo giro
lo storico non serve più: le vendite si vedono come C ≠ base.

**Primo giro per carta, condizioni da CardTrader** (decisione dell'utente, 2026-11-12). Il primo
giro non confronta chiave per chiave ma carta per carta (stesso blueprint, lingua, reverse e prima
edizione): le **quantità** le decide Pokecardex (tolte le copie vendute), le **condizioni** le
decide CardTrader, che è quello che vede chi compra. Su 46 carte, quasi tutte del Set Base, le
condizioni erano diverse e CardTrader era quasi sempre più severo di uno o due gradi: in quei casi
si corregge la riga Pokecardex e l'inserzione CardTrader resta com'è. Le copie in più di
Pokecardex tengono la loro condizione; se servono meno copie si tolgono prima quelle che
Pokecardex non ha in quella condizione, dalla peggiore. Mint su Pokecardex è "Neuve" (1) e vale
già NM.

Le scritture di una carta vanno **tutte insieme o nessuna**: togliere la riga Pokecardex nella
condizione vecchia senza poter aggiungere quella nuova perderebbe copie. Se una scrittura della
carta non si può fare, la carta resta com'è e va in revisione. Una lingua già presente nella
collezione per quella carta e versione si considera esistente anche se il catalogo Pokecardex non
la elenca (molte carte vintage risultano senza italiano nel catalogo ma ci sono in collezione).

Modifiche su entrambi i lati tra due giri (P ≠ base, C ≠ base, P ≠ C) restano un conflitto da
rivedere: l'utente vende di rado, quindi è un caso raro (decisione dell'utente, 2026-11-12).

### 4.2.1 Come è fatto (2026-11-12)

`App\Services\Pokecardex\SyncPlanner` produce un `SyncPlan` e non scrive niente:
- le righe Pokecardex passano dagli abbinamenti (§ 5.4) e dalle traduzioni; quelle senza
  abbinamento sicuro, lingua o condizione vanno in revisione o in attesa e restano fuori dal
  confronto;
- **quarantena:** una carta Pokecardex con almeno una riga senza abbinamento sicuro non riceve
  aggiunte. Senza questo freno un'inserzione CardTrader della stessa carta verrebbe aggiunta su
  Pokecardex come doppione della riga non abbinata;
- un aumento su Pokecardex deve sapere dove aggiungere: carta e versione delle righe esistenti
  della chiave, se sono una sola; altrimenti l'abbinamento inverso (§ 5.4). Serve anche la lingua
  sulla versione di destinazione (§ 4.4). Se manca qualcosa → revisione;
- **niente rimozioni da CardTrader al buio:** una diminuzione su CardTrader richiede di sapere a
  quale carta Pokecardex corrisponde la chiave (dalle righe o dall'abbinamento inverso) e che
  quella carta non sia in quarantena. Senza questo freno, con "vince Pokecardex", le inserzioni
  di carte con righe Pokecardex non abbinate (es. Prize Pack senza regola) sarebbero state
  cancellate da CardTrader perché per il confronto valevano 0;
- le chiavi senza base valgono base 0 dopo il primo giro;
- copie lette per i freni: tutte le righe Pokecardex e tutte le inserzioni CardTrader, anche
  quelle escluse dal confronto.

**Prova a secco sui dati reali (2026-11-12), primo giro:** 6153 copie su Pokecardex, 3758 su
CardTrader secondo la tabella `products`, che però era ferma al 12 giugno 2026. Il piano avrebbe
tolto 1991 copie da Pokecardex; il freno del primo giro lo manda comunque in anteprima. Da qui una
regola per il passo 6: **ogni giro rilegge le inserzioni CardTrader (`SyncProducts`) prima del
confronto**, e se la rilettura fallisce il giro si ferma. Revisioni: 493 righe senza abbinamento,
266 inserzioni senza destinazione su Pokecardex (144 sono Prize Pack, che su Pokecardex non hanno
la versione Standard: si risolvono con la regola PRZP → `playprizep`), 59 di lingua, 29 in
quarantena, 3 in attesa.

**Prova a secco dopo la rilettura di CardTrader, con vendite e condizioni da CardTrader
(2026-11-12):** Pokecardex −15 copie vendute (Abra, Kadabra, Rare Candy, Dawn del 3 novembre) e 46
correzioni di condizione (46 righe tolte e 46 aggiunte); CardTrader +820 copie su 465 chiavi e −42
copie su 32 chiavi in più rispetto a Pokecardex. Revisioni: 375 righe senza abbinamento, 312
senza corrispondenza, 26 in quarantena; 121 in attesa (quasi tutte Delta Reign).

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

### 4.5 Come è fatto il giro (2026-11-12)

`App\Jobs\SyncPokecardex`, un giro alla volta per utente (`ShouldBeUnique`):
1. rilegge le inserzioni CardTrader (`CardTraderInventory`, cioè `SyncProducts::sync()` senza
   accodare la vendita dinamica); `SyncProducts` ora lancia le eccezioni invece di ingoiarle quando
   è chiamato così;
2. aggiorna l'elenco serie se ha più di un giorno, legge la collezione Pokecardex, al primo giro lo
   storico vendite;
3. piano (§ 4.2.1) e freni (§ 7); il giro salva in `pokecardex_sync_runs` il piano (`plan`) e la
   sua impronta (`fingerprint`);
4. freno di blocco → `blocked`; anteprima → `preview`. L'anteprima approvata si applica rilanciando
   il giro con l'id della run approvata: le scritture partono solo se il nuovo piano ha la stessa
   impronta, altrimenti nuova anteprima;
5. scritture (`SyncApplier`): per carta, prima gli aumenti e poi le diminuzioni, così un errore
   lascia al massimo copie in più; al primo errore il giro si ferma (`failed`); la base cambia solo
   per le carte scritte per intero;
6. dopo scritture su CardTrader rilegge le inserzioni e accoda `ApplyDynamicSale`;
7. notifica nel pannello per ogni esito.

Scrittori: `CardTraderWriter` (aumento sull'inserzione ferma o nuova inserzione ferma con prezzo di
disattivazione e tag vacanza; diminuzione prima dalle inserzioni in vendita; inserzione a zero
cancellata con `DELETE /products/{id}`, **chiamata non ancora provata dal vivo**) e
`PokecardexWriter` (aumento sulla riga con la stessa traduzione o riga nuova; diminuzione dalla
riga più recente).

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

Codici letti da `series` il 2026-11-12:

| `id_langue` | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 9 | 10 | 11 | 8, 12, 13, 14, 15 |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Pokecardex | francese | inglese | tedesco | italiano | giapponese | spagnolo | portoghese | olandese | russo | polacco | coreano, cinese s., thai, cinese t., indonesiano |
| `Language` | fr | en | de | it | jp | es | pt | nl | ru | pl | — |

Le lingue senza corrispondenza su CardTrader restano "solo Pokecardex" e compaiono in "Da
rivedere" una volta sola, senza scritture. Lo svedese di CardTrader non esiste su Pokecardex.

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

Fatto il 2026-11-12 (`App\Services\Pokecardex\CardMatcher`). `ct_blueprints` **non** contiene gli
ID Cardmarket (colonna vuota su tutte le righe) e le sue righe guidano il recupero dei
`meta_name`, quindi gli abbinamenti usano una tabella propria, `ct_expansion_blueprints`, riempita
da `/blueprints/export?expansion_id=` (`BlueprintCatalog`), una volta al giorno al massimo per
espansione e solo quando serve.

Versioni sicure (1, 2, 6), in ordine:
1. abbinamento manuale salvato: vince sempre;
2. la serie deve avere un'espansione CardTrader, altrimenti "Da rivedere";
3. `id_cardmarket` → `ct_expansion_blueprints.cardmarket_product_id`: un solo blueprint e stesso
   numero di collezione → sicuro, salvato in `pokecardex_card_matches`; più blueprint o numero
   diverso → "Da rivedere"; nessuno → **in attesa**.

Versioni speciali: regola di serie (`pokecardex_series_rules`) → espansione CardTrader; dentro
quell'espansione prima per ID Cardmarket, poi per numero di collezione. Un solo risultato → sicuro.
Il primo criterio serve alle espansioni miste come Prize Pack, dove lo stesso numero compare più
volte. Il flag reverse di una versione speciale si ricava dal nome ("Reverse (...)"): le
inserzioni CardTrader di `p-asc`, `e-asc`, `p-wht` sono tutte reverse.

Verso opposto (blueprint → carta Pokecardex): abbinamento salvato compatibile con reverse e prima
edizione; altrimenti, se l'espansione è destinazione di regole, carte delle serie delle regole
(ID Cardmarket, poi numero); altrimenti carte delle serie abbinate all'espansione per ID
Cardmarket, con la versione 1, 2 o 6 secondo le proprietà dell'inserzione. Versione assente sulla
carta → "Da rivedere".

Corrispondenza serie ↔ espansione (`PokecardexCatalog::linkExpansions`): codice (`nom_court` =
`code`) o nome inglese uguale, senza distinguere maiuscole, solo se il risultato è uno; le serie
abbinate a mano (`expansion_source = manual`) non si toccano.

Catalogo Pokecardex (`pokecardex_series`, `pokecardex_cards`): le serie da `series`, le carte da
`get_all_collection` (quelle possedute) e da `get_cartes` per serie (le altre), caricate su
richiesta dentro il giro manuale e riscaricate solo dopo un giorno. Il catalogo è condiviso tra
gli utenti, come regole e abbinamenti.

**Export non pronto.** Per alcune espansioni `/blueprints/export` risponde 404 "Data is not ready"
e lo resta per minuti (visto su Prize Pack Series Two…Eight): le carte coinvolte restano in attesa
e si riprova al giro dopo.

**Prova sulla collezione reale (2026-11-12, 6153 copie):**
- serie abbinate da sole: 141 su 251; 50 delle 59 serie possedute;
- abbinate in sicurezza: 4876 copie (79%);
- versioni speciali senza regola: 793 copie, soprattutto Prize Pack (versioni 144 "Non Holo
  (PPS)" e 147 "Cosmos Holo (PPS)", 666 copie). Con la regola PRZP → `playprizep` 219 righe PRZP su
  225 trovano un blueprint unico per ID Cardmarket;
- serie senza espansione: 409 copie. Delta Reign (252) perché `dlr` non è ancora nella tabella
  `expansions` (arriva col prossimo `SyncProducts`); Scarlet & Violet Energy (`sveen`), SV Promos
  (`svpromo`), Wizards Promos (`wiz`), Prize Pack (`playprizep`) vanno abbinate a mano;
- numero diverso: 72 copie. Quasi tutte suffissi CardTrader delle varianti (`180nh`, `031h`,
  `104e`) o prefissi Pokecardex (`MEE001`); due errori veri (Lunatone abbinata a Latios, Mr. Mime
  95a a 95b). Restano "Da rivedere": togliere i suffissi in automatico rischierebbe di confondere
  holo e non holo (§ 1.3);
- in attesa: 3 copie.

Aperto: due versioni Pokecardex della stessa carta Prize Pack (144 e 147) hanno lo stesso ID
Cardmarket e finiscono sullo stesso blueprint. Nel verso opposto il blueprint non sa quale delle
due scegliere e va in revisione. Da decidere col confronto a tre (§ 4).

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
- Se un giro vuole togliere più di 20 copie in totale (soglia configurabile), non
  scrive e prepara un'anteprima da approvare.
- Un solo giro alla volta per utente (lock): un secondo clic mentre il giro è in corso non ne
  avvia un altro.
- Il client Pokecardex non espone `delete_all` né altre azioni di massa.
- Login: al massimo un tentativo per giro; dopo un rifiuto per credenziali, niente più tentativi
  finché l'utente non salva di nuovo le credenziali.

Fatti il 2026-11-12 (`SyncSafety`): soglia copie e soglia rimozioni in `config/services.php`
(`POKECARDEX_MIN_COPIES_RATIO`, `POKECARDEX_MAX_REMOVED_COPIES`), confronto con l'ultimo giro
`applied` in `pokecardex_sync_runs`. Lock per utente: `ShouldBeUnique` sul job del giro (§ 4.5).

## 8. Interfaccia

### 8.1 Pagina "Pokecardex" (Filament)

- Stato: ultimo giro, esito, eventuale blocco attivo (§ 7) con il motivo.
- Bottone **Sincronizza ora**: unico modo per avviare un giro. Il giro gira in coda e la
  pagina mostra quando è in corso e quando finisce.
- **Anteprima**: elenco delle scritture proposte, raggruppate per direzione (verso CardTrader,
  verso Pokecardex) con nome carta, espansione, numero, condizione, lingua, versione, quantità
  prima e dopo. Bottoni "Applica" e "Annulla". Compare al primo giro e quando scatta la soglia del
  § 7.
- **Da rivedere**: una riga per problema, con l'azione per risolverlo (scegliere il blueprint,
  confermare una regola di versione, scegliere quale lato vince in un conflitto, ignorare per
  sempre).
- **In attesa**: elenco informativo, raggruppato per espansione.
- **Storico**: cosa ha scritto ogni giro, con direzione, carta, quantità, esito, errore.

**Fatto il 2026-11-12** (`App\Filament\Pages\Pokecardex`, gruppo di navigazione "Pokecardex"):
stato dell'ultimo giro (si aggiorna da solo mentre il giro è in corso), bottoni "Sincronizza ora",
"Applica anteprima" e "Scarta anteprima", tabella del piano dell'ultimo giro con le sezioni
Scritture, Da rivedere e In attesa (filtro "Lato" per le scritture), storico degli ultimi dieci
giri. Risorsa "Serie Pokecardex": abbinamento a mano di una serie a un'espansione CardTrader e
regole delle versioni speciali; cambiare o togliere una regola cancella gli abbinamenti nati da
lei. **Non ancora fatti:** invertire il verso di una scrittura nell'anteprima, scegliere a mano il
blueprint di una carta, scegliere il lato in un conflitto, ignorare per sempre una revisione. Il
giro gira in coda: serve un worker attivo (`php artisan queue:work` o Horizon).

### 8.2 Profilo

Componente `MyProfilePokecardex` accanto agli altri (fatto): username e password, salvati per
account nella tabella `pokecardex_accounts`, password cifrata (cast `encrypted`) e mai rimandata
al modulo. Non c'è interruttore di sincronizzazione automatica: la sincronizzazione è solo manuale (§ 1.1).

## 9. Modello dati (indicativo)

```
pokecardex_accounts          user_id, username, password (encrypted), session_cookies (encrypted, json),
                             decrypt_key (nullable), last_run_at, blocked_reason (nullable)
pokecardex_series            id_serie (PK), lang (en|jp), code, name_us, id_cardmarket, expansion_id (nullable),
                             expansion_source (auto|manual), cards_loaded_at
ct_expansion_blueprints      blueprint_id (PK), expansion_ct_id, name, collector_number, cardmarket_product_id(s)
pokecardex_cards             id_card (PK), id_serie, num_card, name_en, id_cardmarket, versions (json)
                             (catalogo caricato per serie su richiesta, § 5.4)
pokecardex_series_rules      id_serie, id_version → expansion_id (regole di serie, § 5.3, condivise)
pokecardex_card_matches      id_card, id_version → blueprint_id, source (cardmarket|rule|manual)
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
4. Catalogo Pokecardex su richiesta, traduzioni e abbinamenti (§ 5) (fatto il 2026-11-12).
5. Confronto a tre e freni (§ 4, § 7), con test (fatto il 2026-11-12, § 4.2.1).
6. Scritture su CardTrader e Pokecardex (fatto il 2026-11-12, § 4.5).
7. Interfaccia (§ 8) con il bottone "Sincronizza ora" (fatta in parte il 2026-11-12, § 8.1).
8. Primo allineamento reale con anteprima.

## 11. Rischi

- API interna di Pokecardex: percorsi, cifratura e chiave possono cambiare. I freni del § 7
  trasformano un cambiamento in un blocco con notifica, mai in scritture sbagliate.
- Cloudflare può bloccare le richieste da server in qualunque momento.
- Termini di servizio di Pokecardex non verificati per l'uso automatizzato.
- Le versioni speciali chiedono manutenzione a ogni espansione con varianti nuove.
- Ritardo: una carta venduta su CardTrader resta su Pokecardex finché l'utente non sincronizza.
  Se nel frattempo la modifica anche su Pokecardex, il confronto a tre (§ 4) la tratta come
  modifica su entrambi i lati.

## 12. Decisioni

Dell'utente:
- primo giro: anteprima con entrambi i valori, quantità da **Pokecardex** (2026-11-12, prima era
  CardTrader) tolte le copie vendute su CardTrader, condizioni da **CardTrader**, direzione
  invertibile;
- le carte presenti solo su CardTrader si aggiungono su Pokecardex (serve il catalogo completo);
- NM da CardTrader verso Pokecardex: Excellent (6);
- diminuzioni prima dalle inserzioni in vendita, poi da quelle ferme (§ 4.3);
- `sale_locked` senza trattamento speciale (§ 4.3);
- carte nuove sempre soggette alla vendita dinamica (§ 4.3).

Mie, da controllare:
- regole di serie per le versioni speciali, abbinamento finale per carta (§ 5.3);
- abbinamento serie ↔ espansione per nome e codice, poi a mano (§ 5.4);
- soglie dei freni: 90% delle copie, 20 copie rimosse per giro (§ 7).
