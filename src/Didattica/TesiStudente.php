<?php

declare(strict_types=1);

namespace App\Didattica;

use App\ConvenzioniTesi\AziendaDellaPratica;
use App\Core\Database;

/**
 * «Tesi e laurea» dello studente per la tessera dell'area personale: la sua richiesta di tesi (la pratica del modulo con chiave «tesi»), la convenzione
 * dell'azienda scelta e i passi successivi (cerca un relatore, fai la richiesta, i passi del percorso). La lettura dal database sta in perUtente(),
 * le regole di cosa mostrare in riepilogo() (senza database, per provarle da sole).
 */
final class TesiStudente
{
    public function __construct(private Database $db, private AziendaDellaPratica $azienda)
    {
    }

    /**
     * @param array<string, mixed>|null $utente
     * @return array{pratica: ?array<string, mixed>, azienda: ?array<string, string>, modulo: ?int}
     */
    public function perUtente(?array $utente): array
    {
        $modulo = $this->db->valore("SELECT id FROM didattica_moduli WHERE chiave = 'tesi' AND attivo = 1 ORDER BY id LIMIT 1");
        $pratica = empty($utente['id']) ? null : $this->db->riga(
            "SELECT p.id, p.codice, p.stato, p.risposte_json FROM pratiche p JOIN didattica_moduli m ON m.id = p.modulo_id WHERE p.utente_id = ? AND m.chiave = 'tesi' ORDER BY p.id DESC LIMIT 1",
            [(int) $utente['id']]
        );
        $azienda = null;
        if ($pratica && $pratica['stato'] !== 'bozza') {
            $scelta = AziendaDellaPratica::scelta((array) (json_decode((string) $pratica['risposte_json'], true) ?: []));
            $azienda = $scelta === null ? null : $this->azienda->stato($scelta['valore'], $scelta['nome_altra'], (int) $pratica['id']);
        }
        if ($pratica) {
            unset($pratica['risposte_json']);
        }

        return ['pratica' => $pratica ?: null, 'azienda' => $azienda, 'modulo' => $modulo === null || $modulo === false ? null : (int) $modulo];
    }

    /**
     * Il testo e i pulsanti della tessera.
     *
     * @param array{pratica: ?array<string, mixed>, azienda: ?array<string, string>, modulo: ?int} $d risultato di perUtente()
     * @return array{testo: string, azioni: list<array{0: string, 1: string, 2: bool, 3: bool}>, novita: int} azioni = [etichetta, link, esterno, in evidenza]
     */
    public static function riepilogo(array $d): array
    {
        $p = $d['pratica'];
        $cerca = ['Cerca un relatore', 'disponibilita_tesi.php', false, false];
        $passi = ['I passi della tesi', 'percorso_tesi.php', false, false];
        if ($p === null) {
            $azioni = [];
            if ($d['modulo'] !== null) {
                $azioni[] = ['Fai la richiesta di tesi', 'modulo.php?id=' . $d['modulo'], false, true];
            }

            return ['testo' => 'Dalla scelta del relatore alla domanda di laurea: cerca un docente disponibile e invia la richiesta di tesi.', 'azioni' => [...$azioni, $cerca, $passi], 'novita' => 0];
        }
        $stato = (string) $p['stato'];
        $nome = Costanti::STATI_PRATICA[$stato][0] ?? $stato;
        $apri = 'pratiche.php?id=' . (int) $p['id'];
        $testo = 'La tua richiesta di tesi (pratica ' . $p['codice'] . '): ' . mb_strtolower($nome) . '.';
        $da_fare = in_array($stato, ['bozza', 'integrazione'], true);
        $az = $d['azienda'];
        if ($az !== null) {
            $testo .= ' ' . match ($az['esito']) {
                'valida' => 'Azienda convenzionata fino al ' . date('d/m/Y', (int) strtotime($az['valida_al'])) . '.',
                'in_scadenza' => 'Convenzione dell\'azienda in scadenza il ' . date('d/m/Y', (int) strtotime($az['valida_al'])) . '.',
                'scaduta' => 'La convenzione dell\'azienda è scaduta: va rinnovata.',
                'in_corso' => 'La convenzione dell\'azienda è in corso (' . $az['stato_in_corso'] . ').',
                'da_chiedere' => 'L\'azienda non è ancora convenzionata: può chiedere la convenzione online.',
                default => '',
            };
        }
        $azioni = [[$da_fare ? ($stato === 'bozza' ? 'Completa la richiesta' : 'Rispondi all\'integrazione') : 'Apri la richiesta', $apri, false, $da_fare]];
        if ($az !== null && in_array($az['esito'], ['da_chiedere', 'scaduta'], true)) {
            $azioni[] = ['Convenzione per l\'azienda', 'richiedi_convenzione_tesi.php', false, false];
        }

        return ['testo' => trim($testo), 'azioni' => array_slice([...$azioni, $cerca, $passi], 0, 3), 'novita' => $da_fare ? 1 : 0];
    }
}
