<?php

declare(strict_types=1);

namespace App\Anagrafi;

use ZipArchive;

/**
 * Anagrafe delle scuole (open data del Ministero): ricerca guidata per il campo "Scuola" dei moduli, nome ufficiale
 * delle scuole scelte, caricamento del file del Ministero e abbinamento delle scuole scritte a mano (pannello "Anagrafe scuole").
 */
final class ServizioScuole
{
    /** Regioni selezionabili all'importazione: nome => inizio del nome nel file del Ministero (solo lettere, maiuscolo) */
    public const REGIONI = [
        'Abruzzo' => 'ABRUZZO', 'Basilicata' => 'BASILICATA', 'Calabria' => 'CALABRIA', 'Campania' => 'CAMPANIA',
        'Emilia-Romagna' => 'EMILIA', 'Friuli Venezia Giulia' => 'FRIULI', 'Lazio' => 'LAZIO', 'Liguria' => 'LIGURIA',
        'Lombardia' => 'LOMBARDIA', 'Marche' => 'MARCHE', 'Molise' => 'MOLISE', 'Piemonte' => 'PIEMONTE', 'Puglia' => 'PUGLIA',
        'Sardegna' => 'SARDEGNA', 'Sicilia' => 'SICILIA', 'Toscana' => 'TOSCANA', 'Trentino-Alto Adige' => 'TRENTINO',
        'Umbria' => 'UMBRIA', "Valle d'Aosta" => 'VALLE', 'Veneto' => 'VENETO',
    ];

    /** Colonne del file del Ministero (nomi in maiuscolo, senza spazi) per ogni colonna della tabella */
    public const MAPPA_COLONNE = [
        'codice'                 => ['CODICESCUOLA'],
        'denominazione'          => ['DENOMINAZIONESCUOLA'],
        'istituto_codice'        => ['CODICEISTITUTORIFERIMENTO'],
        'istituto_denominazione' => ['DENOMINAZIONEISTITUTORIFERIMENTO'],
        'tipo'                   => ['DESCRIZIONETIPOLOGIAGRADOISTRUZIONESCUOLA', 'TIPOLOGIAGRADOISTRUZIONESCUOLA'],
        'comune'                 => ['DESCRIZIONECOMUNE', 'COMUNE'],
        'provincia'              => ['PROVINCIA'],
        'regione'                => ['REGIONE'],
        'indirizzo'              => ['INDIRIZZOSCUOLA', 'INDIRIZZO'],
        'cap'                    => ['CAPSCUOLA', 'CAP'],
        'email'                  => ['INDIRIZZOEMAILSCUOLA', 'EMAIL'],
        'pec'                    => ['INDIRIZZOPECSCUOLA', 'PEC'],
        'anno_scolastico'        => ['ANNOSCOLASTICO'],
    ];

    /** @var array<string, array<string, mixed>|null> scuole già lette, per codice */
    private array $perCodice = [];

    public function __construct(private ScuolaRepository $scuole)
    {
    }

    /** @return array<string, mixed>|null riga della scuola dal codice meccanografico (10 lettere/cifre) */
    public function perCodice(?string $codice): ?array
    {
        $codice = strtoupper(trim((string) $codice));
        if (!preg_match('/^[A-Z0-9]{10}$/', $codice)) {
            return null;
        }
        if (!array_key_exists($codice, $this->perCodice)) {
            $this->perCodice[$codice] = $this->scuole->perCodice($codice);
        }

        return $this->perCodice[$codice];
    }

    /**
     * Ricerca per parole (nome, comune, istituto o codice meccanografico): tutte le parole devono comparire.
     * Prima il codice esatto, poi le scuole della Calabria, poi le altre in ordine di nome. Con il comune o la provincia
     * la ricerca può anche essere vuota (tutte le scuole del luogo). Ogni scuola porta i periodi delle convenzioni ('conv').
     *
     * @param array<string, mixed> $filtri ['regione' => …, 'provincia' => …, 'comune' => …]
     * @return list<array<string, mixed>>
     */
    public function cerca(string $q, int $limite = 15, array $filtri = []): array
    {
        $q = trim((string) preg_replace('/\s+/u', ' ', $q));
        $parole = array_slice(array_values(array_filter(explode(' ', $q), static fn (string $p): bool => mb_strlen($p) >= 2)), 0, 6);
        $luogo = [];
        foreach (['regione', 'provincia', 'comune'] as $f) {
            $v = mb_strtoupper(trim((string) ($filtri[$f] ?? '')));
            if ($v !== '') {
                $luogo[$f] = $v;
            }
        }
        if (!$parole && empty($filtri['comune']) && empty($filtri['provincia'])) {
            return [];
        }
        $out = [];
        foreach ($this->scuole->cerca($luogo, $parole, strtoupper(str_replace(' ', '', $q)), $limite) as $s) {
            $out[] = ['codice' => $s['codice'], 'nome' => Testi::etichettaScuola($s), 'tipo' => Testi::maiuscoleScuola((string) $s['tipo']),
                      'comune' => Testi::maiuscoleScuola((string) $s['comune']), 'provincia' => Testi::maiuscoleScuola((string) $s['provincia']),
                      'istituto' => !empty($s['istituto_codice']) && $s['istituto_codice'] !== $s['codice'] ? Testi::maiuscoleScuola((string) $s['istituto_denominazione']) : ''];
        }
        // Convenzioni con il Dipartimento: periodi di validità [dal, al] ('' = senza limite); il modulo controlla se uno copre l'attività
        if ($out) {
            $conv = $this->scuole->convenzioni(array_map(static fn (array $x): string => (string) $x['codice'], $out));
            foreach ($out as &$o) {
                $o['conv'] = $conv[$o['codice']] ?? [];
            }
            unset($o);
        }

        return $out;
    }

    /**
     * Regioni, province di una regione o comuni di una provincia, con il numero di scuole:
     * [['valore' => 'COSENZA', 'nome' => 'Cosenza', 'n' => 412], …]
     *
     * @return list<array{valore: mixed, nome: string, n: int}>
     */
    public function luoghi(string $livello, string $regione = '', string $provincia = ''): array
    {
        $campo = ['regioni' => 'regione', 'province' => 'provincia', 'comuni' => 'comune'][$livello] ?? null;
        if ($campo === null) {
            return [];
        }
        $filtri = [];
        if ($campo !== 'regione') {
            $filtri['regione'] = mb_strtoupper($regione);
        }
        if ($campo === 'comune') {
            $filtri['provincia'] = mb_strtoupper($provincia);
        }

        return array_map(static fn (array $x): array => ['valore' => $x['v'], 'nome' => Testi::maiuscoleScuola((string) $x['v']), 'n' => (int) $x['n']], $this->scuole->luoghi($campo, $filtri));
    }

    /**
     * Moduli con il campo "Scuola": se è stata scelta una scuola dell'anagrafe (scuola_codice[nome_campo]), nel campo si
     * salva il nome ufficiale. Ritorna il codice meccanografico (il primo valido) o null se la scuola è scritta a mano.
     *
     * @param array<string, mixed> $datiCustom
     */
    public function applicaScelta(array &$datiCustom, mixed $codiciPost): ?string
    {
        $scelto = null;
        foreach ((array) $codiciPost as $campo => $codice) {
            $s = $this->perCodice(is_string($codice) ? $codice : '');
            if (!$s || !array_key_exists((string) $campo, $datiCustom)) {
                continue;
            }
            $datiCustom[(string) $campo] = Testi::etichettaScuola($s);
            if ($scelto === null) {
                $scelto = (string) $s['codice'];
            }
        }

        return $scelto;
    }

    /**
     * Nome della scuola di una prenotazione: quello ufficiale se scelta dall'anagrafe, altrimenti il primo campo del form
     * che parla di scuola/istituto.
     *
     * @param array<string, mixed>|null $pr
     */
    public function nomeDaPrenotazione(?array $pr): string
    {
        if (!empty($pr['scuola_codice']) && ($s = $this->perCodice((string) $pr['scuola_codice']))) {
            return Testi::etichettaScuola($s);
        }

        return self::nomeScrittoNelModulo($pr);
    }

    /**
     * Scuola scritta a mano nel modulo di una prenotazione (primo campo che parla di scuola/istituto), '' se non c'è.
     *
     * @param array<string, mixed>|null $pr
     */
    public static function nomeScrittoNelModulo(?array $pr): string
    {
        $custom = json_decode((string) ($pr['dati_custom_json'] ?? ''), true) ?: [];
        foreach ((array) $custom as $k => $val) {
            if (preg_match('/scuol|istitut/i', (string) $k) && is_string($val) && trim($val) !== '' && !preg_match('/^\d+$/', trim($val))) {
                return trim($val);
            }
        }

        return '';
    }

    // ---------------------------------------------------------------- pannello "Anagrafe scuole"

    /**
     * Legge il file caricato (CSV, o ZIP che contiene un CSV) e aggiorna la tabella.
     *
     * @param array<string, mixed> $file elemento di $_FILES
     * @param list<string> $regioni inizi del nome delle regioni da tenere (valori di REGIONI); [] = tutte
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int}|string [nuove, aggiornate, invariate, scartate, fuori regione] o il messaggio d'errore
     */
    public function importa(array $file, int $statale, array $regioni = []): array|string
    {
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            return 'Il file supera il limite di caricamento del server (' . ini_get('upload_max_filesize') . '): caricalo compresso in ZIP.';
        }
        if ($err !== UPLOAD_ERR_OK) {
            return "Caricamento non riuscito (codice $err).";
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $percorso = (string) $file['tmp_name'];
        $tempZip = null;
        if ($ext === 'zip') {
            if (!class_exists('ZipArchive')) {
                return 'Il server non può aprire i file ZIP: carica direttamente il CSV.';
            }
            $zip = new ZipArchive();
            if ($zip->open($percorso) !== true) {
                return 'File ZIP non leggibile.';
            }
            $nomeCsv = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $n = (string) $zip->getNameIndex($i);
                if (preg_match('/\.csv$/i', $n) && strpos($n, '__MACOSX') === false) {
                    $nomeCsv = $n;
                    break;
                }
            }
            if ($nomeCsv === null) {
                $zip->close();

                return "Nello ZIP non c'è un file CSV.";
            }
            $tempZip = (string) tempnam(sys_get_temp_dir(), 'scu');
            file_put_contents($tempZip, (string) $zip->getFromName($nomeCsv));
            $zip->close();
            $percorso = $tempZip;
        } elseif (!in_array($ext, ['csv', 'txt'], true)) {
            return 'Formato non supportato: carica il file CSV del Ministero (anche compresso in ZIP).';
        }
        try {
            return $this->importaCsv($percorso, $statale, $regioni);
        } finally {
            if ($tempZip !== null) {
                @unlink($tempZip);
            }
        }
    }

    /**
     * @param list<string> $regioni
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int}|string
     */
    private function importaCsv(string $percorso, int $statale, array $regioni): array|string
    {
        $fh = @fopen($percorso, 'r');
        if (!$fh) {
            return 'File non leggibile.';
        }
        $prima = (string) preg_replace('/^\xEF\xBB\xBF/', '', (string) fgets($fh));
        $separatori = [';' => substr_count($prima, ';'), ',' => substr_count($prima, ','), "\t" => substr_count($prima, "\t")];
        arsort($separatori);
        $sep = (string) array_key_first($separatori);
        $intestazioni = array_map(static fn ($c): string => strtoupper((string) preg_replace('/[\s"\']+/', '', (string) $c)), str_getcsv(trim($prima), $sep));
        $indici = [];
        foreach (self::MAPPA_COLONNE as $col => $nomi) {
            foreach ($nomi as $nm) {
                $pos = array_search($nm, $intestazioni, true);
                if ($pos !== false) {
                    $indici[$col] = $pos;
                    break;
                }
            }
        }
        if (!isset($indici['codice'], $indici['denominazione'])) {
            fclose($fh);

            return "File non riconosciuto: mancano le colonne CODICESCUOLA e DENOMINAZIONESCUOLA. Usa il file dell'anagrafe scuole del Ministero.";
        }

        @set_time_limit(600);
        $cols = ScuolaRepository::COLONNE_IMPORTAZIONE;
        $righe = [];
        $scartate = 0;
        $fuori = 0;
        while (($r = fgetcsv($fh, 0, $sep)) !== false) {
            // Codifica controllata riga per riga: le prime righe possono essere tutte in lettere semplici
            if (!mb_check_encoding(implode('', $r), 'UTF-8')) {
                $r = array_map(static fn ($v): string => (string) mb_convert_encoding((string) $v, 'UTF-8', 'Windows-1252'), $r);
            }
            $val = [];
            foreach ($cols as $c) {
                $val[] = isset($indici[$c]) ? mb_substr(trim((string) ($r[$indici[$c]] ?? '')), 0, 250) : '';
            }
            $val[0] = strtoupper($val[0]);
            if (!preg_match('/^[A-Z0-9]{10}$/', $val[0]) || $val[1] === '') {
                if (implode('', $r) !== '') {
                    $scartate++;
                }
                continue;
            }
            // Solo le scuole delle regioni scelte (es. CALABRIA): le altre righe si saltano
            if ($regioni) {
                $regRiga = (string) preg_replace('/[^A-Z]/', '', strtoupper((string) $val[7]));
                $okReg = false;
                foreach ($regioni as $pref) {
                    if (str_starts_with($regRiga, $pref)) {
                        $okReg = true;
                        break;
                    }
                }
                if (!$okReg) {
                    $fuori++;
                    continue;
                }
            }
            foreach ([2, 3] as $k) {
                if ($val[$k] === '' || strtoupper((string) $val[$k]) === 'NON DISPONIBILE') {
                    $val[$k] = null; // istituto di riferimento
                }
            }
            $righe[] = $val;
        }
        fclose($fh);
        [$inserite, $aggiornate, $invariate, $nonSalvate] = $this->scuole->importa($righe, $statale);

        return [$inserite, $aggiornate, $invariate, $scartate + $nonSalvate, $fuori];
    }

    /**
     * Scuole per regione (chiave: inizio del nome, come REGIONI).
     *
     * @return array<string, int>
     */
    public function perRegione(): array
    {
        $out = [];
        foreach ($this->scuole->perRegione() as $x) {
            $norm = (string) preg_replace('/[^A-Z]/', '', strtoupper((string) $x['regione']));
            foreach (self::REGIONI as $pref) {
                if ($norm !== '' && str_starts_with($norm, $pref)) {
                    $out[$pref] = ($out[$pref] ?? 0) + (int) $x['n'];
                    break;
                }
            }
        }

        return $out;
    }

    /** @return array<string, mixed> tot, statali, paritarie, calabria, agg, anno */
    public function stato(): array
    {
        return $this->scuole->stato();
    }

    public function iscrizioniConCodice(): int
    {
        return $this->scuole->iscrizioniConCodice();
    }

    /**
     * Tiene solo le scuole della Calabria (e quelle già scelte in iscrizioni e profili).
     *
     * @return array{0: int, 1: bool} [quante ne toglie, ce n'erano di già scelte]
     */
    public function tieniSoloCalabria(): array
    {
        return $this->scuole->tieniSoloCalabria();
    }

    /**
     * Scuole scritte a mano nelle iscrizioni passate (senza codice, o con un codice non più in anagrafe), raggruppate per testo:
     * chiave (testo in minuscolo) => ['testo' => …, 'ids' => [id iscrizioni]], dal gruppo più numeroso.
     *
     * @return array<string, array{testo: string, ids: list<int>}>
     */
    public function daAbbinare(): array
    {
        $gruppi = [];
        // Il campo della scuola si riconosce in PHP: un LIKE in SQL dipenderebbe da maiuscole e collation del server
        foreach ($this->scuole->iscrizioniConDati() as $p) {
            if (!empty($p['scuola_codice']) && $this->perCodice((string) $p['scuola_codice'])) {
                continue;
            }
            $nome = self::nomeScrittoNelModulo($p);
            if ($nome === '') {
                continue;
            }
            $chiave = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $nome)));
            $gruppi[$chiave]['testo'] ??= $nome;
            $gruppi[$chiave]['ids'][] = (int) $p['id'];
        }
        uasort($gruppi, static fn (array $a, array $b): int => count($b['ids']) <=> count($a['ids']));

        return $gruppi;
    }

    /**
     * Scuole più simili a un nome scritto a mano: parole significative, poi via via meno parole.
     *
     * @return list<array<string, mixed>>
     */
    public function suggerisci(string $testo): array
    {
        $vuote = ['di', 'del', 'della', 'dei', 'de', 'e', 'ed', 'la', 'il', 'lo', 'le', 'gli', 'a', 'da', 'in', 'per', 'statale', 'istituto', 'scuola', 'superiore', 'secondaria', 'grado', 'primo', 'secondo', 'ist', 'sc', 'sede', 'via'];
        $parole = array_values(array_filter(preg_split('/[\s,;.\-–"\'()\/]+/u', mb_strtolower($testo)) ?: [], static fn (string $w): bool => mb_strlen($w) >= 3 && !in_array($w, $vuote, true)));
        $parole = array_slice($parole, 0, 5);
        while ($parole) {
            $ris = $this->cerca(implode(' ', $parole), 5);
            if ($ris) {
                return $ris;
            }
            array_pop($parole);
        }

        return [];
    }

    /**
     * Collega alla scuola $codice le iscrizioni del gruppo $chiave di daAbbinare().
     *
     * @return array{0: string, 1: string, 2: array<string, mixed>|null} [esito: ok|scuola|gruppo, testo del gruppo, scuola], con il numero di iscrizioni in 'iscrizioni'
     */
    public function abbina(string $codice, string $chiave): array
    {
        $s = $this->perCodice($codice);
        if (!$s) {
            return ['scuola', '', null];
        }
        $gruppi = $this->daAbbinare();
        if (!isset($gruppi[$chiave])) {
            return ['gruppo', '', $s];
        }
        $n = $this->scuole->abbinaIscrizioni($gruppi[$chiave]['ids'], (string) $s['codice']);

        return ['ok', $gruppi[$chiave]['testo'], $s + ['iscrizioni' => $n]];
    }

    /**
     * Scuole collegate a iscrizioni e profili: per ogni codice iscrizioni, attività, ultima iscrizione e docenti,
     * dalla scuola con più iscrizioni.
     *
     * @return array<string, array<string, mixed>>
     */
    public function collegate(): array
    {
        /** @var array<string, array<string, mixed>> $collegate costruito per riferimento: PHPStan non ne segue le chiavi */
        $collegate = [];
        foreach ($this->scuole->iscrizioniCollegate() as $x) {
            $c = &$collegate[$x['scuola_codice']];
            $c['iscrizioni'] = ($c['iscrizioni'] ?? 0) + 1;
            $c['attivita'][$x['titolo']] = true;
            $c['ultima'] = max($c['ultima'] ?? '', (string) $x['data_prenotazione']);
            $k = $x['email'] ?: mb_strtolower($x['nome'] . ' ' . $x['cognome']);
            if (!isset($c['docenti'][$k])) {
                $c['docenti'][$k] = ['nome' => trim($x['nome'] . ' ' . $x['cognome']), 'email' => (string) $x['email'], 'n' => 0];
            }
            $c['docenti'][$k]['n']++;
            unset($c);
        }
        foreach ($this->scuole->profiliCollegati() as $x) {
            $k = $x['email'] ?: mb_strtolower($x['nome'] . ' ' . $x['cognome']);
            if (!isset($collegate[$x['scuola_codice']]['docenti'][$k])) {
                $collegate[$x['scuola_codice']]['docenti'][$k] = ['nome' => trim($x['nome'] . ' ' . $x['cognome']), 'email' => (string) $x['email'], 'n' => 0];
            }
            $collegate[$x['scuola_codice']]['docenti'][$k]['profilo'] = true;
        }
        uasort($collegate, static fn ($a, $b): int => ($b['iscrizioni'] ?? 0) <=> ($a['iscrizioni'] ?? 0));

        return $collegate;
    }
}
