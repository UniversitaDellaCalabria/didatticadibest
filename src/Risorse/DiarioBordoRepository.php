<?php

declare(strict_types=1);

namespace App\Risorse;

use App\Core\Database;
use App\Eventi\Righe;

/** Il diario di bordo di auto e mezzi (risorse_diario): una riga per prenotazione, con km, litri e i valori di tutti i campi. */
final class DiarioBordoRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function perPrenotazione(int $prenotazioneId): ?array
    {
        return $this->db->riga('SELECT * FROM risorse_diario WHERE prenotazione_id = ?', [$prenotazioneId]);
    }

    /** @return array<string, mixed>|null */
    public function perId(int $id): ?array
    {
        return $this->db->riga('SELECT * FROM risorse_diario WHERE id = ?', [$id]);
    }

    /** @param array<string, mixed> $v data, conducente, km_iniziali, km_finali, km_percorsi, litri, valori_json, compilato_da */
    public function salva(int $risorsaId, int $prenotazioneId, array $v): void
    {
        $this->db->esegui(
            'INSERT INTO risorse_diario (risorsa_id, prenotazione_id, data, conducente, km_iniziali, km_finali, km_percorsi, litri, valori_json, compilato_da) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE data = VALUES(data), conducente = VALUES(conducente), km_iniziali = VALUES(km_iniziali), km_finali = VALUES(km_finali), km_percorsi = VALUES(km_percorsi),
                litri = VALUES(litri), valori_json = VALUES(valori_json), compilato_da = VALUES(compilato_da), modificato_il = NOW(), verificato_da = \'\', verificato_il = NULL',
            [$risorsaId, $prenotazioneId, $v['data'], $v['conducente'], $v['km_iniziali'], $v['km_finali'], $v['km_percorsi'], $v['litri'], $v['valori_json'], $v['compilato_da']]
        );
    }

    /** I km finali dell'ultimo viaggio registrato fino a questa data (esclusa una prenotazione), per proporre i km iniziali e controllarli. */
    public function ultimiKm(int $risorsaId, ?string $finoAllaData, ?int $escludiPrenotazione = null): ?int
    {
        $v = $this->db->valore(
            'SELECT km_finali FROM risorse_diario WHERE risorsa_id = ? AND km_finali IS NOT NULL' . ($finoAllaData ? ' AND data <= ?' : '') . ($escludiPrenotazione ? ' AND prenotazione_id <> ?' : '')
            . ' ORDER BY data DESC, id DESC LIMIT 1',
            array_values(array_filter([$risorsaId, $finoAllaData, $escludiPrenotazione], fn ($x) => $x !== null))
        );

        return $v === null ? null : (int) $v;
    }

    public function verifica(int $id, string $nome): void
    {
        $this->db->esegui('UPDATE risorse_diario SET verificato_da = ?, verificato_il = NOW() WHERE id = ?', [mb_substr($nome, 0, 200), $id]);
    }

    /**
     * Le righe del diario di una risorsa (con codice e orari della prenotazione), dalla più recente.
     *
     * @return list<array<string, string|null>>
     */
    public function elenco(int $risorsaId, ?string $dal = null, ?string $al = null, int $limite = 500): array
    {
        $dove = 'd.risorsa_id = ?';
        $par = [$risorsaId];
        if ($dal) {
            $dove .= ' AND d.data >= ?';
            $par[] = $dal;
        }
        if ($al) {
            $dove .= ' AND d.data <= ?';
            $par[] = $al;
        }

        return Righe::testo($this->db->righe(
            "SELECT d.*, p.codice, p.inizio, p.fine, p.nome AS pren_nome, p.cognome AS pren_cognome FROM risorse_diario d JOIN prenotazioni_risorse p ON p.id = d.prenotazione_id
             WHERE $dove ORDER BY d.data DESC, d.id DESC LIMIT " . (int) $limite,
            $par
        ));
    }

    /** @return array{viaggi: int, km: int, litri: float} */
    public function totali(int $risorsaId, ?string $dal = null, ?string $al = null): array
    {
        $r = $this->db->riga('SELECT COUNT(*) AS n, COALESCE(SUM(km_percorsi), 0) AS km, COALESCE(SUM(litri), 0) AS litri FROM risorse_diario WHERE risorsa_id = ?' . ($dal ? ' AND data >= ?' : '') . ($al ? ' AND data <= ?' : ''),
            array_values(array_filter([$risorsaId, $dal, $al], fn ($x) => $x !== null))) ?: [];

        return ['viaggi' => (int) ($r['n'] ?? 0), 'km' => (int) ($r['km'] ?? 0), 'litri' => (float) ($r['litri'] ?? 0)];
    }

    /**
     * Prenotazioni già concluse (confermate) di risorse con il diario attivo che non hanno ancora il diario. Filtro: utente (chi ha prenotato o altro
     * conducente, per email) oppure una risorsa.
     *
     * @return list<array<string, string|null>>
     */
    public function senzaDiario(?int $utenteId, ?string $email, ?int $risorsaId = null): array
    {
        $dove = ["r.diario_attivo = 1", "p.stato = 'confermata'", 'p.fine < NOW()', 'd.id IS NULL'];
        $par = [];
        if ($risorsaId !== null) {
            $dove[] = 'p.risorsa_id = ?';
            $par[] = $risorsaId;
        }
        if ($utenteId !== null) {
            $dove[] = '(p.utente_id = ? OR p.co_conducenti_json LIKE ?)';
            array_push($par, $utenteId, '%"email":"' . addcslashes(strtolower((string) $email), '%_\\') . '"%');
        }

        return Righe::testo($this->db->righe(
            'SELECT p.*, r.nome AS risorsa_nome FROM prenotazioni_risorse p JOIN risorse r ON r.id = p.risorsa_id LEFT JOIN risorse_diario d ON d.prenotazione_id = p.id
             WHERE ' . implode(' AND ', $dove) . ' ORDER BY p.inizio',
            $par
        ));
    }

    /**
     * Prenotazioni concluse da più di due ore e da meno di trenta giorni senza diario né promemoria: per l'email di promemoria del cron.
     *
     * @return list<array<string, string|null>>
     */
    public function daRicordare(): array
    {
        return Righe::testo($this->db->righe(
            "SELECT p.*, r.nome AS risorsa_nome, r.luogo, r.pagina_id, r.email_notifiche FROM prenotazioni_risorse p JOIN risorse r ON r.id = p.risorsa_id LEFT JOIN risorse_diario d ON d.prenotazione_id = p.id
             WHERE r.diario_attivo = 1 AND p.stato = 'confermata' AND d.id IS NULL AND p.diario_promemoria_il IS NULL AND p.fine < NOW() - INTERVAL 2 HOUR AND p.fine > NOW() - INTERVAL 30 DAY"
        ));
    }

    public function segnaPromemoria(int $prenotazioneId): void
    {
        $this->db->esegui('UPDATE prenotazioni_risorse SET diario_promemoria_il = NOW() WHERE id = ?', [$prenotazioneId]);
    }

    public function impostaCoConducenti(int $prenotazioneId, ?string $json): void
    {
        $this->db->esegui('UPDATE prenotazioni_risorse SET co_conducenti_json = ? WHERE id = ?', [$json, $prenotazioneId]);
    }

    /** @return array<string, mixed>|null la persona attiva dell'anagrafe con questo «Cognome Nome» o «Nome Cognome» (una sola) */
    public function personaPerNome(string $nome): ?array
    {
        $r = $this->db->righe(
            "SELECT id, cognome, nome, email FROM personale_ateneo WHERE attivo = 1 AND (TRIM(CONCAT(cognome, ' ', nome)) = ? OR TRIM(CONCAT(nome, ' ', cognome)) = ?)",
            [trim($nome), trim($nome)]
        );

        return count($r) === 1 ? $r[0] : null;
    }

    /** @return list<string> i nominativi del personale attivo, per il suggerimento dei co-conducenti */
    public function nominativiPersonale(): array
    {
        return array_map('strval', array_column($this->db->righe("SELECT TRIM(CONCAT(cognome, ' ', nome)) AS n FROM personale_ateneo WHERE attivo = 1 ORDER BY cognome, nome LIMIT 3000"), 'n'));
    }
}
