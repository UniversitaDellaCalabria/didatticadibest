<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Anagrafi\ServizioScuole;
use App\Anagrafi\Testi;
use App\Core\Database;
use App\Core\Orologio;

/**
 * Convenzioni e Allegato A di una scuola nell'Area personale: li vedono e li scaricano tutte le persone collegate alla scuola, cioè chi ha una
 * prenotazione di un'attività di Formazione Scuola Lavoro per quella scuola e chi ha la scuola nel profilo. Si vede lo storico delle
 * convenzioni (anche scadute). I file stanno in cartelle bloccate al web: si scaricano solo da documento_scuola.php, dopo questo controllo.
 */
final class ServizioDocumentiScuola
{
    /** Giorni prima della scadenza in cui la convenzione risulta «in scadenza». */
    private const GIORNI_IN_SCADENZA = 60;

    public function __construct(
        private Database $db,
        private ConvenzioneRepository $convenzioni,
        private ServizioRegistroConvenzioni $registro,
        private PannelloRepository $pannello,
        private ServizioConvenzioneOnline $online,
        private ServizioScuole $scuole,
        private Orologio $orologio
    ) {
    }

    /**
     * Codici delle scuole a cui la persona è collegata.
     *
     * @return list<string>
     */
    public function codici(int $utenteId, string $email): array
    {
        $email = strtolower(trim($email));
        $righe = $this->db->righe(
            "SELECT DISTINCT pr.scuola_codice AS c FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN progetti_dettagli pd ON pd.evento_id = t.evento_id
             WHERE pd.convenzione = 1 AND pr.scuola_codice IS NOT NULL AND pr.scuola_codice <> '' AND IFNULL(pr.stato, 'confermata') NOT IN ('annullata', 'rifiutata', 'scaduta')
               AND (pr.utente_id = ? OR (? <> '' AND LOWER(pr.email) = ?))
             UNION SELECT scuola_codice FROM utenti WHERE id = ? AND scuola_codice IS NOT NULL AND scuola_codice <> ''",
            [$utenteId, $email, $email, $utenteId]
        );
        $out = [];
        foreach ($righe as $r) {
            $c = ConvenzioneRepository::codiceValido((string) array_values($r)[0]);
            if ($c !== null) {
                $out[$c] = $c;
            }
        }

        return array_values($out);
    }

    public function collegata(int $utenteId, string $email, string $codice): bool
    {
        $c = ConvenzioneRepository::codiceValido($codice);

        return $c !== null && in_array($c, $this->codici($utenteId, $email), true);
    }

    /**
     * Le scuole della persona con lo storico delle convenzioni (la più recente per prima).
     *
     * @return list<array{codice: string, nome: string, allegato: bool, convenzioni: list<array{id: int, validita: string, stato: string, protocollo: string, file: bool, allegato: bool}>}>
     */
    public function perUtente(int $utenteId, string $email): array
    {
        $oggi = $this->orologio->adesso()->format('Y-m-d');
        $tra = $this->orologio->adesso()->modify('+' . self::GIORNI_IN_SCADENZA . ' days')->format('Y-m-d');
        $conAllegato = $this->scuoleConAllegatoGenerabile();
        $out = [];
        foreach ($this->codici($utenteId, $email) as $codice) {
            $conv = [];
            foreach ($this->convenzioni->dellaScuola($codice) as $c) {
                $conv[] = [
                    'id' => (int) $c['id'], 'validita' => PeriodiConvenzione::testoValidita($c), 'protocollo' => (string) ($c['protocollo'] ?? ''),
                    'stato' => self::stato($c, $oggi, $tra), 'file' => !empty($c['file_convenzione']), 'allegato' => !empty($c['file_allegato']),
                ];
            }
            $anagrafe = $this->scuole->perCodice($codice);
            $allegato = isset($conAllegato[$codice]);
            if (!$conv && !$allegato) {
                continue;
            }
            $out[] = ['codice' => $codice, 'nome' => $anagrafe ? Testi::etichettaScuola($anagrafe) : $codice, 'allegato' => $allegato, 'convenzioni' => $conv];
        }

        return $out;
    }

    /**
     * Stato di una convenzione: valida, in_scadenza, scaduta, futura.
     *
     * @param array<string, mixed> $c
     */
    public static function stato(array $c, string $oggi, string $traGiorni): string
    {
        if (!empty($c['data_stipula']) && $c['data_stipula'] > $oggi) {
            return 'futura';
        }
        if (!empty($c['scadenza']) && $c['scadenza'] < $oggi) {
            return 'scaduta';
        }

        return !empty($c['scadenza']) && $c['scadenza'] <= $traGiorni ? 'in_scadenza' : 'valida';
    }

    /**
     * File di una convenzione del registro (convenzione firmata o Allegato A caricato), se la persona è collegata alla scuola.
     *
     * @return array{percorso: string, nome: string, ext: string}|null
     */
    public function fileConvenzione(int $utenteId, string $email, string $codice, int $convenzioneId, string $colonna): ?array
    {
        $c = $this->convenzioni->perId($convenzioneId);
        if (!$c || strtoupper((string) $c['scuola_codice']) !== strtoupper(trim($codice)) || !$this->collegata($utenteId, $email, $codice)) {
            return null;
        }

        return $this->registro->fileDaScaricare($convenzioneId, $colonna);
    }

    /**
     * Allegato A (PDF precompilato) con tutte le attività FSL in essere della scuola, se la persona è collegata alla scuola.
     *
     * @return array{file: string, nome: string}|null file temporaneo da cancellare dopo l'uso
     */
    public function allegatoGenerato(int $utenteId, string $email, string $codice): ?array
    {
        $codice = (string) ConvenzioneRepository::codiceValido($codice);
        if ($codice === '' || !$this->collegata($utenteId, $email, $codice)) {
            return null;
        }
        $ids = [];
        foreach ($this->pannello->iscrizioniInEssere() as $x) {
            if (strtoupper((string) ($x['scuola_codice'] ?? '')) === $codice) {
                $ids[] = (int) $x['id'];
            }
        }

        return $ids ? $this->online->scaricaPerPrenotazioni('allegato_pdf', $ids) : null;
    }

    /** @return array<string, true> codici delle scuole con almeno un'attività FSL in essere (per cui si può generare l'Allegato A) */
    private function scuoleConAllegatoGenerabile(): array
    {
        $out = [];
        foreach ($this->pannello->iscrizioniInEssere() as $x) {
            $c = ConvenzioneRepository::codiceValido((string) ($x['scuola_codice'] ?? ''));
            if ($c !== null) {
                $out[$c] = true;
            }
        }

        return $out;
    }
}
