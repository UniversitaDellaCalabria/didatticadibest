<?php

declare(strict_types=1);

namespace App\Disponibilita;

use App\Core\Database;

/**
 * Le disponibilità dei docenti per tesi e tirocini (tabella disponibilita_docenti): una riga per docente e per tipo. Gli orari arrivano dal chiamante, mai da NOW().
 * I corsi di laurea si scelgono dall'anagrafe di Ateneo (corsi_studio): si salvano i codici e si mostrano i nomi.
 */
final class DisponibilitaRepository
{
    public const TIPI = ['tesi' => 'Tesi', 'tirocinio' => 'Tirocinio'];
    public const MAX_POSTI = 50;
    public const LIMITI = ['temi' => 300, 'attivita' => 1500, 'obiettivi' => 1500, 'sede' => 200, 'note' => 500];

    public function __construct(private Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function perUtente(int $utenteId, string $tipo): ?array
    {
        return $this->db->riga('SELECT * FROM disponibilita_docenti WHERE utente_id = ? AND tipo = ?', [$utenteId, $tipo]);
    }

    /**
     * Salva (o aggiorna) la disponibilità. Ritorna un messaggio di errore o null.
     *
     * @param array<string, mixed> $d accetta, posti, occupati, corsi (elenco di codici), temi, attivita, obiettivi, sede, note
     */
    public function salva(int $utenteId, string $tipo, array $d, string $quando): ?string
    {
        if (!isset(self::TIPI[$tipo]) || $utenteId <= 0) {
            return 'Tipo di disponibilità non valido.';
        }
        $accetta = !empty($d['accetta']) ? 1 : 0;
        $posti = max(0, min(self::MAX_POSTI, (int) ($d['posti'] ?? 0)));
        $occupati = max(0, min(self::MAX_POSTI, (int) ($d['occupati'] ?? 0)));
        if ($accetta && $posti === 0) {
            return 'Indica quanti posti hai, oppure scegli «Non accetto per ora».';
        }
        $codici = $this->codiciValidi((array) ($d['corsi'] ?? []));
        $testo = static fn (string $k): string => mb_substr(trim(strip_tags((string) ($d[$k] ?? ''))), 0, self::LIMITI[$k]);
        $this->db->esegui(
            'INSERT INTO disponibilita_docenti (utente_id, tipo, accetta, posti, occupati, corsi, temi, attivita, obiettivi, sede, note, aggiornato_il)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE accetta = VALUES(accetta), posti = VALUES(posti), occupati = VALUES(occupati), corsi = VALUES(corsi), temi = VALUES(temi),
               attivita = VALUES(attivita), obiettivi = VALUES(obiettivi), sede = VALUES(sede), note = VALUES(note), aggiornato_il = VALUES(aggiornato_il)',
            [$utenteId, $tipo, $accetta, $posti, $occupati, implode(',', $codici), $testo('temi'), $testo('attivita'), $testo('obiettivi'), $testo('sede'), $testo('note'), $quando]
        );

        return null;
    }

    /** Toglie il docente dalla pagina pubblica. */
    public function elimina(int $utenteId, string $tipo): void
    {
        $this->db->esegui('DELETE FROM disponibilita_docenti WHERE utente_id = ? AND tipo = ?', [$utenteId, $tipo]);
    }

    /**
     * I docenti che hanno messo la disponibilità, per la pagina pubblica: nome, area scientifica, corsi (con i nomi) e dati inseriti.
     *
     * @return list<array<string, mixed>>
     */
    public function elenco(string $tipo): array
    {
        $righe = $this->db->righe(
            "SELECT d.*, u.nome, u.cognome, COALESCE(NULLIF(pa.email, ''), u.email) AS email_pubblica, pa.ssd
             FROM disponibilita_docenti d JOIN utenti u ON u.id = d.utente_id LEFT JOIN personale_ateneo pa ON pa.id = u.persona_id
             WHERE d.tipo = ? ORDER BY u.cognome, u.nome",
            [$tipo]
        );
        $nomi = [];
        foreach ($this->db->righe('SELECT codice, nome FROM corsi_studio') as $c) {
            $nomi[(string) $c['codice']] = (string) $c['nome'];
        }
        foreach ($righe as &$r) {
            $r['corsi_lista'] = [];
            foreach (array_filter(explode(',', (string) $r['corsi'])) as $codice) {
                $r['corsi_lista'][$codice] = $nomi[$codice] ?? $codice;
            }
        }

        return $righe;
    }

    /**
     * Solo i codici che esistono nell'anagrafe dei corsi di Ateneo.
     *
     * @param list<mixed> $codici
     * @return list<string>
     */
    private function codiciValidi(array $codici): array
    {
        $codici = array_values(array_unique(array_filter(array_map(static fn ($c): string => preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $c) ?? '', $codici))));
        if (!$codici) {
            return [];
        }
        $esistenti = array_map('strval', array_column($this->db->righe('SELECT codice FROM corsi_studio WHERE codice IN (' . implode(',', array_fill(0, count($codici), '?')) . ')', $codici), 'codice'));

        return array_values(array_intersect($codici, $esistenti));
    }
}
