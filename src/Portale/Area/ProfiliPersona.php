<?php

declare(strict_types=1);

namespace App\Portale\Area;

use App\Anagrafi\Anagrafe;
use App\Anagrafi\PersonaRepository;
use App\Auth\Abilitazioni\ServizioAbilitazioni;
use App\Auth\GruppoAziende;
use App\Auth\GruppoScuole;
use App\Core\Database;
use App\Uffici\Direzione;

/**
 * Chi è la persona che entra, per scegliere i riquadri dell'area personale. Usa solo quello che il portale sa già: il ruolo (principale e secondari),
 * il gruppo nell'anagrafe di Ateneo, gli uffici di cui fa parte, la Direzione e le abilitazioni di gestore. Chi non rientra in nessun profilo è «esterno».
 */
final class ProfiliPersona
{
    public const RUOLO_AMMINISTRATORE = 1;
    public const RUOLO_GESTORE = 2;
    public const RUOLO_STUDENTE = 3;
    public const RUOLO_PERSONALE = 4;

    public function __construct(
        private Database $db,
        private Direzione $direzione,
        private PersonaRepository $persone,
        private ServizioAbilitazioni $abilitazioni,
    ) {
    }

    /**
     * I profili della persona, nell'ordine di Profili::NOMI.
     *
     * @param array<string, mixed>|null $utente la riga di utenti
     * @return list<string>
     */
    public function di(?array $utente): array
    {
        if (!$utente || empty($utente['id'])) {
            return [Profili::ESTERNO];
        }
        $ruoli = array_values(array_unique(array_filter(array_map('intval', array_merge([(int) ($utente['ruolo_id'] ?? 5)], explode(',', (string) ($utente['ruoli_secondari'] ?? '')))))));
        $email = mb_strtolower(trim((string) ($utente['email'] ?? '')));
        $gruppoAnagrafe = $this->gruppoAnagrafe((string) ($utente['persona_id'] ?? ''));
        $nomiRuoli = $this->nomiRuoli($ruoli);
        $docente = $gruppoAnagrafe === 'docenti' || in_array(mb_strtolower(Anagrafe::GRUPPI_PERSONALE['docenti']), $nomiRuoli, true);
        $personale = in_array(self::RUOLO_PERSONALE, $ruoli, true) || in_array($gruppoAnagrafe, ['pta', 'altro'], true)
            || in_array(mb_strtolower(Anagrafe::GRUPPI_PERSONALE['pta']), $nomiRuoli, true) || in_array(mb_strtolower(Anagrafe::GRUPPI_PERSONALE['altro']), $nomiRuoli, true)
            || $this->inUnUfficio($email);

        $p = [
            Profili::STUDENTE => in_array(self::RUOLO_STUDENTE, $ruoli, true) || trim((string) ($utente['matricola_studente'] ?? '')) !== '',
            Profili::DOCENTE => $docente,
            Profili::DIRETTORE => $this->direzione->direttoreOVice($utente),
            Profili::PERSONALE => $personale,
            Profili::GESTORE => in_array(self::RUOLO_GESTORE, $ruoli, true) || $this->haAbilitazioni((int) $utente['id']),
            Profili::AMMINISTRATORE => in_array(self::RUOLO_AMMINISTRATORE, $ruoli, true),
            // una scuola: ha indicato la scuola in cui insegna o fa parte del gruppo «Scuole» (si può dare a mano da Utenti)
            Profili::SCUOLA => trim((string) ($utente['scuola_codice'] ?? '')) !== '' || in_array(mb_strtolower(GruppoScuole::NOME), $nomiRuoli, true),
            // un'azienda: fa parte del gruppo «Aziende» o la sua email è quella di una convenzione di tesi
            Profili::AZIENDA => in_array(mb_strtolower(GruppoAziende::NOME), $nomiRuoli, true) || $this->haConvenzione($email),
        ];
        $profili = array_keys(array_filter($p));

        return $profili ?: [Profili::ESTERNO];
    }

    private function gruppoAnagrafe(string $personaId): string
    {
        if ($personaId === '') {
            return '';
        }
        try {
            return (string) ($this->persone->perId($personaId)['gruppo'] ?? '');
        } catch (\Throwable $e) {
            error_log('Profili, gruppo in anagrafe: ' . $e->getMessage());

            return '';
        }
    }

    /** @param list<int> $ruoli @return list<string> */
    private function nomiRuoli(array $ruoli): array
    {
        if (!$ruoli) {
            return [];
        }
        try {
            return array_map(static fn (array $r): string => mb_strtolower((string) $r['nome']), $this->db->righe('SELECT nome FROM ruoli WHERE id IN (' . implode(',', $ruoli) . ')'));
        } catch (\Throwable $e) {
            error_log('Profili, nomi dei ruoli: ' . $e->getMessage());

            return [];
        }
    }

    /** Fa parte di un ufficio (la Direzione conta come profilo «direttore», non come personale). */
    private function inUnUfficio(string $email): bool
    {
        if ($email === '') {
            return false;
        }
        try {
            return (int) $this->db->valore("SELECT COUNT(*) FROM uffici_persone p JOIN uffici u ON u.id = p.ufficio_id WHERE LOWER(p.email) = ? AND u.chiave <> 'direzione'", [$email]) > 0;
        } catch (\Throwable $e) {
            error_log('Profili, uffici della persona: ' . $e->getMessage());

            return false;
        }
    }

    private function haConvenzione(string $email): bool
    {
        try {
            return (new GruppoAziende($this->db))->haConvenzione($email);
        } catch (\Throwable $e) {
            error_log('Profili, convenzioni di tesi: ' . $e->getMessage());

            return false;
        }
    }

    private function haAbilitazioni(int $utenteId): bool
    {
        try {
            return $this->abilitazioni->utenteHaAbilitazioni($utenteId);
        } catch (\Throwable $e) {
            error_log('Profili, abilitazioni: ' . $e->getMessage());

            return false;
        }
    }
}
