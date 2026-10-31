<?php

declare(strict_types=1);

namespace App\Firme;

use App\Auth\Identita;
use App\Core\Database;
use App\Didattica\VerbaleFirmato;
use App\Fsl\ServizioFirmaDirettore;
use App\Tutorato\ServizioIncarichi;

/**
 * «Fatte»: le firme e le conferme date dalla persona negli ultimi mesi, in tutti i moduli, dalla più recente. Serve a ritrovare in un attimo
 * «ho già firmato quella?». Non scrive niente; ogni fonte che non risponde lascia il suo gruppo vuoto e l'errore va nel log.
 * Le firme del Direttore su convenzioni vanno a chi oggi firma al suo posto (Direttore o vice con «Direttore assente»).
 */
final class StoricoFirme
{
    public const MESI = 12;
    public const MAX_RIGHE = 300;

    public function __construct(
        private Database $db,
        private ServizioFirmaDirettore $direttore,
        private ServizioIncarichi $lettere,
        private VerbaleFirmato $verbali
    ) {
    }

    /**
     * @param array<string, mixed>|null $utente
     * @return list<array{tipo: string, titolo: string, dettaglio: string, quando: string}> quando = data e ora (Y-m-d H:i:s), la più recente prima
     */
    public function fatte(?array $utente, int $mesi = self::MESI): array
    {
        if (!$utente || empty($utente['id'])) {
            return [];
        }
        $dal = date('Y-m-d H:i:s', strtotime('-' . $mesi . ' months'));
        $out = [];
        foreach (['pratiche', 'conferme', 'convenzioniFsl', 'convenzioniTesi', 'tutorato', 'verbali'] as $metodo) {
            try {
                array_push($out, ...$this->$metodo($utente, $dal));
            } catch (\Throwable $e) {
                error_log('Storico delle firme (' . $metodo . '): ' . $e->getMessage());
            }
        }
        usort($out, static fn (array $a, array $b): int => strcmp($b['quando'], $a['quando']));

        return array_slice($out, 0, self::MAX_RIGHE);
    }

    /** @param array<string, mixed> $u */
    private function mia(array $u, mixed $email, mixed $personaId = null): bool
    {
        $mia = strtolower(trim((string) ($u['email'] ?? '')));

        return ($mia !== '' && $mia === strtolower(trim((string) $email))) || Identita::stessaScheda($u, $personaId === null ? null : (string) $personaId);
    }

    /** @return list<array{tipo: string, titolo: string, dettaglio: string, quando: string}> */
    private function pratiche(array $u, string $dal): array
    {
        $out = [];
        foreach ($this->db->righe(
            "SELECT f.email, f.persona_id, f.firmata_il, p.codice, p.nome, p.cognome, m.titolo FROM pratiche_firme f JOIN pratiche p ON p.id = f.pratica_id JOIN didattica_moduli m ON m.id = p.modulo_id
             WHERE f.stato = 'firmata' AND f.firmata_il >= ? ORDER BY f.firmata_il DESC",
            [$dal]
        ) as $r) {
            if ($this->mia($u, $r['email'], $r['persona_id'])) {
                $out[] = ['tipo' => 'pratica', 'titolo' => $r['titolo'] . ' · ' . trim($r['nome'] . ' ' . $r['cognome']), 'dettaglio' => 'pratica ' . $r['codice'] . ' · firmata', 'quando' => (string) $r['firmata_il']];
            }
        }

        return $out;
    }

    /** @return list<array{tipo: string, titolo: string, dettaglio: string, quando: string}> */
    private function conferme(array $u, string $dal): array
    {
        $out = [];
        foreach ($this->db->righe(
            "SELECT c.email, c.persona_id, c.esito_il, p.codice, p.nome, p.cognome, m.titolo FROM pratiche_conferme c JOIN pratiche p ON p.id = c.pratica_id JOIN didattica_moduli m ON m.id = p.modulo_id
             WHERE c.stato = 'confermata' AND c.esito_il >= ? ORDER BY c.esito_il DESC",
            [$dal]
        ) as $r) {
            if ($this->mia($u, $r['email'], $r['persona_id'])) {
                $out[] = ['tipo' => 'conferma', 'titolo' => $r['titolo'] . ' · ' . trim($r['nome'] . ' ' . $r['cognome']), 'dettaglio' => 'pratica ' . $r['codice'] . ' · confermata', 'quando' => (string) $r['esito_il']];
            }
        }

        return $out;
    }

    /** @return list<array{tipo: string, titolo: string, dettaglio: string, quando: string}> */
    private function convenzioniFsl(array $u, string $dal): array
    {
        if (!$this->direttore->puoFirmare($u)) {
            return [];
        }
        $out = [];
        foreach ($this->db->righe(
            "SELECT c.firmata_direttore_il, COALESCE(s.denominazione, c.scuola_codice) AS scuola FROM convenzioni_compilate c LEFT JOIN scuole s ON s.codice = c.scuola_codice
             WHERE c.firmata_direttore_il >= ? ORDER BY c.firmata_direttore_il DESC",
            [$dal]
        ) as $r) {
            $out[] = ['tipo' => 'convenzione_fsl', 'titolo' => 'Convenzione · ' . $r['scuola'], 'dettaglio' => 'Formazione Scuola Lavoro · firmata dal Direttore', 'quando' => (string) $r['firmata_direttore_il']];
        }

        return $out;
    }

    /** @return list<array{tipo: string, titolo: string, dettaglio: string, quando: string}> */
    private function convenzioniTesi(array $u, string $dal): array
    {
        if (!$this->direttore->puoFirmare($u)) {
            return [];
        }
        $out = [];
        foreach ($this->db->righe("SELECT azienda, firmata_direttore_il FROM convenzioni_tesi WHERE firmata_direttore_il >= ? ORDER BY firmata_direttore_il DESC", [$dal]) as $r) {
            $out[] = ['tipo' => 'convenzione_tesi', 'titolo' => 'Convenzione di tesi · ' . $r['azienda'], 'dettaglio' => 'ente o azienda per le tesi · firmata dal Direttore', 'quando' => (string) $r['firmata_direttore_il']];
        }

        return $out;
    }

    /** @return list<array{tipo: string, titolo: string, dettaglio: string, quando: string}> */
    private function tutorato(array $u, string $dal): array
    {
        $out = [];
        foreach ($this->db->righe(
            "SELECT i.*, b.direttore_persona_id, b.direttore_nome, b.direttore_email, b.direttore_cf FROM tutorato_incarichi i JOIN tutorato_bandi b ON b.id = i.bando_id
             WHERE i.firmata_docente_il >= ? OR i.firmata_direttore_il >= ? OR i.fine_firmata_il >= ?",
            [$dal, $dal, $dal]
        ) as $i) {
            $tutor = trim((string) $i['cognome'] . ' ' . (string) $i['nome']);
            $parti = [
                ['firmata_docente_il', 'docente', 'Lettera di incarico · tutor ' . $tutor, 'incarico ' . $i['codice'] . ' · firmata dal docente responsabile'],
                ['firmata_direttore_il', 'direttore', 'Lettera di incarico · tutor ' . $tutor, 'incarico ' . $i['codice'] . ' · firmata dal Direttore'],
                ['fine_firmata_il', 'docente', 'Fine attività · tutor ' . $tutor, 'incarico ' . $i['codice'] . ' · dichiarazione firmata dal docente'],
            ];
            foreach ($parti as [$colonna, $ruolo, $titolo, $dettaglio]) {
                if (!empty($i[$colonna]) && (string) $i[$colonna] >= $dal && $this->lettere->firmatario($i, $ruolo, $u)) {
                    $out[] = ['tipo' => 'tutorato', 'titolo' => $titolo, 'dettaglio' => $dettaglio, 'quando' => (string) $i[$colonna]];
                }
            }
        }

        return $out;
    }

    /** @return list<array{tipo: string, titolo: string, dettaglio: string, quando: string}> */
    private function verbali(array $u, string $dal): array
    {
        $out = [];
        // Il segretario ha firmato quando il verbale è passato al coordinatore (data dell'invio); il coordinatore quando è risultato firmato
        foreach ($this->db->righe(
            "SELECT * FROM didattica_sedute WHERE (verbale_stato IN ('coordinatore') AND verbale_inviato_il >= ?) OR (verbale_stato = 'firmato' AND (verbale_firmato_il >= ? OR verbale_inviato_il >= ?))",
            [$dal, $dal, $dal]
        ) as $s) {
            $data = $s['data'] ? ' del ' . date('d/m/Y', (int) strtotime((string) $s['data'])) : '';
            if ($this->mia($u, $s['segretario_email']) && !empty($s['verbale_inviato_il']) && (string) $s['verbale_inviato_il'] >= $dal) {
                $out[] = ['tipo' => 'verbale', 'titolo' => 'Verbale · ' . $s['organo'], 'dettaglio' => 'seduta' . $data . ' · firmato come segretario', 'quando' => (string) $s['verbale_inviato_il']];
            }
            if ($s['verbale_stato'] === 'firmato' && !empty($s['verbale_firmato_il']) && (string) $s['verbale_firmato_il'] >= $dal && $this->mia($u, $s['coordinatore_email'])) {
                $out[] = ['tipo' => 'verbale', 'titolo' => 'Verbale · ' . $s['organo'], 'dettaglio' => 'seduta' . $data . ' · firmato come coordinatore', 'quando' => (string) $s['verbale_firmato_il']];
            }
        }

        return $out;
    }
}
