<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Database;
use App\Infrastructure\Audit\AuditLog;

/**
 * Le conferme degli attestati di un docente tutte insieme: l'elenco di quelle che aspettano lui (con le righe che riguardano lui) e la conferma di più
 * richieste in un colpo solo. Per ogni richiesta fa quello che fa la pagina conferma_attestato.php: registra la conferma e, quando sono arrivate tutte
 * quelle della pratica, la manda al passo successivo dell'iter. Il rifiuto resta richiesta per richiesta, perché vuole un motivo.
 */
final class ConfermeInBlocco
{
    public function __construct(
        private ConfermaPraticaRepository $conferme,
        private ServizioConfermeDocenti $servizio,
        private ServizioPratiche $pratiche,
        private ServizioFlussoConvalide $flusso,
        private StoricoPratica $storico,
        private Database $db
    ) {
    }

    /**
     * Le richieste che aspettano la conferma di questa persona, con la pratica e le righe che la riguardano.
     *
     * @param array<string, mixed>|null $utente
     * @return list<array<string, mixed>>
     */
    public function inAttesa(?array $utente): array
    {
        if (!$utente || empty($utente['id'])) {
            return [];
        }
        $out = [];
        foreach ($this->conferme->inAttesaDi((string) ($utente['email'] ?? ''), (string) ($utente['persona_id'] ?? '')) as $c) {
            if (!$this->servizio->puoConfermare($c, $utente)) {
                continue;
            }
            $p = $this->pratiche->pratica((int) $c['pratica_id']);
            $c['righe'] = $p ? $this->servizio->righeDelDocente($p, $c) : [];
            $out[] = $c;
        }

        return $out;
    }

    /**
     * Conferma le richieste scelte (solo tra quelle che aspettano questa persona).
     *
     * @param array<string, mixed> $utente
     * @param list<int> $ids numeri delle conferme (pratiche_conferme.id)
     * @return list<array{id: int, ok: bool, titolo: string, messaggio: string}>
     */
    public function conferma(array $utente, array $ids): array
    {
        $mie = [];
        foreach ($this->inAttesa($utente) as $c) {
            $mie[(int) $c['id']] = $c;
        }
        $esiti = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $c = $mie[$id] ?? null;
            if ($c === null) {
                $esiti[] = ['id' => $id, 'ok' => false, 'titolo' => '', 'messaggio' => 'Non aspetta la tua conferma (forse è già stata data o annullata).'];
                continue;
            }
            $titolo = (string) $c['modulo_titolo'] . ' · ' . trim((string) $c['studente_nome'] . ' ' . (string) $c['studente_cognome']);
            $err = $this->servizio->conferma($c);
            if ($err === null) {
                $this->dopoConferma($c, (int) $utente['id']);
            }
            $esiti[] = ['id' => $id, 'ok' => $err === null, 'titolo' => $titolo, 'messaggio' => $err ?? 'Confermata.'];
        }

        return $esiti;
    }

    /** Audit e, se sono arrivate tutte le conferme della pratica, passo successivo dell'iter (la conferma è il passo «passo»: il successivo ha indice «passo»). */
    private function dopoConferma(array $c, int $utenteId): void
    {
        try {
            (new AuditLog($this->db))->registra($utenteId, 'Pratica: conferma del docente', ['Pratica' => $c['codice'], 'Da' => 'conferme in blocco'], (string) ($_SERVER['REMOTE_ADDR'] ?? 'Sconosciuto'));
        } catch (\Throwable $e) {
            error_log('Conferme in blocco (audit): ' . $e->getMessage());
        }
        if ($this->servizio->tutteConfermate((int) $c['pratica_id'])) {
            [$e2] = $this->flusso->trasmettiAlPasso((int) $c['pratica_id'], (int) $c['passo'], 0, 'Conferma dei docenti');
            if ($e2) {
                $this->storico->evento((int) $c['pratica_id'], 'messaggio', 'ufficio', 0, null, 'Dopo le conferme: ' . $e2, null, null, true);
            }
        }
    }
}
