<?php

declare(strict_types=1);

namespace App\Risorse;

use App\Didattica\CampiModulo;
use App\Didattica\LetturaRisposte;
use App\Infrastructure\Documenti\Excel;

/**
 * Il diario di bordo di auto e mezzi. L'admin lo attiva per risorsa e ne decide i campi (di partenza: data, conducente, partenza e arrivo, ore, km
 * iniziali e finali, carburante, segnalazioni); alcuni campi hanno un ruolo (km iniziali e finali, litri…) che serve ai controlli e ai totali anche se
 * vengono rinominati. Lo compila chi ha prenotato, un altro conducente da lui indicato o un responsabile della risorsa.
 */
final class ServizioDiarioBordo
{
    /** Ruoli dei campi: chiave => nome mostrato nel pannello. */
    public const RUOLI = ['data' => 'Data del viaggio', 'conducente' => 'Conducente', 'ora_partenza' => 'Ora di partenza', 'ora_arrivo' => 'Ora di arrivo',
        'km_iniziali' => 'Km iniziali', 'km_finali' => 'Km finali', 'litri' => 'Carburante (litri)'];

    /** I campi di partenza (dal modulo cartaceo) con i loro ruoli. */
    public const PREDEFINITO = [
        'campi' => [
            ['etichetta' => 'Data', 'tipo' => 'date', 'obbligatorio' => true],
            ['etichetta' => 'Nome e cognome del conducente', 'tipo' => 'text', 'obbligatorio' => true],
            ['etichetta' => 'Luogo di partenza', 'tipo' => 'text', 'obbligatorio' => true],
            ['etichetta' => 'Luogo di arrivo', 'tipo' => 'text', 'obbligatorio' => true],
            ['etichetta' => 'Ora di partenza', 'tipo' => 'time', 'obbligatorio' => true],
            ['etichetta' => 'Ora di arrivo', 'tipo' => 'time', 'obbligatorio' => true],
            ['etichetta' => 'Km iniziali', 'tipo' => 'number', 'obbligatorio' => true, 'aiuto' => 'Dal contachilometri, prima di partire'],
            ['etichetta' => 'Km finali', 'tipo' => 'number', 'obbligatorio' => true, 'aiuto' => 'Dal contachilometri, all\'arrivo'],
            ['etichetta' => 'Rifornimento di carburante (litri)', 'tipo' => 'number', 'obbligatorio' => false, 'aiuto' => 'Solo se hai fatto rifornimento'],
            ['etichetta' => 'Segnalazioni e osservazioni', 'tipo' => 'textarea', 'obbligatorio' => false],
        ],
        'ruoli' => ['data' => 'Data', 'conducente' => 'Nome e cognome del conducente', 'ora_partenza' => 'Ora di partenza', 'ora_arrivo' => 'Ora di arrivo',
            'km_iniziali' => 'Km iniziali', 'km_finali' => 'Km finali', 'litri' => 'Rifornimento di carburante (litri)'],
    ];

    public function __construct(
        private DiarioBordoRepository $diario,
        private RisorsaRepository $risorse,
        private ResponsabileRisorsaRepository $responsabili,
        private LetturaRisposte $lettura
    ) {
    }

    /**
     * I campi e i ruoli del diario della risorsa (quelli di partenza se l'admin non li ha cambiati).
     *
     * @param array<string, mixed> $risorsa
     * @return array{campi: list<array<string, mixed>>, ruoli: array<string, string>}
     */
    public function configurazione(array $risorsa): array
    {
        $j = json_decode((string) ($risorsa['diario_json'] ?? ''), true);

        return is_array($j) && !empty($j['campi']) ? ['campi' => $j['campi'], 'ruoli' => (array) ($j['ruoli'] ?? [])] : self::PREDEFINITO;
    }

    /**
     * I campi normalizzati (nome «cN», opzioni…) con il ruolo di ognuno (nome del campo => ruolo).
     *
     * @param array<string, mixed> $risorsa
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>}
     */
    public function campi(array $risorsa): array
    {
        $conf = $this->configurazione($risorsa);
        $campi = CampiModulo::da((string) json_encode($conf['campi'], JSON_UNESCAPED_UNICODE));
        $perEtichetta = [];
        foreach ($campi as $c) {
            $perEtichetta[mb_strtolower($c['etichetta'])] = $c['nome'];
        }
        $ruoli = [];
        foreach ($conf['ruoli'] as $ruolo => $etichetta) {
            if (isset(self::RUOLI[$ruolo]) && isset($perEtichetta[mb_strtolower((string) $etichetta)])) {
                $ruoli[$perEtichetta[mb_strtolower((string) $etichetta)]] = (string) $ruolo;
            }
        }

        return [$campi, $ruoli];
    }

    /**
     * Salva la configurazione dal modulo del pannello: attivazione, blocco e campi (righe c_etichetta[], c_tipo[], c_opzioni[], c_obbl[], c_aiuto[]) con i ruoli
     * (ruolo_<chiave> = etichetta del campo). Senza campi valgono quelli di partenza.
     *
     * @param array<string, mixed> $post
     */
    public function salvaConfigurazione(int $risorsaId, array $post, ServizioCampiRisorsa $campiSvc): void
    {
        $campi = json_decode((string) $campiSvc->jsonDaForm($post), true) ?: [];
        $json = null;
        if ($campi) {
            $etichette = array_column($campi, 'etichetta');
            $ruoli = [];
            foreach (array_keys(self::RUOLI) as $ruolo) {
                $e = (string) ($post['ruolo_' . $ruolo] ?? '');
                if ($e !== '' && in_array($e, $etichette, true)) {
                    $ruoli[$ruolo] = $e;
                }
            }
            $json = (string) json_encode(['campi' => $campi, 'ruoli' => $ruoli], JSON_UNESCAPED_UNICODE);
        }
        $this->risorse->impostaDiario($risorsaId, !empty($post['diario_attivo']), !empty($post['diario_blocca']), $json);
    }

    /**
     * Gli altri conducenti indicati da chi prenota (campo «conducenti[]»): ogni nome si riconosce nell'anagrafe se è uno solo, altrimenti resta scritto.
     * Ritorna il JSON [{nome, persona_id, email}] o null.
     *
     * @param array<string, mixed> $post
     */
    public function coConducentiDaForm(array $post): ?string
    {
        $out = [];
        foreach ((array) ($post['conducenti'] ?? []) as $nome) {
            $nome = mb_substr(trim((string) $nome), 0, 150);
            if ($nome === '' || count($out) >= 5) {
                continue;
            }
            $p = $this->diario->personaPerNome($nome);
            $email = $p && filter_var($p['email'] ?? '', FILTER_VALIDATE_EMAIL) ? strtolower((string) $p['email']) : '';
            $out[] = ['nome' => $p ? trim($p['cognome'] . ' ' . $p['nome']) : $nome, 'persona_id' => $p['id'] ?? null, 'email' => $email];
        }

        return $out ? (string) json_encode($out, JSON_UNESCAPED_UNICODE) : null;
    }

    public function impostaCoConducenti(int $prenotazioneId, ?string $json): void
    {
        $this->diario->impostaCoConducenti($prenotazioneId, $json);
    }

    /** @return list<string> nominativi del personale, per il suggerimento dei co-conducenti */
    public function nominativi(): array
    {
        return $this->diario->nominativiPersonale();
    }

    /** @return list<array{nome: string, persona_id: ?string, email: string}> */
    public static function coConducenti(array $prenotazione): array
    {
        return array_values(array_filter((array) json_decode((string) ($prenotazione['co_conducenti_json'] ?? ''), true), 'is_array'));
    }

    /** L'utente può compilare o correggere il diario della prenotazione: chi ha prenotato, un altro conducente indicato (per email) o un responsabile della risorsa. */
    public function puoCompilare(array $prenotazione, ?array $utente): bool
    {
        if (!$utente || empty($utente['id'])) {
            return false;
        }
        if ((int) ($prenotazione['utente_id'] ?? 0) === (int) $utente['id']) {
            return true;
        }
        $email = strtolower(trim((string) ($utente['email'] ?? '')));
        if ($email === '') {
            return false;
        }
        foreach (self::coConducenti($prenotazione) as $c) {
            if (($c['email'] ?? '') === $email) {
                return true;
            }
        }

        return $this->responsabili->perEmail((int) $prenotazione['risorsa_id'], $email) !== null;
    }

    /** L'utente è responsabile della risorsa (può anche verificare il diario). */
    public function eResponsabile(array $prenotazione, ?array $utente): bool
    {
        $email = strtolower(trim((string) ($utente['email'] ?? '')));

        return $email !== '' && !empty($utente['id']) && $this->responsabili->perEmail((int) $prenotazione['risorsa_id'], $email) !== null;
    }

    /** @return array<string, mixed>|null */
    public function diarioDi(int $prenotazioneId): ?array
    {
        return $this->diario->perPrenotazione($prenotazioneId);
    }

    /**
     * I valori da mostrare nel modulo (nome del campo => valore): quelli salvati, oppure per un diario nuovo quelli ricavati dalla prenotazione
     * (data, conducente, ore) e i km iniziali proposti dall'ultimo viaggio.
     *
     * @param array<string, mixed> $risorsa
     * @param array<string, mixed> $prenotazione
     * @return array<string, string>
     */
    public function valoriIniziali(array $risorsa, array $prenotazione): array
    {
        [$campi, $ruoli] = $this->campi($risorsa);
        $perRuolo = array_flip($ruoli);
        $v = [];
        if ($d = $this->diario->perPrenotazione((int) $prenotazione['id'])) {
            foreach (json_decode((string) ($d['valori_json'] ?? ''), true) ?: [] as $r) {
                foreach ($campi as $c) {
                    if (mb_strtolower($c['etichetta']) === mb_strtolower((string) ($r['etichetta'] ?? ''))) {
                        $v[$c['nome']] = is_array($r['valore'] ?? '') ? implode(', ', $r['valore']) : (string) ($r['valore'] ?? '');
                    }
                }
            }

            return $v;
        }
        $imposta = function (string $ruolo, string $valore) use (&$v, $perRuolo): void {
            if ($valore !== '' && isset($perRuolo[$ruolo])) {
                $v[$perRuolo[$ruolo]] = $valore;
            }
        };
        $imposta('data', date('Y-m-d', (int) strtotime((string) $prenotazione['inizio'])));
        $imposta('conducente', trim($prenotazione['nome'] . ' ' . $prenotazione['cognome']));
        $imposta('ora_partenza', date('H:i', (int) strtotime((string) $prenotazione['inizio'])));
        $imposta('ora_arrivo', date('H:i', (int) strtotime((string) $prenotazione['fine'])));
        $ultimi = $this->diario->ultimiKm((int) $prenotazione['risorsa_id'], date('Y-m-d', (int) strtotime((string) $prenotazione['inizio'])), (int) $prenotazione['id']);
        $imposta('km_iniziali', $ultimi !== null ? (string) $ultimi : '');

        return $v;
    }

    /** I km finali dell'ultimo viaggio registrato prima della prenotazione (per il suggerimento accanto al campo). */
    public function ultimiKm(array $prenotazione): ?int
    {
        return $this->diario->ultimiKm((int) $prenotazione['risorsa_id'], date('Y-m-d', (int) strtotime((string) $prenotazione['inizio'])), (int) $prenotazione['id']);
    }

    /**
     * Salva il diario letto dal modulo (POST campo_cN). Ritorna l'errore, null se salvato.
     *
     * @param array<string, mixed> $prenotazione
     * @param array<string, mixed>|null $utente
     * @param array<string, mixed> $post
     */
    public function salva(array $prenotazione, ?array $utente, array $post): ?string
    {
        $r = $this->risorse->perId((int) $prenotazione['risorsa_id']);
        if (!$r || !(int) $r['diario_attivo']) {
            return 'Questa risorsa non ha il diario di bordo.';
        }
        if (!$this->puoCompilare($prenotazione, $utente)) {
            return 'Non puoi compilare il diario di questa prenotazione.';
        }
        [$campi, $ruoli] = $this->campi($r);
        [$risposte, $errori] = $this->lettura->leggi($campi, $post, [], false);
        if ($errori) {
            return implode(' ', $errori);
        }
        $nomePerEtichetta = [];
        foreach ($campi as $c) {
            $nomePerEtichetta[mb_strtolower($c['etichetta'])] = $c['nome'];
        }
        $perRuolo = [];
        foreach ($risposte as $ris) {
            $nome = $nomePerEtichetta[mb_strtolower((string) ($ris['etichetta'] ?? ''))] ?? '';
            if (isset($ruoli[$nome])) {
                $perRuolo[$ruoli[$nome]] = trim((string) ($ris['valore'] ?? ''));
            }
        }
        $num = static fn (?string $s): ?float => $s !== null && $s !== '' && is_numeric(str_replace(',', '.', $s)) ? (float) str_replace(',', '.', $s) : null;
        $kmi = $num($perRuolo['km_iniziali'] ?? null);
        $kmf = $num($perRuolo['km_finali'] ?? null);
        $litri = $num($perRuolo['litri'] ?? null);
        if (($kmi !== null && $kmi < 0) || ($kmf !== null && $kmf < 0) || ($litri !== null && $litri < 0)) {
            return 'Km e litri non possono essere negativi.';
        }
        if ($kmi !== null && $kmf !== null && $kmf < $kmi) {
            return 'I km finali non possono essere inferiori ai km iniziali.';
        }
        $data = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($perRuolo['data'] ?? '')) ? $perRuolo['data'] : date('Y-m-d', (int) strtotime((string) $prenotazione['inizio']));
        $ultimi = $this->diario->ultimiKm((int) $r['id'], $data, (int) $prenotazione['id']);
        if ($kmi !== null && $ultimi !== null && (int) round($kmi) < $ultimi) {
            return "I km iniziali ($kmi) sono inferiori agli ultimi km finali registrati ($ultimi): controlla il contachilometri.";
        }
        $chi = trim((string) ($utente['nome'] ?? '') . ' ' . (string) ($utente['cognome'] ?? ''));
        $this->diario->salva((int) $r['id'], (int) $prenotazione['id'], [
            'data' => $data, 'conducente' => mb_substr($perRuolo['conducente'] ?? '', 0, 200) ?: trim($prenotazione['nome'] . ' ' . $prenotazione['cognome']),
            'km_iniziali' => $kmi !== null ? (int) round($kmi) : null, 'km_finali' => $kmf !== null ? (int) round($kmf) : null,
            'km_percorsi' => $kmi !== null && $kmf !== null ? (int) round($kmf - $kmi) : null, 'litri' => $litri, 'valori_json' => (string) json_encode($risposte, JSON_UNESCAPED_UNICODE), 'compilato_da' => $chi,
        ]);

        return null;
    }

    public function verifica(array $prenotazione, ?array $utente): ?string
    {
        $d = $this->diario->perPrenotazione((int) $prenotazione['id']);
        if (!$d || !$this->eResponsabile($prenotazione, $utente)) {
            return 'Solo un responsabile della risorsa può verificare un diario compilato.';
        }
        $this->diario->verifica((int) $d['id'], trim(($utente['nome'] ?? '') . ' ' . ($utente['cognome'] ?? '')));

        return null;
    }

    /**
     * Prenotazioni concluse senza diario di cui l'utente è responsabile come prenotatore o co-conducente.
     *
     * @param array<string, mixed>|null $utente
     * @return list<array<string, string|null>>
     */
    public function daCompilare(?array $utente): array
    {
        return $utente && !empty($utente['id']) ? $this->diario->senzaDiario((int) $utente['id'], (string) ($utente['email'] ?? '')) : [];
    }

    /** @return list<array<string, string|null>> prenotazioni concluse della risorsa senza diario (per il responsabile) */
    public function mancanti(int $risorsaId): array
    {
        return $this->diario->senzaDiario(null, null, $risorsaId);
    }

    /**
     * Il diario della risorsa e i suoi totali (per il responsabile).
     *
     * @return array{righe: list<array<string, string|null>>, totali: array{viaggi: int, km: int, litri: float}}
     */
    public function registro(int $risorsaId, ?string $dal = null, ?string $al = null): array
    {
        return ['righe' => $this->diario->elenco($risorsaId, $dal, $al), 'totali' => $this->diario->totali($risorsaId, $dal, $al)];
    }

    /** File .xlsx con il diario della risorsa (tutti i campi, km percorsi, chi l'ha compilato e verificato) e il riepilogo per mese. Percorso temporaneo o null. */
    public function esporta(array $risorsa, ?string $dal = null, ?string $al = null): ?string
    {
        [$campi] = $this->campi($risorsa);
        $righe = array_reverse($this->diario->elenco((int) $risorsa['id'], $dal, $al, 5000));
        $intest = ['Data', 'Prenotazione'];
        foreach ($campi as $c) {
            $intest[] = $c['etichetta'];
        }
        array_push($intest, 'Km percorsi', 'Compilato da', 'Verificato da');
        $out = [];
        $mesi = [];
        foreach ($righe as $d) {
            $val = [];
            foreach (json_decode((string) ($d['valori_json'] ?? ''), true) ?: [] as $r) {
                $val[mb_strtolower((string) ($r['etichetta'] ?? ''))] = is_array($r['valore'] ?? '') ? implode(', ', $r['valore']) : (string) ($r['valore'] ?? '');
            }
            $riga = [$d['data'] ? date('d/m/Y', (int) strtotime((string) $d['data'])) : '', $d['codice']];
            foreach ($campi as $c) {
                $riga[] = $val[mb_strtolower($c['etichetta'])] ?? '';
            }
            array_push($riga, $d['km_percorsi'] ?? '', $d['compilato_da'], $d['verificato_da']);
            $out[] = $riga;
            $m = $d['data'] ? date('Y-m', (int) strtotime((string) $d['data'])) : 'senza data';
            $mesi[$m]['viaggi'] = ($mesi[$m]['viaggi'] ?? 0) + 1;
            $mesi[$m]['km'] = ($mesi[$m]['km'] ?? 0) + (int) $d['km_percorsi'];
            $mesi[$m]['litri'] = ($mesi[$m]['litri'] ?? 0) + (float) $d['litri'];
        }
        $riepilogo = [];
        foreach ($mesi as $m => $t) {
            $riepilogo[] = [$m, $t['viaggi'], $t['km'], round($t['litri'], 2), $t['litri'] > 0 ? round($t['km'] / $t['litri'], 1) : ''];
        }

        return Excel::crea([
            'Diario di bordo' => ['intestazioni' => $intest, 'righe' => $out, 'larghezze' => array_merge([12, 16], array_fill(0, count($campi), 22), [12, 22, 22])],
            'Riepilogo per mese' => ['intestazioni' => ['Mese', 'Viaggi', 'Km percorsi', 'Litri', 'Km per litro'], 'righe' => $riepilogo, 'larghezze' => [14, 10, 14, 10, 14]],
        ]);
    }
}
