# Copie da tenere per versione Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Poter impostare, per versione di una carta, quante copie tenere ferme, in aggiunta al minimo del gruppo.

**Architecture:** Nuova tabella `version_keep_settings` (modello `VersionKeepSetting`). `ApplyDynamicSale` estrae la logica per gruppo in un metodo testabile che prima ferma le copie delle versioni con deroga, poi applica la regola di gruppo sulle righe rimaste. Un'azione di riga in `VersionsRelationManager` scrive e cancella la deroga.

**Tech Stack:** Laravel 12, Filament 5, Livewire 4, Pest 4. Test su sqlite in memoria (la vista `card_groups` è solo MySQL: i test non passano da `handle()`).

**Spec:** `docs/superpowers/specs/2026-11-23-version-keep-quantity-design.md`

## Global Constraints

- Chiave di versione: `(user_id, blueprint_id, language, condition, reverse)`, unica.
- Le copie di versione sono extra rispetto al minimo del gruppo, mai dentro.
- Righe con `sale_locked` ignorate dalle deroghe, come dalla regola di gruppo.
- Ordine di tenuta: copie più economiche prima, come oggi.
- Valore 0 o vuoto cancella la deroga. Testi UI in italiano. Niente modifica in linea dalla colonna.
- PHP: `/Applications/MAMP/bin/php/php8.4.17/bin/php`. Dopo i PHP toccati: `vendor/bin/pint --dirty --format agent`.

## Review Focus

- Deroga maggiore delle copie possedute: le tiene tutte, nessuna vendita.
- Gruppo senza nessun minimo (`$keep === null`) ma con deroga di versione: la deroga si applica, il resto resta com'è.
- Nessuna deroga e nessun minimo ovunque: il job non fa nulla (ritorno anticipato invariato).
- Stessa versione con più righe (inserzioni divise): la deroga vale sul totale delle righe, non per riga.
- Deroga di un utente non tocca le versioni di un altro utente.

---

### Task 1: Tabella e modello

**Files:**
- Create: `database/migrations/2026_11_23_100000_create_version_keep_settings_table.php` (con `php artisan make:migration`)
- Create: `app/Models/VersionKeepSetting.php`, `database/factories/VersionKeepSettingFactory.php` (con `make:model -f`)
- Test: `tests/Feature/VersionKeepSettingTest.php` (con `make:test --pest`)

**Interfaces:**
- Produces: `VersionKeepSetting` con fillable `user_id, blueprint_id, language, condition, reverse, keep_quantity`; casts `reverse` bool, `keep_quantity` int. Metodo statico `VersionKeepSetting::keyFor(Product $product): string` che restituisce `"{blueprint_id}:{language->value}:{condition->value}:{(int) reverse}"`, e metodo `key(): string` uguale sull'istanza (`language` e `condition` come stringhe grezze).

- [ ] **Step 1: Test** in `VersionKeepSettingTest.php`: `it('builds the same key for a product and its setting')` (crea Product e VersionKeepSetting con gli stessi attributi, `expect(VersionKeepSetting::keyFor($product))->toBe($setting->key())`) e `it('is unique per user and version')` (secondo insert uguale lancia `QueryException`).
- [ ] **Step 2:** `php artisan test --compact --filter=VersionKeepSettingTest` deve fallire (classe mancante).
- [ ] **Step 3:** Migrazione: colonne come in spec, `foreignId('user_id')->constrained()->cascadeOnDelete()`, `unsignedBigInteger('blueprint_id')`, `string('language')`, `string('condition')`, `boolean('reverse')`, `unsignedSmallInteger('keep_quantity')`, `timestamps`, unique sulle cinque colonne. Modello e factory sul modello di `CardGroupSetting`.
- [ ] **Step 4:** Rilancio il test: PASS.
- [ ] **Step 5:** Commit `feat(version-keep): add version_keep_settings table and model`.

### Task 2: Logica in ApplyDynamicSale

**Files:**
- Modify: `app/Jobs/ApplyDynamicSale.php` (`handle()`, nuovo metodo)
- Test: `tests/Feature/ApplyDynamicSaleVersionKeepTest.php`

**Interfaces:**
- Consumes: `VersionKeepSetting::keyFor`, `splitListing`, `resolveSalePrice` esistenti.
- Produces: `protected function applyToProducts(Collection $products, ?int $groupKeep, Collection $versionKeeps, CTApiClient $client, array &$ctData): void`. `$products` è già ordinato per prezzo crescente e id (come oggi); `$versionKeeps` è `keep_quantity` indicizzato per `VersionKeepSetting::keyFor`. Il corpo del `each` attuale di `handle()` si sposta qui senza cambiare la regola di gruppo; `handle()` lo chiama per gruppo.

- [ ] **Step 1: Test** con la sottoclasse anonima che espone `applyToProducts` (pattern di `ApplyDynamicSaleSplitTest`), `Http::fake` come lì. Test, con i valori:
  - `it('keeps the version copies on top of the group minimum')`: gruppo keep 1, due versioni (A con deroga 1, B senza), 1 copia di A e 2 di B → A ferma, di B la più economica ferma, l'altra `sale = true`.
  - `it('applies a version keep when the group has no minimum')`: `$groupKeep = null`, A deroga 1 con 2 copie in due righe → la prima riga ferma (`sale = false`), la seconda e le righe di altre versioni non toccate.
  - `it('keeps all copies when the keep exceeds what is owned')`: deroga 3, copie 2 → `sale = false` su tutte.
  - `it('counts the keep over all rows of the same version')`: due righe da 1 copia, deroga 1 → una ferma, una passa alla regola di gruppo.
  - `it('splits the boundary row')`: riga da 3 copie, deroga 1, `Http::fake` come nel test di divisione → `quantity` locale 1 e chiamata PUT con `quantity` 1 e POST per 2.
  - `it('ignores locked rows')`: riga `sale_locked` non conta e non cambia.
- [ ] **Step 2:** Lanciare: FAIL (metodo mancante).
- [ ] **Step 3:** Estrarre il metodo. In testa, per ogni versione presente in `$versionKeeps`, scorrere le righe non bloccate della versione in ordine, tenendo ferme fino a `keep` copie; riga di confine in eccesso: `splitListing` con `resolveSalePrice` come nella regola di gruppo, riga intera ferma se la divisione non riesce. Le righe usate vanno nel push `$ctData` con prezzo `ct_disable_price` e tag vacanza, come per le altre ferme, e sono tolte dalla lista passata alla regola di gruppo. Se `$groupKeep` è null, esce dopo le deroghe. In `handle()` caricare `VersionKeepSetting::where('user_id', …)->get()->mapWithKeys(fn ($s) => [$s->key() => $s->keep_quantity])` e usarlo nel ritorno anticipato: esce solo se anche `$versionKeeps` è vuoto.
- [ ] **Step 4:** Test PASS, poi `php artisan test --compact` intero: restano verdi i test esistenti (`ExampleTest` è già rosso su main).
- [ ] **Step 5:** Commit `feat(version-keep): keep per-version copies in ApplyDynamicSale`.

### Task 3: Azione e colonna

**Files:**
- Modify: `app/Filament/Resources/CardGroups/RelationManagers/VersionsRelationManager.php`
- Test: `tests/Feature/VersionKeepActionTest.php`

**Interfaces:**
- Consumes: `VersionKeepSetting`, `VersionKeepSetting::keyFor`.
- Produces: azione di riga `Action::make('set_keep_quantity')` (`Filament\Actions\Action`), campo numerico `keep_quantity` (0 minimo, precompilato col valore attuale), e `TextColumn::make('keep_quantity')` etichetta "Da tenere", sola lettura, valore preso dalla deroga della versione (vuoto se assente).

- [ ] **Step 1: Test** Livewire sul relation manager con utente autenticato e pannello `admin` (come `PokecardexPageTest`): `it('saves the keep for a version')` (`callAction(TestAction::make('set_keep_quantity')->table($product), ['keep_quantity' => 1])` poi `assertDatabaseHas('version_keep_settings', [... 'keep_quantity' => 1])`), `it('deletes the keep when set to 0')`, `it('shows the keep in a read only column')`, `it('does not touch other users')`.
- [ ] **Step 2:** Lanciare: FAIL.
- [ ] **Step 3:** Aggiungere `recordActions([...])` con l'azione (`updateOrCreate` sulla chiave della versione col `user_id` di `Auth::id()`, `delete` se 0, notifica di conferma) e la colonna. Il valore della colonna si legge con `->state(fn (Product $record) => …)` da una mappa caricata una sola volta per evitare N+1.
- [ ] **Step 4:** Test PASS, suite intera.
- [ ] **Step 5:** `vendor/bin/pint --dirty --format agent`, commit `feat(version-keep): action and column to set copies to keep per version`.
