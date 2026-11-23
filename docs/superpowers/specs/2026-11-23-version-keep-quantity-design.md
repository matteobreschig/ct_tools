# Copie da tenere per versione — Design

Data: 2026-11-23
Progetto: ctalert (cttools)
Stato: in attesa di approvazione

## 1. Obiettivo

Nella pagina di una carta (sezione Carte) poter dire, per una singola versione, quante copie
tenere ferme (non in vendita). Esempio: della 129 del 30° Anniversario di Exeggutor di Alola
ne tengo 1 fuori dalla vendita.

Oggi la vendita dinamica (`ApplyDynamicSale`) conosce solo il minimo per gruppo carta (deroga
del gruppo, poi minimo per tipo, poi minimo globale). Manca il livello "versione".

### Obiettivi

- Un valore "copie da tenere" per versione, impostato da un'azione (non modificabile
  direttamente dalla colonna, decisione dell'utente).
- Le copie tenute per versione **si sommano** al minimo del gruppo: sono extra, non contano
  dentro il minimo di gruppo (decisione dell'utente).
- Funziona anche se per quel gruppo non c'è nessun minimo di gruppo.

### Fuori scopo

- Modifica in linea dalla colonna.
- Cambiare la regola di gruppo esistente.
- Azione di gruppo su più versioni (si può aggiungere dopo, non richiesta).

## 2. Approcci considerati

1. **Colonna su `products`.** Semplice, ma le righe prodotto nascono e muoiono: la divisione
   delle inserzioni ne crea di nuove e la sincronizzazione con CardTrader le ricrea. Il valore
   si perderebbe. Scartato.
2. **Tabella `version_keep_settings`, chiave per versione.** Stesso schema di
   `card_group_settings`. Sopravvive a divisioni e risincronizzazioni. **Scelto.**
3. Estendere `card_group_settings` con una chiave di versione. Mescola due livelli diversi
   nella stessa tabella. Scartato.

## 3. Design

### Dati

Tabella `version_keep_settings`: `user_id`, `blueprint_id`, `language`, `condition`,
`reverse`, `keep_quantity` (unsigned small int), unica su
`(user_id, blueprint_id, language, condition, reverse)`. `blueprint_id` identifica già
espansione e numero. Modello `VersionKeepSetting` con factory.

Valore 0 o vuoto = nessuna deroga: la riga viene cancellata.

### Azione

Azione di riga "Copie da tenere" in `VersionsRelationManager`: campo numerico, precompilato
col valore attuale, "0" toglie la regola. Una colonna di sola lettura "Da tenere" mostra il
valore (proposta mia, per vederlo senza aprire l'azione).

### Vendita dinamica

In `ApplyDynamicSale::handle`, per ogni gruppo, prima della regola di gruppo:

1. Per ogni versione con deroga, tra le righe non bloccate (`sale_locked`) e in ordine di
   prezzo crescente come oggi, tengo ferme fino a `keep_quantity` copie. La riga di
   confine con copie in eccesso si divide con `splitListing`, come già avviene.
2. Le righe usate così escono dal conteggio del gruppo.
3. Sulle righe restanti si applica la regola di gruppo invariata.

Se il gruppo non ha nessun minimo (`$keep === null`) le deroghe di versione si applicano
comunque e le altre righe restano come sono. Il ritorno anticipato all'inizio del job va
adeguato: si ferma solo se non esiste nessuna impostazione di nessun livello.

Una versione con deroga a 1 e una sola copia posseduta: la copia resta ferma. Se la deroga
supera le copie possedute, le tiene tutte.

### Test

Pest, con `Http::fake` sul pattern dei test esistenti: deroga che si somma al gruppo,
deroga senza minimo di gruppo, riga di confine divisa, righe bloccate ignorate, azione che
salva e che cancella a 0.

## 4. Scelte del costruttore

- Nome colonna e testi in italiano coerenti con la tabella.
- Ordine di tenuta: copie più economiche, come la regola di gruppo.
