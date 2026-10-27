<?php

declare(strict_types=1);

namespace App\Portale;

use App\Core\Database;
use App\Core\Sito;
use App\Didattica\ServizioUffici;
use App\Iscrizioni\AreaPersonaleRepository;

/**
 * Il calendario personale: in un posto solo gli eventi prenotati, le aule e i laboratori prenotati, il ricevimento di chi ha uno sportello (gli
 * appuntamenti presi dagli studenti) e le scadenze (conferma di un posto liberato). Serve la pagina calendario.php, il file .ics da scaricare, il
 * calendario a cui il telefono si abbona (link segreto per persona, che si può rigenerare) e il riepilogo settimanale per email.
 */
final class CalendarioPersonale
{
    /** Tipi di voce: nome, icona e colore. */
    public const TIPI = [
        'evento' => ['Eventi', 'fa-calendar-check', '#0d6efd'],
        'aula' => ['Aule e appuntamenti', 'fa-door-open', '#0d9488'],
        'ricevimento' => ['Il mio ricevimento', 'fa-user-clock', '#7c3aed'],
        'scadenza' => ['Scadenze', 'fa-hourglass-half', '#b91c1c'],
    ];

    private const STATI_EVENTO_VALIDI = ['confermata', 'confermato', 'confirmed', 'in_attesa'];

    public function __construct(
        private Database $db,
        private AreaPersonaleRepository $area,
        private ServizioUffici $uffici,
        private Sito $sito
    ) {
    }

    /**
     * Le voci della persona tra due date (Y-m-d, estremi compresi), in ordine di tempo.
     *
     * @param array<string, mixed> $utente riga di utenti (id, email, persona_id…)
     * @return list<array{tipo: string, titolo: string, inizio: string, fine: string, tutto_giorno: bool, luogo: string, link: string, stato: string, stato_et: string, uid: string}>
     */
    public function voci(array $utente, string $dal, string $al): array
    {
        $id = (int) ($utente['id'] ?? 0);
        $email = mb_strtolower(trim((string) ($utente['email'] ?? '')));
        $voci = [];
        $dentro = static fn (string $inizio): bool => substr($inizio, 0, 10) >= $dal && substr($inizio, 0, 10) <= $al;

        foreach ($this->area->dellUtente($id, $email) as $p) {
            $stato = (string) ($p['stato'] ?? 'confermata');
            if ($stato === 'richiesta_conferma' && !empty($p['scadenza_conferma']) && $dentro((string) $p['scadenza_conferma'])) {
                $voci[] = $this->voce('scadenza', 'Conferma il posto: ' . $p['evento_titolo'], (string) $p['scadenza_conferma'], null, false, '', 'miei_eventi.php', 'azione', 'Da confermare', 'conferma-' . (int) $p['id']);
                continue;
            }
            if (!in_array($stato, self::STATI_EVENTO_VALIDI, true) || empty($p['data_turno'])) {
                continue;
            }
            $giorno = (string) $p['data_turno'];
            if ($giorno < $dal || $giorno > $al) {
                continue;
            }
            $conOra = !empty($p['orario_inizio']);
            $inizio = $giorno . ' ' . ($conOra ? substr((string) $p['orario_inizio'], 0, 8) : '00:00:00');
            $fine = $conOra && !empty($p['orario_fine']) ? $giorno . ' ' . substr((string) $p['orario_fine'], 0, 8) : null;
            $titolo = (string) $p['evento_titolo'] . (!empty($p['nome_turno']) ? ' – ' . $p['nome_turno'] : '');
            $voci[] = $this->voce('evento', $titolo, $inizio, $fine, !$conOra, (string) ($p['evento_luogo'] ?? ''), 'miei_eventi.php',
                $stato === 'in_attesa' ? 'attesa' : 'ok', $stato === 'in_attesa' ? 'In lista d\'attesa' : 'Confermata', 'turno-' . (int) $p['turno_id'] . '-' . (int) $p['id']);
        }

        foreach ($this->area->risorsePrenotate($id) as $r) {
            if ($dentro((string) $r['inizio'])) {
                $voci[] = $this->voce('aula', (string) $r['risorsa_nome'], (string) $r['inizio'], (string) $r['fine'], false, (string) ($r['luogo'] ?? ''), 'miei_eventi.php#aule',
                    $r['stato'] === 'confermata' ? 'ok' : 'attesa', $r['stato'] === 'confermata' ? 'Confermata' : 'In attesa di approvazione', 'risorsa-' . $r['codice']);
            }
        }

        $sportelli = array_map('intval', array_column($this->uffici->sportelliUtente($utente), 'id'));
        if ($sportelli && $id > 0) {
            foreach ($this->db->righe(
                "SELECT pr.*, r.nome AS risorsa_nome, r.luogo FROM prenotazioni_risorse pr JOIN risorse r ON r.id = pr.risorsa_id
                 WHERE pr.risorsa_id IN (" . implode(',', $sportelli) . ") AND pr.stato IN ('confermata', 'da_approvare') AND DATE(pr.inizio) BETWEEN ? AND ?
                   AND (pr.utente_id IS NULL OR pr.utente_id <> ?) ORDER BY pr.inizio LIMIT 200",
                [$dal, $al, $id]
            ) as $r) {
                $chi = trim($r['nome'] . ' ' . $r['cognome']);
                $voci[] = $this->voce('ricevimento', 'Ricevimento' . ($chi !== '' ? ': ' . $chi : ''), (string) $r['inizio'], (string) $r['fine'], false, (string) $r['luogo'], 'ricevimento.php',
                    $r['stato'] === 'confermata' ? 'ok' : 'attesa', $r['stato'] === 'confermata' ? 'Confermato' : 'Da approvare', 'ricevimento-' . $r['codice']);
            }
        }

        usort($voci, static fn (array $a, array $b): int => [$a['inizio'], $a['titolo']] <=> [$b['inizio'], $b['titolo']]);

        return $voci;
    }

    /** @return array{tipo: string, titolo: string, inizio: string, fine: string, tutto_giorno: bool, luogo: string, link: string, stato: string, stato_et: string, uid: string} */
    private function voce(string $tipo, string $titolo, string $inizio, ?string $fine, bool $tuttoGiorno, string $luogo, string $link, string $stato, string $statoEt, string $uid): array
    {
        $ti = (int) strtotime($inizio);
        $tf = $fine !== null ? (int) strtotime($fine) : 0;
        if ($tf <= $ti) {
            $tf = $tuttoGiorno ? $ti : $ti + ($tipo === 'scadenza' ? 1800 : 3600);
        }

        return ['tipo' => $tipo, 'titolo' => $titolo, 'inizio' => date('Y-m-d H:i:s', $ti), 'fine' => date('Y-m-d H:i:s', $tf), 'tutto_giorno' => $tuttoGiorno,
            'luogo' => $luogo, 'link' => $link, 'stato' => $stato, 'stato_et' => $statoEt, 'uid' => $uid];
    }

    /**
     * Il calendario .ics (scaricato o a cui il telefono si abbona).
     *
     * @param list<array<string, mixed>> $voci
     */
    public function ics(array $voci, string $nome): string
    {
        $esc = static fn ($s): string => str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\\,', '\\n', '\\n'], html_entity_decode(strip_tags((string) $s), ENT_QUOTES, 'UTF-8'));
        $utc = static fn ($t): string => gmdate('Ymd\THis\Z', (int) strtotime((string) $t));
        $base = $this->sito->urlBase();
        $host = parse_url($base, PHP_URL_HOST) ?: 'dibest';
        $o = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//DiBEST//Calendario personale//IT\r\nCALSCALE:GREGORIAN\r\nX-WR-CALNAME:" . $esc($nome) . "\r\nX-WR-TIMEZONE:Europe/Rome\r\n"
            . "REFRESH-INTERVAL;VALUE=DURATION:PT6H\r\nX-PUBLISHED-TTL:PT6H\r\n";
        foreach ($voci as $v) {
            $o .= "BEGIN:VEVENT\r\nUID:" . $esc($v['uid']) . '@' . $host . "\r\nDTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
            if ($v['tutto_giorno']) {
                $o .= 'DTSTART;VALUE=DATE:' . date('Ymd', (int) strtotime((string) $v['inizio'])) . "\r\nDTEND;VALUE=DATE:" . date('Ymd', (int) strtotime($v['inizio'] . ' +1 day')) . "\r\n";
            } else {
                $o .= 'DTSTART:' . $utc($v['inizio']) . "\r\nDTEND:" . $utc($v['fine']) . "\r\n";
            }
            $o .= 'SUMMARY:' . $esc($v['titolo']) . "\r\n" . ($v['luogo'] !== '' ? 'LOCATION:' . $esc($v['luogo']) . "\r\n" : '')
                . 'DESCRIPTION:' . $esc(self::TIPI[$v['tipo']][0] . ' · ' . $v['stato_et']) . "\r\nURL:" . $esc($base . '/' . $v['link']) . "\r\n"
                . 'CATEGORIES:' . $esc(self::TIPI[$v['tipo']][0]) . "\r\nSTATUS:" . ($v['stato'] === 'ok' ? 'CONFIRMED' : 'TENTATIVE') . "\r\nEND:VEVENT\r\n";
        }

        return $o . "END:VCALENDAR\r\n";
    }

    // ---- il link segreto del calendario a cui il telefono si abbona ----

    /** Il codice della persona (lo crea la prima volta). */
    public function token(int $utenteId): string
    {
        $t = (string) ($this->db->valore('SELECT token FROM calendario_token WHERE utente_id = ?', [$utenteId]) ?? '');

        return $t !== '' ? $t : $this->rigenera($utenteId);
    }

    /** Nuovo codice: il vecchio link smette di funzionare. */
    public function rigenera(int $utenteId): string
    {
        $t = bin2hex(random_bytes(20));
        $this->db->esegui('INSERT INTO calendario_token (utente_id, token) VALUES (?, ?) ON DUPLICATE KEY UPDATE token = VALUES(token), creato_il = NOW()', [$utenteId, $t]);

        return $t;
    }

    public function utentePerToken(string $token): ?int
    {
        if (!preg_match('/^[a-f0-9]{40}$/', $token)) {
            return null;
        }
        $id = $this->db->valore('SELECT utente_id FROM calendario_token WHERE token = ?', [$token]);

        return $id !== null ? (int) $id : null;
    }

    /**
     * Email di chi ha qualcosa in agenda tra due date (per il riepilogo settimanale esteso a tutti).
     *
     * @return list<string>
     */
    public function emailConAgenda(string $dal, string $al): array
    {
        $righe = $this->db->righe(
            "SELECT LOWER(pr.email) AS e FROM prenotazioni pr JOIN turni t ON t.id = pr.turno_id
              WHERE pr.stato IN ('confermata', 'confermato', 'confirmed', 'in_attesa') AND t.data_turno BETWEEN ? AND ? AND pr.email <> ''
             UNION SELECT LOWER(email) FROM prenotazioni_risorse WHERE stato IN ('confermata', 'da_approvare') AND DATE(inizio) BETWEEN ? AND ? AND email <> ''",
            [$dal, $al, $dal, $al]
        );
        $out = [];
        foreach ($righe as $r) {
            $e = trim((string) array_values($r)[0]);
            if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $out[$e] = true;
            }
        }

        return array_keys($out);
    }
}
