<?php

declare(strict_types=1);

namespace App\Sistema;

use App\Core\Database;
use App\Core\Orologio;

/**
 * Quattro numeri da seguire nel tempo, mostrati in Sistema: persone senza un'email affidabile, compiti fermi da più di GIORNI_FERMI giorni per gruppo,
 * giorni medi tra «inviata al Direttore» e «firmata», incarichi di tutorato con il registro ancora vuoto. Solo conteggi, nessun dato personale.
 */
final class MisureSistema
{
    public const GIORNI_FERMI = 7;

    public function __construct(private Database $db, private Orologio $orologio)
    {
    }

    /** Utenti senza email, oppure con un'email scelta da loro e mai verificata (non arrivano gli avvisi, né si può fidarsi). */
    public function utentiSenzaEmailAffidabile(): int
    {
        return (int) $this->db->valore("SELECT COUNT(*) FROM utenti WHERE email IS NULL OR email = '' OR (email_personalizzata = 1 AND email_verificata_il IS NULL)");
    }

    /** @return array<string, array{titolo: string, n: int}> compiti fermi da più di GIORNI_FERMI giorni */
    public function compitiFermi(): array
    {
        $limite = $this->orologio->adesso()->modify('-' . self::GIORNI_FERMI . ' days')->format('Y-m-d H:i:s');
        $conta = fn (string $sql): int => (int) $this->db->valore($sql, [$limite]);

        return [
            'tutorato_docente' => ['titolo' => 'Lettere di tutorato da firmare al docente', 'n' => $conta("SELECT COUNT(*) FROM tutorato_incarichi WHERE stato = 'confermata' AND COALESCE(confermata_il, aggiornata_il, creata_il) < ?")],
            'tutorato_direttore' => ['titolo' => 'Lettere di tutorato dal Direttore', 'n' => $conta("SELECT COUNT(*) FROM tutorato_incarichi WHERE stato = 'firmata' AND firmata_docente_il < ?")],
            'convenzioni_tesi_direttore' => ['titolo' => 'Convenzioni di tesi dal Direttore', 'n' => $conta("SELECT COUNT(*) FROM convenzioni_tesi WHERE stato = 'da_firmare' AND da_firmare_il < ?")],
            'convenzioni_tesi_azienda' => ['titolo' => 'Convenzioni di tesi che aspettano l\'azienda', 'n' => $conta("SELECT COUNT(*) FROM convenzioni_tesi WHERE stato IN ('invitata', 'compilata') AND creata_il < ?")],
            'convenzioni_tesi_ufficio' => ['titolo' => 'Convenzioni di tesi che aspettano l\'ufficio', 'n' => $conta("SELECT COUNT(*) FROM convenzioni_tesi WHERE stato IN ('modifiche', 'caricata', 'in_carico', 'firmata_direttore') AND COALESCE(aggiornata_il, creata_il) < ?")],
        ];
    }

    /** @return array{n: int, giorni: ?float} documenti firmati dal Direttore dall'invio alla firma (lettere di tutorato e convenzioni di tesi) */
    public function giorniMediFirmaDirettore(): array
    {
        $r = $this->db->riga(
            "SELECT COUNT(*) AS n, AVG(gg) AS gg FROM (
                SELECT DATEDIFF(firmata_direttore_il, firmata_docente_il) AS gg FROM tutorato_incarichi WHERE firmata_direttore_il IS NOT NULL AND firmata_docente_il IS NOT NULL
                UNION ALL
                SELECT DATEDIFF(firmata_direttore_il, da_firmare_il) FROM convenzioni_tesi WHERE firmata_direttore_il IS NOT NULL AND da_firmare_il IS NOT NULL
            ) t"
        );
        $n = (int) ($r['n'] ?? 0);

        return ['n' => $n, 'giorni' => $n === 0 || $r['gg'] === null ? null : round((float) $r['gg'], 1)];
    }

    /** Incarichi di tutorato firmati dal docente senza nessuna riga nel registro delle attività. */
    public function incarichiConRegistroVuoto(): int
    {
        return (int) $this->db->valore("SELECT COUNT(*) FROM tutorato_incarichi i WHERE i.stato IN ('firmata', 'protocollata') AND NOT EXISTS (SELECT 1 FROM tutorato_registro r WHERE r.incarico_id = i.id)");
    }
}
