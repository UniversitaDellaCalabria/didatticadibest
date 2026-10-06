<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Orologio;
use App\Core\Sito;
use App\Infrastructure\Storage\Upload;
use App\Risorse\ServizioRisorse;

/** Moduli della didattica: chi li può compilare, quando sono aperti, e il salvataggio dal costruttore del pannello. */
final class ServizioModuli
{
    public function __construct(
        private ModuloRepository $moduli,
        private UfficioRepository $uffici,
        private ServizioUffici $ufficio,
        private ServizioRisorse $risorse,
        private Upload $upload,
        private Sito $sito,
        private Orologio $orologio
    ) {
    }

    /** @return array<string, mixed>|null */
    public function modulo(int $id): ?array
    {
        return $this->moduli->perId($id);
    }

    /**
     * Chi può compilare il modulo online (stesse regole delle risorse: gruppi dell'anagrafe e matricola).
     *
     * @param array<string, mixed> $m
     * @param array<string, mixed>|null $u
     */
    public function destinatario(array $m, ?array $u): bool
    {
        if (!$u || empty($u['id'])) {
            return false;
        }
        if (($m['destinatari'] ?? 'tutti') === 'tutti' || $this->ufficio->gestisce($u)) {
            return true;
        }

        return $this->risorse->puoPrenotare(['accesso' => $m['destinatari'], 'pagina_id' => 0], $u);
    }

    /**
     * Il modulo online si compila solo nel periodo aperto_dal–aperto_al (vuoti = sempre). Ritorna [aperto, testo da mostrare].
     *
     * @param array<string, mixed> $m
     * @return array{0: bool, 1: string}
     */
    public function periodo(array $m): array
    {
        $oggi = $this->orologio->adesso()->format('Y-m-d');
        $d = fn ($x) => date('d/m/Y', strtotime($x));
        $dal = $m['aperto_dal'] ?? null;
        $al = $m['aperto_al'] ?? null;
        if ($dal && $oggi < $dal) {
            return [false, 'Si compila dal ' . $d($dal) . ($al ? ' al ' . $d($al) : '')];
        }
        if ($al && $oggi > $al) {
            return [false, 'Chiuso il ' . $d($al)];
        }
        if ($al) {
            return [true, 'Aperto fino al ' . $d($al)];
        }

        return [true, ''];
    }

    /**
     * Salva il modulo dal costruttore del pannello (campi, logica, verbale, iter, domanda in PDF/A, documento da scaricare).
     * Ritorna ['id' => 0, 'errore' => 'titolo'] se manca il titolo; altrimenti l'id, il titolo, il tipo e se il file caricato è stato rifiutato.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     * @return array{id: int, titolo: string, tipo: string, errore: ?string, file_rifiutato: bool}
     */
    public function salva(array $post, array $files): array
    {
        $id = (int) ($post['modulo_id'] ?? 0);
        $titolo = mb_substr(trim((string) ($post['titolo'] ?? '')), 0, 200);
        if ($titolo === '') {
            return ['id' => $id, 'titolo' => '', 'tipo' => '', 'errore' => 'titolo', 'file_rifiutato' => false];
        }
        $tipo = ($post['tipo'] ?? '') === 'online' ? 'online' : 'documento';
        $cat = mb_substr(trim((string) ($post['categoria'] ?? '')), 0, 100) ?: 'Altro';
        $descr = mb_substr(trim(strip_tags((string) ($post['descrizione'] ?? ''), '<p><br><strong><em><ul><ol><li><a>')), 0, 5000);
        $dest = isset(Costanti::DESTINATARI_MODULO[$post['destinatari'] ?? '']) ? $post['destinatari'] : 'tutti';
        $emails = implode(', ', array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) ($post['email_ufficio'] ?? '')) ?: []), fn (string $e): bool => (bool) filter_var($e, FILTER_VALIDATE_EMAIL)));
        $link = trim((string) ($post['link'] ?? ''));
        if ($link !== '' && !preg_match('#^https?://#i', $link)) {
            $link = 'https://' . $link;
        }
        if ($link !== '' && !filter_var($link, FILTER_VALIDATE_URL)) {
            $link = '';
        }
        $attivo = isset($post['attivo']) ? 1 : 0;
        $ordine = (int) ($post['ordine'] ?? 0);
        $campiJson = $this->campiDaForm($post);
        $verbale = [];
        foreach (['sezione' => 200, 'stile' => 10, 'intro' => 3000, 'testo' => 3000, 'delibera' => 3000, 'chiusura' => 3000, 'colonne' => 500, 'raggruppa' => 200, 'decisione' => 20] as $k => $max) {
            $verbale[$k] = mb_substr(trim((string) ($post['v_' . $k] ?? '')), 0, $max);
        }
        $verbaleJson = json_encode($verbale, JSON_UNESCAPED_UNICODE);
        $iter = [];
        foreach ((array) ($post['iter'] ?? []) as $x) {
            // «@cdl» / «@segreteria»: l'ufficio si sceglie con la pratica (corso di studio, segreteria indicata dal referente)
            if (in_array($x, ['@cdl', '@segreteria'], true)) {
                $iter[] = $x;
                continue;
            }
            if (($idU = $this->uffici->idDa((int) $x)) && !(int) ($this->uffici->tutti()[$idU]['smista'] ?? 0)) {
                $iter[] = $idU;
            }
        }
        $iterJson = $iter ? json_encode($iter) : null;
        $dal = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($post['aperto_dal'] ?? '')) ? $post['aperto_dal'] : null;
        $al = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($post['aperto_al'] ?? '')) ? $post['aperto_al'] : null;
        $gg = max(0, min(90, (int) ($post['giorni_promemoria'] ?? 7)));
        $id = $this->moduli->salva($id, [
            'titolo' => $titolo, 'categoria' => $cat, 'descrizione' => $descr, 'tipo' => $tipo, 'link' => $link, 'campi_json' => $campiJson, 'verbale_json' => $verbaleJson, 'iter_json' => $iterJson,
            'destinatari' => $dest, 'email_ufficio' => $emails, 'attivo' => $attivo, 'ordine' => $ordine, 'aperto_dal' => $dal, 'aperto_al' => $al, 'giorni_promemoria' => $gg,
        ]);
        // Domanda in PDF/A da protocollare (moduli online)
        $domanda = ['pdf' => !empty($post['d_pdf']) ? 1 : 0, 'bollo' => !empty($post['d_bollo']) ? 1 : 0];
        foreach (['oggetto' => 300, 'destinatario' => 600, 'chiede' => 2000] as $k => $max) {
            $domanda[$k] = mb_substr(trim((string) ($post['d_' . $k] ?? '')), 0, $max);
        }
        if ($id) {
            $this->moduli->impostaDomanda($id, (string) json_encode($domanda, JSON_UNESCAPED_UNICODE));
        }
        $rifiutato = false;
        // Documento da scaricare (pubblico)
        if (($files['file_modulo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $dir = $this->sito->radice() . '/' . Costanti::DIR_MODULISTICA;
            $fn = $this->upload->salva(
                $files['file_modulo'],
                $dir,
                ['pdf', 'doc', 'docx', 'odt', 'xls', 'xlsx', 'ods', 'rtf'],
                ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.oasis.opendocument.text',
                 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.oasis.opendocument.spreadsheet', 'text/rtf', 'application/rtf', 'application/zip', 'application/octet-stream']
            );
            if ($fn) {
                $vecchio = $this->moduli->perId($id)['file_path'] ?? null;
                $this->moduli->impostaFile($id, Costanti::DIR_MODULISTICA . $fn);
                if ($vecchio && is_file($this->sito->radice() . '/' . $vecchio)) {
                    @unlink($this->sito->radice() . '/' . $vecchio);
                }
            } else {
                $rifiutato = true;
            }
        }

        return ['id' => $id, 'titolo' => $titolo, 'tipo' => $tipo, 'errore' => null, 'file_rifiutato' => $rifiutato];
    }

    /**
     * Elimina il modulo; se ha pratiche lo nasconde soltanto. Ritorna il numero di pratiche (0 = eliminato).
     */
    public function elimina(int $id): int
    {
        $n = $this->moduli->contaPratiche($id);
        if ($n > 0) {
            $this->moduli->nascondi($id);

            return $n;
        }
        $m = $this->moduli->perId($id);
        if ($m && $m['file_path'] && is_file($this->sito->radice() . '/' . $m['file_path'])) {
            @unlink($this->sito->radice() . '/' . $m['file_path']);
        }
        $this->moduli->elimina($id);

        return 0;
    }

    /**
     * Campi del modulo online dalle righe del costruttore (JSON, null se non ce ne sono).
     *
     * @param array<string, mixed> $post
     */
    private function campiDaForm(array $post): ?string
    {
        $campi = [];
        foreach ((array) ($post['c_etichetta'] ?? []) as $i => $et) {
            $et = trim((string) $et);
            if ($et === '') {
                continue;
            }
            $campo = ['etichetta' => $et, 'tipo' => (string) ($post['c_tipo'][$i] ?? 'text'), 'opzioni' => (string) ($post['c_opzioni'][$i] ?? ''),
                      'obbligatorio' => ($post['c_obbl'][$i] ?? '0') === '1', 'ufficio' => ($post['c_uff'][$i] ?? '0') === '1', 'aiuto' => (string) ($post['c_aiuto'][$i] ?? '')];
            // Logica: «mostra solo se» e «compila in automatico se» (l'altro campo è indicato con la sua domanda)
            $rif = trim((string) ($post['c_cond_campo'][$i] ?? ''));
            if ($rif !== '' && isset(Costanti::OPERATORI_CONDIZIONE[$post['c_cond_op'][$i] ?? ''])) {
                $campo['cond'] = ['campo' => $rif, 'op' => $post['c_cond_op'][$i], 'valore' => (string) ($post['c_cond_val'][$i] ?? '')];
            }
            $rif = trim((string) ($post['c_auto_campo'][$i] ?? ''));
            if ($rif !== '' && isset(Costanti::OPERATORI_CONDIZIONE[$post['c_auto_op'][$i] ?? ''])) {
                $campo['auto'] = ['campo' => $rif, 'op' => $post['c_auto_op'][$i], 'valore' => (string) ($post['c_auto_val'][$i] ?? ''), 'imposta' => (string) ($post['c_auto_imposta'][$i] ?? '')];
            }
            $campi[] = $campo;
        }

        return $campi ? (string) json_encode($campi, JSON_UNESCAPED_UNICODE) : null;
    }
}
