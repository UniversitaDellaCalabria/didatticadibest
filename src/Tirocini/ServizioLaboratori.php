<?php

declare(strict_types=1);

namespace App\Tirocini;

use App\Anagrafi\ClientApiAteneo;
use App\Anagrafi\StruttureRepository;
use App\Core\Database;

/**
 * Laboratori interni per i tirocini: si scaricano dalle API pubbliche di Ateneo (laboratories/) per i dipartimenti scelti in
 * «Strutture e aggiornamento» dell'anagrafe, insieme al resto dell'anagrafe. Si può nascondere un laboratorio dall'elenco pubblico.
 */
final class ServizioLaboratori
{
    public function __construct(private Database $db, private ClientApiAteneo $api, private StruttureRepository $strutture)
    {
    }

    /**
     * Scarica i laboratori delle strutture scelte (o di una). Un laboratorio che l'Ateneo non pubblica più sparisce; se le API non rispondono
     * restano quelli di prima.
     *
     * @return array<string, array{ok: bool, esito: string}> per struttura
     */
    public function sincronizza(?string $soloStruttura = null): array
    {
        $esiti = [];
        foreach ($this->strutture->codici($soloStruttura) as $cod) {
            $elenco = $this->api->tutte('laboratories/', ['department' => $cod]);
            if ($elenco === null) {
                $esiti[$cod] = ['ok' => false, 'esito' => 'API non raggiungibili: laboratori di prima conservati'];
                continue;
            }
            $visti = [];
            $this->db->transazione(function () use ($elenco, $cod, &$visti): void {
                foreach ($elenco as $l) {
                    $id = (int) ($l['LaboratoryId'] ?? 0);
                    if ($id <= 0 || (isset($l['Visible']) && $l['Visible'] === false)) {
                        continue;
                    }
                    $visti[] = $id;
                    $ambiti = implode(', ', array_map(static fn ($s): string => (string) ($s['ScopeDescription'] ?? ''), (array) ($l['LaboratoryScopes'] ?? [])));
                    $dip = trim((string) preg_replace('/\s+/', ' ', (string) ($l['DepartmentName'] ?? '')));
                    $dip = preg_replace('/,\s*[A-Z]{3,10}$/u', '', $dip) ?? $dip; // «…della Terra, DIBEST» → «…della Terra»
                    $sigla = (string) ($l['LaboratoryAcronym'] ?? '');
                    $this->db->esegui(
                        'INSERT INTO tirocini_laboratori (id, nome, sigla, area, dip_cod, dipartimento, interdipartimentale, direttore, ambiti, descrizione, infrastruttura, superficie, docenti)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE nome = VALUES(nome), sigla = VALUES(sigla), area = VALUES(area), dip_cod = VALUES(dip_cod), dipartimento = VALUES(dipartimento),
                           interdipartimentale = VALUES(interdipartimentale), direttore = VALUES(direttore), ambiti = VALUES(ambiti), descrizione = VALUES(descrizione),
                           infrastruttura = VALUES(infrastruttura), superficie = VALUES(superficie), docenti = VALUES(docenti), aggiornato_il = NOW()',
                        [$id, mb_substr(trim((string) ($l['LaboratoryName'] ?? '')), 0, 255), mb_substr(in_array($sigla, ['', 'None'], true) ? '' : $sigla, 0, 60),
                         mb_substr((string) ($l['Area'] ?? ''), 0, 100), $cod, mb_substr($dip, 0, 255), (($l['Interdepartmental'] ?? '') === 'SI') ? 1 : 0,
                         mb_substr(self::nomeProprio((string) ($l['ScientificDirector'] ?? '')), 0, 150), mb_substr($ambiti, 0, 150),
                         self::descrizione($l), mb_substr((string) ($l['InfrastructureName'] ?? ''), 0, 150), mb_substr((string) ($l['Dimension'] ?? ''), 0, 20),
                         mb_substr(implode('; ', self::docenti($l)), 0, 1500)]
                    );
                }
                if ($visti) {
                    $this->db->esegui('DELETE FROM tirocini_laboratori WHERE dip_cod = ? AND id NOT IN (' . implode(',', array_map('intval', $visti)) . ')', [$cod]);
                } else {
                    $this->db->esegui('DELETE FROM tirocini_laboratori WHERE dip_cod = ?', [$cod]);
                }
            });
            $esiti[$cod] = ['ok' => true, 'esito' => count($visti) . ' laboratori'];
        }

        return $esiti;
    }

    public function impostaPubblica(int $id, bool $pubblica): void
    {
        $this->db->esegui('UPDATE tirocini_laboratori SET pubblica = ? WHERE id = ?', [$pubblica ? 1 : 0, $id]);
    }

    /** @return list<array<string, mixed>> tutti, per il pannello */
    public function tutti(): array
    {
        return $this->db->righe('SELECT * FROM tirocini_laboratori ORDER BY dipartimento, nome');
    }

    /** @return list<array<string, mixed>> quelli da mostrare al pubblico */
    public function pubblici(): array
    {
        return $this->db->righe('SELECT * FROM tirocini_laboratori WHERE pubblica = 1 ORDER BY nome');
    }

    /** Particelle che fanno parte del cognome quando il nome è tutto in maiuscolo («DE FRANCESCO ANNA» → «De Francesco» + «Anna»). */
    private const PARTICELLE = ['DE', 'DI', 'DA', 'DEL', 'DELLA', 'DELLO', 'DEI', 'DEGLI', 'DAL', 'DALLA', 'LA', 'LE', 'LO', 'VAN', 'VON', 'DEN', 'DER'];

    /**
     * «BRUNO Leonardo» → «Leonardo Bruno». Le API scrivono prima il cognome: se è in maiuscolo e il nome no, il cognome sono le parole in maiuscolo; se è tutto in
     * maiuscolo («COZZA RADIANA») il cognome è la prima parola, con le particelle che la precedono («DE FRANCESCO ANNA MARIA»).
     */
    public static function nomeProprio(string $api): string
    {
        $parole = array_values(array_filter(preg_split('/\s+/u', trim($api)) ?: [], static fn (string $p): bool => $p !== ''));
        $f = static fn (string $s): string => mb_convert_case(mb_strtolower($s), MB_CASE_TITLE);
        if (count($parole) >= 2 && !array_filter($parole, static fn (string $p): bool => $p !== mb_strtoupper($p))) {
            $n = 1;
            while ($n < count($parole) - 1 && in_array($parole[$n - 1], self::PARTICELLE, true)) {
                ++$n;
            }

            return trim(implode(' ', array_map($f, array_slice($parole, $n))) . ' ' . implode(' ', array_map($f, array_slice($parole, 0, $n))));
        }
        $cogn = [];
        $nomi = [];
        foreach ($parole as $p) {
            if ($p === mb_strtoupper($p) && !$nomi) {
                $cogn[] = $p;
            } else {
                $nomi[] = $p;
            }
        }

        return trim(implode(' ', array_map($f, $nomi)) . ' ' . implode(' ', array_map($f, $cogn)));
    }

    /**
     * I docenti e ricercatori coinvolti nel laboratorio (nomi «Nome Cognome»), in ordine di cognome, senza doppioni, senza voci vuote e senza il responsabile
     * scientifico, che si mostra a parte.
     *
     * @return list<string>
     */
    public static function docenti(array $l): array
    {
        $responsabile = mb_strtolower(self::nomeProprio((string) ($l['ScientificDirector'] ?? '')));
        $out = [];
        foreach ((array) ($l['LaboratoryResearchPersonnel'] ?? []) as $p) {
            $api = trim((string) (is_array($p) ? ($p['ResearchPersonnelName'] ?? '') : ''));
            if ($api === '' || $api === 'None') {
                continue;
            }
            $nome = self::nomeProprio($api);
            if ($nome !== '' && mb_strtolower($nome) !== $responsabile) {
                $out[mb_strtolower($nome)] = [mb_strtolower($api), $nome];
            }
        }
        uasort($out, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        return array_values(array_map(static fn (array $x): string => $x[1], $out));
    }

    /** Testo semplice (senza HTML) di ricerca, didattica o servizi: il primo che c'è, al massimo 700 caratteri */
    private static function descrizione(array $l): string
    {
        foreach (['LaboratoryTeachingScope', 'LaboratoryResearchScope', 'LaboratoryServicesScope'] as $campo) {
            $t = (string) ($l[$campo] ?? '');
            if ($t === '' || $t === 'None') {
                continue;
            }
            $t = html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '</li>'], ' ', $t)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $t = trim((string) preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', $t)));
            if ($t !== '') {
                return mb_strlen($t) > 700 ? rtrim(mb_substr($t, 0, 697)) . '…' : $t;
            }
        }

        return '';
    }
}
