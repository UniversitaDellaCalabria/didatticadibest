<?php

declare(strict_types=1);

namespace App\Tutorato;

use App\Core\Database;
use App\Core\Orologio;
use App\Infrastructure\Sicurezza\Cifratura;

/**
 * Dichiarazione individuale del tutor: salvataggio cifrato (AES-256-GCM, chiave nel .env), stato dei due passi e lettura dei dati con
 * registro di chi li apre. Finché la chiave non è nel .env la dichiarazione non si richiede (i dati bancari non si conservano in chiaro).
 */
final class ServizioDichiarazione
{
    public function __construct(
        private Database $db,
        private Cifratura $cifratura,
        private DichiarazioneIndividuale $dichiarazione,
        private Orologio $orologio
    ) {
    }

    /** La dichiarazione si richiede e si salva solo se la cifratura è configurata. */
    public function richiesta(): bool
    {
        return $this->cifratura->disponibile();
    }

    /**
     * Stato dei due passi (senza decifrare niente).
     *
     * @return array{passo2: bool, passo3: bool, completa: bool, iban_finale: string}
     */
    public function stato(int $incaricoId): array
    {
        $r = $this->db->riga('SELECT dati_cifrati, iban_finale, compilata_il FROM tutorato_dichiarazioni WHERE incarico_id = ?', [$incaricoId]);
        $dati = $r ? $this->decifra((string) $r['dati_cifrati']) : null;
        $p2 = isset($dati['passo2']) && $dati['passo2'];
        $p3 = isset($dati['passo3']) && $dati['passo3'];

        return ['passo2' => $p2, 'passo3' => $p3, 'completa' => $p2 && $p3, 'iban_finale' => (string) ($r['iban_finale'] ?? '')];
    }

    /** La dichiarazione è completa (o non si richiede ancora: cifratura non configurata). */
    public function soddisfatta(int $incaricoId): bool
    {
        return !$this->richiesta() || $this->stato($incaricoId)['completa'];
    }

    /**
     * I dati già compilati di un passo, per riproporli nel modulo (il tutor che ripassa dal proprio link). Non registra nessun accesso:
     * serve solo a chi sta compilando la propria dichiarazione.
     *
     * @return array<string, mixed>
     */
    public function datiPasso(int $incaricoId, int $passo): array
    {
        $r = $this->db->riga('SELECT dati_cifrati FROM tutorato_dichiarazioni WHERE incarico_id = ?', [$incaricoId]);
        $d = $r ? $this->decifra((string) $r['dati_cifrati']) : null;

        return (array) ($d['passo' . $passo] ?? []);
    }

    /**
     * Controlla e salva un passo (2 o 3). Ritorna l'elenco dei campi da correggere (vuoto = salvato) e i valori ripuliti da riproporre.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $incarico
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    public function salvaPasso(int $incaricoId, int $passo, array $post, array $incarico): array
    {
        if (!in_array($passo, [2, 3], true)) {
            return [['il passo della dichiarazione'], []];
        }
        if (!$this->richiesta()) {
            return [['la cifratura dei dati non è configurata sul portale: avvisa l\'ufficio'], []];
        }
        [$err, $d] = $this->dichiarazione->valida($passo, $post, $incarico);
        if ($err) {
            return [$err, $d];
        }
        $r = $this->db->riga('SELECT dati_cifrati FROM tutorato_dichiarazioni WHERE incarico_id = ?', [$incaricoId]);
        $tutti = ($r ? $this->decifra((string) $r['dati_cifrati']) : null) ?? [];
        $tutti['passo' . $passo] = $d;
        $cifrato = $this->cifratura->cifra((string) json_encode($tutti, JSON_UNESCAPED_UNICODE));
        if ($cifrato === null) {
            return [['il salvataggio cifrato non è riuscito'], $d];
        }
        $iban = (string) (($tutti['passo2']['iban'] ?? '') !== '' ? substr((string) $tutti['passo2']['iban'], -4) : '');
        $completa = !empty($tutti['passo2']) && !empty($tutti['passo3']);
        $adesso = $this->orologio->adesso()->format('Y-m-d H:i:s');
        $this->db->esegui(
            'INSERT INTO tutorato_dichiarazioni (incarico_id, dati_cifrati, iban_finale, compilata_il, aggiornata_il) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE dati_cifrati = VALUES(dati_cifrati), iban_finale = VALUES(iban_finale), compilata_il = VALUES(compilata_il), aggiornata_il = VALUES(aggiornata_il)',
            [$incaricoId, $cifrato, $iban, $completa ? $adesso : null, $adesso]
        );

        return [[], $d];
    }

    /**
     * Apre i dati personali (IBAN compreso) e registra chi li ha aperti e perché. Null se non ci sono o non si decifrano.
     *
     * @param array<string, mixed>|null $utente
     * @return array<string, mixed>|null
     */
    public function apri(int $incaricoId, ?array $utente, string $azione): ?array
    {
        $r = $this->db->riga('SELECT dati_cifrati FROM tutorato_dichiarazioni WHERE incarico_id = ?', [$incaricoId]);
        $d = $r ? $this->decifra((string) $r['dati_cifrati']) : null;
        if ($d === null) {
            return null;
        }
        $this->db->esegui('INSERT INTO tutorato_accessi_dati (incarico_id, utente_id, utente_email, azione) VALUES (?, ?, ?, ?)',
            [$incaricoId, (int) ($utente['id'] ?? 0) ?: null, mb_substr((string) ($utente['email'] ?? ''), 0, 150), mb_substr($azione, 0, 30)]);

        return $d;
    }

    /**
     * Chi ha aperto i dati personali di una lettera, dal più recente.
     *
     * @return list<array<string, mixed>>
     */
    public function accessi(int $incaricoId): array
    {
        return $this->db->righe('SELECT utente_email, azione, creato_il FROM tutorato_accessi_dati WHERE incarico_id = ? ORDER BY id DESC LIMIT 50', [$incaricoId]);
    }

    /**
     * PDF della dichiarazione compilata (registra l'accesso). Null se non è completa.
     *
     * @param array<string, mixed> $incarico
     * @param array<string, mixed>|null $utente
     */
    public function pdf(array $incarico, ?array $utente): ?string
    {
        $d = $this->apri((int) $incarico['id'], $utente, 'pdf');
        if (!$d || empty($d['passo2']) || empty($d['passo3'])) {
            return null;
        }

        return $this->dichiarazione->pdf($incarico, $d, $this->orologio->adesso()->format('d/m/Y'));
    }

    /** @return array<string, mixed>|null */
    private function decifra(string $cifrato): ?array
    {
        $chiaro = $cifrato !== '' ? $this->cifratura->decifra($cifrato) : null;
        $d = $chiaro !== null ? json_decode($chiaro, true) : null;

        return is_array($d) ? $d : null;
    }
}
