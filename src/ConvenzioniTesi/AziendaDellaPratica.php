<?php

declare(strict_types=1);

namespace App\ConvenzioniTesi;

use App\Core\Database;
use App\Core\Orologio;

/**
 * L'azienda scelta in una pratica di tesi (campo «Azienda convenzionata per la tesi») e lo stato della sua convenzione: valida (fino a quando), in scadenza prima
 * della fine della tesi, scaduta, in corso (l'azienda la sta compilando) o ancora da chiedere. Si mostra allo studente e all'ufficio nella pratica.
 */
final class AziendaDellaPratica
{
    /** Una tesi dura fino a 8 mesi (art. 2): se la convenzione scade prima si segnala. */
    private const GIORNI_TESI = 240;

    public function __construct(private Database $db, private Orologio $orologio)
    {
    }

    /** Come si scrive l'azienda nell'elenco: «Ragione sociale – Comune», o solo la ragione sociale. */
    public static function etichetta(string $azienda, string $datiJson): string
    {
        $d = (array) (json_decode($datiJson, true) ?: []);
        $comune = trim((string) ($d['comune'] ?? ''));

        return $comune !== '' ? $azienda . ' – ' . $comune : $azienda;
    }

    /**
     * Le risposte di una pratica → la scelta dell'azienda, se c'è: ['valore' => scelta, 'nome_altra' => nome scritto a mano per «Altra azienda»].
     *
     * @param list<array<string, mixed>> $risposte
     * @return array{valore: string, nome_altra: string}|null
     */
    public static function scelta(array $risposte): ?array
    {
        $valore = '';
        foreach ($risposte as $r) {
            if (($r['tipo'] ?? '') === 'azienda_tesi' && trim((string) ($r['valore'] ?? '')) !== '' && empty($r['nascosto'])) {
                $valore = trim((string) $r['valore']);
            }
        }
        if ($valore === '') {
            return null;
        }
        $nome = '';
        foreach ($risposte as $r) {
            if (($r['tipo'] ?? '') === 'text' && str_contains(mb_strtolower((string) ($r['etichetta'] ?? '')), 'azienda') && str_contains(mb_strtolower((string) ($r['etichetta'] ?? '')), 'non convenzionata')) {
                $nome = trim((string) ($r['valore'] ?? ''));
            }
        }

        return ['valore' => $valore, 'nome_altra' => $nome];
    }

    /**
     * Lo stato della convenzione per quella scelta.
     *
     * @return array{esito: string, azienda: string, valida_al: string, protocollo: string, stato_in_corso: string}
     *   esito: valida | in_scadenza | scaduta | in_corso | da_chiedere | non_trovata
     */
    public function stato(string $valore, string $nomeAltra = '', ?int $praticaId = null): array
    {
        $oggi = $this->orologio->adesso();
        $base = ['esito' => 'non_trovata', 'azienda' => $valore, 'valida_al' => '', 'protocollo' => '', 'stato_in_corso' => ''];
        // La convenzione chiesta dallo studente dalla pratica («Invita l'azienda») vale più della ricerca per nome
        $legata = $praticaId !== null ? $this->collegata($praticaId) : null;
        if ($legata !== null) {
            if ((string) $legata['stato'] !== Costanti::PROTOCOLLATA) {
                return ['esito' => 'in_corso', 'azienda' => (string) $legata['azienda'], 'stato_in_corso' => (Costanti::ETICHETTE[(string) $legata['stato']][0] ?? (string) $legata['stato'])] + $base;
            }
            $al = (string) $legata['valida_al'];
            $esito = $al < $oggi->format('Y-m-d') ? 'scaduta' : ($al <= $oggi->modify('+' . self::GIORNI_TESI . ' days')->format('Y-m-d') ? 'in_scadenza' : 'valida');

            return ['esito' => $esito, 'azienda' => (string) $legata['azienda'], 'valida_al' => $al, 'protocollo' => (string) $legata['protocollo']] + $base;
        }
        if ($valore === Costanti::ALTRA_AZIENDA) {
            $base['azienda'] = $nomeAltra;
            if ($nomeAltra !== '') {
                $c = $this->db->riga("SELECT azienda, stato FROM convenzioni_tesi WHERE stato <> ? AND LOWER(azienda) LIKE ? ORDER BY id DESC LIMIT 1", [Costanti::PROTOCOLLATA, '%' . mb_strtolower($nomeAltra) . '%']);
                if ($c) {
                    return ['esito' => 'in_corso', 'azienda' => (string) $c['azienda'], 'stato_in_corso' => (Costanti::ETICHETTE[(string) $c['stato']][0] ?? (string) $c['stato'])] + $base;
                }
            }

            return ['esito' => 'da_chiedere'] + $base;
        }
        $trovata = null;
        foreach ($this->db->righe("SELECT azienda, dati_json, protocollo, valida_al FROM convenzioni_tesi WHERE stato = ? ORDER BY valida_al DESC", [Costanti::PROTOCOLLATA]) as $r) {
            if (self::etichetta((string) $r['azienda'], (string) $r['dati_json']) === $valore) {
                $trovata = $r;
                break;
            }
        }
        if ($trovata === null) {
            return $base;
        }
        $al = (string) $trovata['valida_al'];
        $esito = $al < $oggi->format('Y-m-d') ? 'scaduta' : ($al <= $oggi->modify('+' . self::GIORNI_TESI . ' days')->format('Y-m-d') ? 'in_scadenza' : 'valida');

        return ['esito' => $esito, 'azienda' => (string) $trovata['azienda'], 'valida_al' => $al, 'protocollo' => (string) $trovata['protocollo']] + $base;
    }

    /** @return array<string, mixed>|null la convenzione che lo studente ha chiesto per la pratica (pratiche.convenzione_tesi_id) */
    public function collegata(int $praticaId): ?array
    {
        return $this->db->riga('SELECT c.* FROM pratiche p JOIN convenzioni_tesi c ON c.id = p.convenzione_tesi_id WHERE p.id = ?', [$praticaId]);
    }

    /**
     * Il riquadro da mostrare nella pratica (HTML pronto), vuoto se la pratica non ha un'azienda scelta. Con $praticaId lo studente, se l'azienda non è
     * convenzionata, trova il modulo «Invita l'azienda»; se l'ha già invitata ne vede l'avanzamento.
     *
     * @param list<array<string, mixed>> $risposte
     */
    public function riquadro(array $risposte, bool $perUfficio, ?int $praticaId = null): string
    {
        $s = self::scelta($risposte);
        if ($s === null) {
            return '';
        }
        $st = $this->stato($s['valore'], $s['nome_altra'], $praticaId);
        $h = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        $d = static fn (string $x): string => date('d/m/Y', (int) strtotime($x));
        $richiesta = 'richiedi_convenzione_tesi.php';
        [$colore, $icona, $titolo, $testo] = match ($st['esito']) {
            'valida' => ['#15803d', 'fa-circle-check', 'Azienda convenzionata', '<strong>' . $h($st['azienda']) . '</strong>: convenzione di tesi valida fino al ' . $d($st['valida_al']) . ' (protocollo ' . $h($st['protocollo']) . ').'],
            'in_scadenza' => ['#b45309', 'fa-triangle-exclamation', 'Convenzione in scadenza', '<strong>' . $h($st['azienda']) . '</strong>: la convenzione scade il ' . $d($st['valida_al']) . ' (protocollo ' . $h($st['protocollo']) . '). '
                . ($perUfficio ? 'Se la tesi dura oltre questa data va rinnovata (Didattica → Convenzioni di tesi → Archivio).' : 'Se la tesi dura oltre questa data la convenzione va rinnovata: l\'azienda riceve l\'avviso dal Dipartimento.')],
            'scaduta' => ['#b91c1c', 'fa-circle-xmark', 'Convenzione scaduta', '<strong>' . $h($st['azienda']) . '</strong>: la convenzione è scaduta il ' . $d($st['valida_al']) . '. '
                . ($perUfficio ? 'Va rinnovata prima dell\'inizio della tesi (Didattica → Convenzioni di tesi → Archivio → Rinnova).' : 'Va rinnovata prima di iniziare la tesi: chiedi all\'azienda di rinnovarla dal link che le ha mandato il Dipartimento.')],
            'in_corso' => ['#0369a1', 'fa-hourglass-half', 'Convenzione in corso', 'La convenzione con <strong>' . $h($st['azienda']) . '</strong> è in corso (' . $h($st['stato_in_corso']) . '): la tesi può cominciare quando è stipulata.'],
            'da_chiedere' => ['#b91c1c', 'fa-triangle-exclamation', 'Azienda non ancora convenzionata', 'Per svolgere la tesi presso ' . ($st['azienda'] !== '' ? '<strong>' . $h($st['azienda']) . '</strong>' : 'questa azienda') . ' serve prima la <strong>convenzione con il Dipartimento</strong>. '
                . ($perUfficio ? 'L\'azienda può chiederla dalla pagina pubblica (' . $h($richiesta) . ') o si invita da Didattica → Convenzioni di tesi.' : 'L\'azienda può chiederla online, senza registrarsi, da <a href="' . $richiesta . '">«Convenzione di tesi per enti e aziende»</a>: ci vogliono pochi minuti.')],
            default => ['#475569', 'fa-circle-info', 'Azienda non trovata tra le convenzionate', 'L\'azienda scelta (<strong>' . $h($st['azienda']) . '</strong>) non risulta più tra quelle convenzionate: ' . ($perUfficio ? 'controlla l\'archivio delle convenzioni di tesi.' : 'scrivi all\'ufficio.')],
        };

        $invito = $st['esito'] === 'da_chiedere' && !$perUfficio && $praticaId !== null ? $this->moduloInvito($praticaId, $st['azienda']) : '';

        return '<div class="card border-0 shadow-sm mb-3" style="border-left:5px solid ' . $colore . ' !important;"><div class="card-body"><h2 class="h6 fw-bold mb-1"><i class="fa ' . $icona . ' me-1" style="color:' . $colore . ';" aria-hidden="true"></i>'
            . $h($titolo) . '</h2><div class="small mb-0">' . $testo . '</div>' . $invito . '</div></div>';
    }

    /** Il modulo «Invita l'azienda» per lo studente (nome e email del referente), con il token CSRF della sessione. */
    private function moduloInvito(int $praticaId, string $nomeAzienda): string
    {
        $h = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        $csrf = function_exists('csrf_token') ? csrf_token() : '';

        return '<form method="post" action="invita_azienda.php" class="mt-3 border-top pt-3"><input type="hidden" name="csrf_token" value="' . $h((string) $csrf) . '"><input type="hidden" name="pratica" value="' . $praticaId . '">'
            . '<div class="fw-semibold mb-1">Invita l\'azienda</div><div class="text-secondary mb-2">Scrivi l\'email del referente: riceve il link per compilare la convenzione online, senza registrarsi, e tu ne vedi l\'avanzamento qui.</div>'
            . '<div class="row g-2"><div class="col-md-5"><label class="form-label mb-0" for="inv-nome">Nome dell\'azienda</label><input class="form-control form-control-sm" id="inv-nome" name="nome" maxlength="200" required value="' . $h($nomeAzienda) . '"></div>'
            . '<div class="col-md-5"><label class="form-label mb-0" for="inv-email">Email del referente</label><input type="email" class="form-control form-control-sm" id="inv-email" name="email" maxlength="190" required></div>'
            . '<div class="col-md-2 d-flex align-items-end"><button type="submit" class="btn btn-sm btn-primary fw-bold w-100">Invita</button></div></div></form>';
    }
}
