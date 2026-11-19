<?php

namespace App\Services\Pokecardex;

/**
 * Script SQL (MySQL o MariaDB) che porta su un altro database la
 * configurazione esportata da `pokecardex:export-config`. Fa le stesse cose di
 * `pokecardex:import-config` senza dover copiare un file nel container:
 * si incolla in un client SQL.
 *
 * Prima di scrivere controlla utente ed espansioni: ognuno finisce in una
 * colonna NOT NULL di una tabella temporanea, quindi se ne manca uno lo script
 * si ferma con un errore prima della transazione. Si può rilanciare: aggiorna
 * le righe che ci sono già e non cancella niente, a parte gli abbinamenti
 * automatici delle versioni con una regola, che il giro ricalcola.
 */
class ConfigSqlScript
{
    /**
     * @param  array{series: list<array<string, mixed>>, rules: list<array<string, mixed>>, manual_matches: list<array<string, mixed>>, decisions: list<array<string, mixed>>, exported_at: string}  $config
     */
    public function build(array $config, string $targetEmail): string
    {
        $user = '(SELECT id FROM users WHERE email = '.$this->quote($targetEmail).')';
        $lines = [
            '-- Configurazione della sincronizzazione Pokecardex, esportata il '.$config['exported_at'].'.',
            '-- Eseguire tutto insieme: se manca un utente o un\'espansione, lo script si ferma prima di scrivere.',
            '',
            'CREATE TEMPORARY TABLE pokecardex_import_check (what VARCHAR(255) NOT NULL, id BIGINT UNSIGNED NOT NULL);',
            'INSERT INTO pokecardex_import_check VALUES ('.$this->quote("utente {$targetEmail}").", {$user});",
        ];

        foreach ($this->expansions($config) as $ctId => $code) {
            $lines[] = 'INSERT INTO pokecardex_import_check VALUES ('.$this->quote("espansione {$code} ({$ctId})").', '.$this->expansion($ctId).');';
        }

        $lines[] = '';
        $lines[] = 'START TRANSACTION;';
        $lines[] = '';
        $lines[] = '-- Serie abbinate a mano o ignorate';

        foreach ($config['series'] as $series) {
            $linkedByHand = (bool) $series['linked_by_hand'];
            $expansion = $linkedByHand && $series['expansion_ct_id'] !== null ? $this->expansion($series['expansion_ct_id']) : 'NULL';
            $update = $linkedByHand
                ? 'expansion_id = VALUES(expansion_id), expansion_source = VALUES(expansion_source), ignored = VALUES(ignored), updated_at = VALUES(updated_at)'
                : 'ignored = VALUES(ignored), updated_at = VALUES(updated_at)';

            $lines[] = 'INSERT INTO pokecardex_series (id_serie, lang, code, name_us, id_cardmarket, expansion_id, expansion_source, ignored, created_at, updated_at) VALUES ('
                .implode(', ', [
                    (int) $series['id_serie'],
                    $this->quote($series['lang']),
                    $this->quote($series['code']),
                    $this->quote($series['name_us']),
                    $series['id_cardmarket'] === null ? 'NULL' : (int) $series['id_cardmarket'],
                    $expansion,
                    $linkedByHand ? "'manual'" : 'NULL',
                    $series['ignored'] ? 1 : 0,
                    'NOW()',
                    'NOW()',
                ])
                .") ON DUPLICATE KEY UPDATE {$update};";
        }

        $lines[] = '';
        $lines[] = '-- Regole di serie (gli abbinamenti automatici di quelle versioni si ricalcolano al prossimo giro)';

        foreach ($config['rules'] as $rule) {
            $idSerie = (int) $rule['id_serie'];
            $idVersion = (int) $rule['id_version'];

            $lines[] = "DELETE FROM pokecardex_card_matches WHERE source IN ('rule', 'cardmarket') AND id_version = {$idVersion} AND id_card IN (SELECT id_card FROM pokecardex_cards WHERE id_serie = {$idSerie});";
            $lines[] = "INSERT INTO pokecardex_series_rules (id_serie, id_version, expansion_id, created_at, updated_at) VALUES ({$idSerie}, {$idVersion}, {$this->expansion($rule['expansion_ct_id'])}, NOW(), NOW())"
                .' ON DUPLICATE KEY UPDATE expansion_id = VALUES(expansion_id), updated_at = VALUES(updated_at);';
        }

        $lines[] = '';
        $lines[] = '-- Abbinamenti manuali';

        foreach ($config['manual_matches'] as $match) {
            $lines[] = 'INSERT INTO pokecardex_card_matches (id_card, id_version, blueprint_id, source, created_at, updated_at) VALUES ('
                .(int) $match['id_card'].', '.(int) $match['id_version'].', '.(int) $match['blueprint_id'].", 'manual', NOW(), NOW())"
                .' ON DUPLICATE KEY UPDATE blueprint_id = VALUES(blueprint_id), source = VALUES(source), updated_at = VALUES(updated_at);';
        }

        $lines[] = '';
        $lines[] = '-- Decisioni dell\'utente';

        foreach ($config['decisions'] as $decision) {
            $lines[] = "INSERT INTO pokecardex_decisions (user_id, kind, subject, value, created_at, updated_at) VALUES ({$user}, "
                .$this->quote($decision['kind']).', '.$this->quote($decision['subject']).', '.$this->quote($decision['value']).', NOW(), NOW())'
                .' ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at);';
        }

        $lines[] = '';
        $lines[] = 'COMMIT;';
        $lines[] = 'DROP TEMPORARY TABLE pokecardex_import_check;';

        return implode("\n", $lines)."\n";
    }

    /**
     * Espansioni da ritrovare, per `ct_id`.
     *
     * @param  array{series: list<array<string, mixed>>, rules: list<array<string, mixed>>}  $config
     * @return array<int, string>
     */
    private function expansions(array $config): array
    {
        return collect($config['series'])
            ->filter(fn (array $series): bool => $series['linked_by_hand'] && $series['expansion_ct_id'] !== null)
            ->merge($config['rules'])
            ->mapWithKeys(fn (array $item): array => [(int) $item['expansion_ct_id'] => (string) ($item['expansion_code'] ?? $item['expansion_ct_id'])])
            ->all();
    }

    private function expansion(int $ctId): string
    {
        return "(SELECT id FROM expansions WHERE ct_id = {$ctId})";
    }

    private function quote(?string $value): string
    {
        return $value === null ? 'NULL' : "'".str_replace(['\\', "'"], ['\\\\', "''"], $value)."'";
    }
}
