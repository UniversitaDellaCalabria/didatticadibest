<?php

declare(strict_types=1);

namespace App\Didattica;

/** Le risposte di un modulo online lette dal modulo compilato (POST e allegati), con i controlli di ogni tipo di campo. */
final class LetturaRisposte
{
    public function __construct(private AllegatiPratiche $allegati, private ScelteAnagrafe $scelte)
    {
    }

    /**
     * Risposte dal POST (campo_<nome>) e allegati ($_FILES campo_<nome>). Ritorna [risposte, errori].
     * Le risposte: [['etichetta' => …, 'tipo' => …, 'valore' => …, 'file' => percorso|null, 'nome_file' => …, 'righe' => [[…]], 'colonne' => […],
     * 'meta' => […] (insegnamento scelto dal catalogo: corso, a.a., CFU, S.S.D.), 'nascosto' => true se la condizione del campo non è vera], ...]
     * I campi nascosti dalla logica non sono obbligatori e restano vuoti; quelli con un valore automatico lo prendono dal server.
     * $controllaCorsi: i corsi di studio devono essere tra quelli proposti dall'anagrafe.
     *
     * @param list<array<string, mixed>> $campi
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    public function leggi(array $campi, array $post, array $files, bool $controllaCorsi = true): array
    {
        $risposte = [];
        $errori = [];
        // Valori grezzi di tutti i campi, per valutare le condizioni (anche su campi che vengono dopo)
        $grezzi = [];
        foreach ($campi as $c) {
            $v = $post['campo_' . $c['nome']] ?? '';
            $grezzi[$c['nome']] = $c['tipo'] === 'checkbox' || $c['tipo'] === 'dichiarazione' ? (!empty($v) ? 'Sì' : '') : (is_array($v) ? implode(', ', array_filter(array_map(fn ($x) => is_array($x) ? '' : trim((string) $x), $v), static fn (string $x): bool => $x !== '')) : trim((string) $v));
        }
        $visibile = [];
        foreach ($campi as $c) {
            $vis = true;
            // Condizione su un campo che non è in questo modulo (es. istruttoria dell'ufficio su una risposta dello studente): non si applica
            if ($c['cond'] && array_key_exists($c['cond']['nome'], $grezzi)) {
                $vis = ($visibile[$c['cond']['nome']] ?? true) && CampiModulo::condizioneVera($c['cond'], (string) $grezzi[$c['cond']['nome']]);
            }
            $visibile[$c['nome']] = $vis;
            if ($c['auto'] && $vis && array_key_exists($c['auto']['nome'], $grezzi) && CampiModulo::condizioneVera($c['auto'], (string) $grezzi[$c['auto']['nome']])) {
                $grezzi[$c['nome']] = $c['auto']['imposta'];
            }
        }
        foreach ($campi as $c) {
            if (in_array($c['tipo'], Costanti::TIPI_SOLO_TESTO, true)) {
                continue;
            }
            $k = 'campo_' . $c['nome'];
            $r = ['etichetta' => $c['etichetta'], 'tipo' => $c['tipo'], 'valore' => '', 'file' => null, 'nome_file' => null];
            if (!$visibile[$c['nome']]) {
                $r['nascosto'] = true;
                if ($c['tipo'] === 'tabella') {
                    $r['colonne'] = $c['opzioni'];
                    $r['righe'] = [];
                }
                $risposte[] = $r;
                continue;
            }
            $auto = $c['auto'] && array_key_exists($c['auto']['nome'], $grezzi) && CampiModulo::condizioneVera($c['auto'], (string) $grezzi[$c['auto']['nome']]);
            if ($c['tipo'] === 'file') {
                $f = $files[$k] ?? null;
                if ($f && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $p = $this->allegati->salva($f);
                    if ($p) {
                        $r['file'] = $p;
                        $r['nome_file'] = mb_substr(basename((string) $f['name']), 0, 200);
                        $r['valore'] = $r['nome_file'];
                    } else {
                        $errori[] = $c['etichetta'] . ': allegato non valido (PDF, JPG o PNG fino a 10 MB)';
                    }
                }
            } elseif ($auto) {
                $r['valore'] = $c['auto']['imposta'];
            } elseif ($c['tipo'] === 'checkbox' || $c['tipo'] === 'dichiarazione') {
                $r['valore'] = !empty($post[$k]) ? 'Sì' : '';
            } elseif ($c['tipo'] === 'multicheck') {
                $scelte = array_values(array_intersect($c['opzioni'], array_map('strval', (array) ($post[$k] ?? []))));
                $r['valore'] = implode(', ', $scelte);
            } elseif ($c['tipo'] === 'tabella') {
                [$r['colonne'], $r['righe'], $r['valore']] = $this->tabella($c, $post[$k] ?? null);
            } else {
                $v = mb_substr(trim((string) ($post[$k] ?? '')), 0, in_array($c['tipo'], ['textarea'], true) ? 5000 : 500);
                if ($v !== '' && $c['tipo'] === 'email' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                    $errori[] = $c['etichetta'] . ': email non valida';
                }
                if ($v !== '' && $c['tipo'] === 'codice_fiscale') {
                    $v = strtoupper((string) preg_replace('/\s+/', '', $v));
                    if (!preg_match('/^[A-Z0-9]{16}$|^\d{11}$/', $v)) {
                        $errori[] = $c['etichetta'] . ': codice fiscale non valido';
                    }
                }
                if ($v !== '' && $c['tipo'] === 'url') {
                    if (!preg_match('#^https?://#i', $v)) {
                        $v = 'https://' . $v;
                    }
                    if (!filter_var($v, FILTER_VALIDATE_URL)) {
                        $errori[] = $c['etichetta'] . ': indirizzo web non valido';
                    }
                }
                if ($v !== '' && in_array($c['tipo'], ['select', 'radio'], true) && !in_array($v, $c['opzioni'], true)) {
                    $v = '';
                }
                if ($v !== '' && $c['tipo'] === 'date' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                    $v = '';
                }
                if ($v !== '' && $c['tipo'] === 'time' && !preg_match('/^\d{2}:\d{2}$/', $v)) {
                    $v = '';
                }
                if ($v !== '' && $c['tipo'] === 'number' && !is_numeric(str_replace(',', '.', $v))) {
                    $v = '';
                }
                if ($v !== '' && $c['tipo'] === 'anno_accademico' && !preg_match('/^\d{4}\/\d{4}$/', $v)) {
                    $v = '';
                }
                if ($v !== '' && $c['tipo'] === 'corso_studio' && $controllaCorsi && ($corsi = array_merge([], ...array_values($this->scelte->per('corso_studio')))) && !in_array($v, $corsi, true)) {
                    $v = '';
                }
                if ($c['tipo'] === 'insegnamento_ateneo' && $v !== '') {
                    // Scelto dal catalogo: corso, anno di offerta, CFU e S.S.D. (si riconoscono nel verbale e nelle convalide)
                    $meta = json_decode((string) ($post[$k . '_meta'] ?? ''), true);
                    if (is_array($meta) && trim((string) ($meta['nome'] ?? '')) !== '') {
                        $r['meta'] = ['id' => (int) ($meta['id'] ?? 0), 'nome' => mb_substr((string) $meta['nome'], 0, 255), 'corso' => mb_substr((string) ($meta['corso'] ?? ''), 0, 255),
                                      'aa' => mb_substr((string) ($meta['aa'] ?? ''), 0, 20), 'cfu' => is_numeric($meta['cfu'] ?? null) ? (float) $meta['cfu'] : null, 'ssd' => mb_substr((string) ($meta['ssd'] ?? ''), 0, 20)];
                    }
                }
                $r['valore'] = $v;
            }
            if ($c['obbligatorio'] && $r['valore'] === '') {
                $errori[] = $c['etichetta'] . ': ' . ($c['tipo'] === 'dichiarazione' ? 'devi accettare la dichiarazione' : 'campo obbligatorio');
            }
            $risposte[] = $r;
        }

        return [$risposte, $errori];
    }

    /**
     * Colonne in array paralleli (campo_cX[0][], campo_cX[1][], …): si tengono le righe non vuote (max 60).
     *
     * @param array<string, mixed> $c
     * @return array{0: list<string>, 1: list<list<string>>, 2: string} nomi delle colonne, righe, testo delle righe
     */
    private function tabella(array $c, mixed $dati): array
    {
        $cols = $c['colonne'] ?: CampiModulo::colonne($c['opzioni']);
        $righe = [];
        $dati = is_array($dati) ? $dati : [];
        $n = max(0, ...array_map(fn ($j) => is_array($dati[$j] ?? null) ? count($dati[$j]) : 0, array_keys($cols)));
        for ($i = 0; $i < min($n, 60); $i++) {
            $riga = [];
            foreach ($cols as $j => $col) {
                $v = mb_substr(trim((string) ($dati[$j][$i] ?? '')), 0, 300);
                if ($col['tipo'] === 'data' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
                    $v = "$m[3]/$m[2]/$m[1]";
                }
                if ($col['tipo'] === 'scelta' && $v !== '' && $col['scelte'] && !in_array($v, $col['scelte'], true)) {
                    $v = '';
                }
                if ($col['tipo'] === 'piano') {
                    [$si, $el] = CampiModulo::valorePiano($v);
                    $v = $si ? 'A scelta' . ($el !== '' ? '; elimina: ' . $el : '') : '';
                }
                $riga[] = $v;
            }
            // Una riga con la sola casella «a scelta» non è una riga compilata
            if (implode('', array_map(fn ($x, $col) => $col['tipo'] === 'piano' ? '' : $x, $riga, $cols)) !== '') {
                $righe[] = $riga;
            }
        }

        return [array_column($cols, 'nome'), $righe, implode("\n", array_map(fn ($x) => implode(' | ', $x), $righe))];
    }
}
