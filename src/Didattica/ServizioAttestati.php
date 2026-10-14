<?php

declare(strict_types=1);

namespace App\Didattica;

/**
 * Gli attestati degli studenti (frequenza alle lezioni, esame sostenuto): dopo la conferma dei docenti l'ufficio attestati vede l'attestato preparato dal portale
 * (PDF/A con i dati dello studente, le righe attestate, le conferme dei docenti e i dati dell'accesso), lo manda al Direttore per la firma digitale e, a firma
 * avvenuta, registra il protocollo in uscita e carica il PDF protocollato: lo studente lo scarica e lo manda all'azienda.
 */
final class ServizioAttestati
{
    public function __construct(
        private PraticaRepository $pratiche,
        private ModuloRepository $moduli,
        private ServizioIter $iter,
        private StoricoPratica $storico,
        private AllegatiPratiche $allegati,
        private ServizioFlussoConvalide $flusso,
        private ServizioConfermeDocenti $conferme,
        private AttestatoPdf $generatore
    ) {
    }

    /** «frequenza», «esame» oppure vuoto se il modulo non è un attestato del portale. */
    public static function tipo(?array $m): string
    {
        return match ((string) ($m['chiave'] ?? '')) {
            'attestato_frequenza' => 'frequenza',
            'attestato_esame' => 'esame',
            default => '',
        };
    }

    /** Valore di una risposta per etichetta. */
    private static function valore(array $risposte, string $etichetta): string
    {
        foreach ($risposte as $r) {
            if (mb_strtolower(trim((string) ($r['etichetta'] ?? ''))) === mb_strtolower($etichetta) && empty($r['nascosto'])) {
                return trim((string) ($r['valore'] ?? ''));
            }
        }

        return '';
    }

    /** Il nome dell'insegnamento senza «– corso di laurea» che l'elenco aggiunge in coda. */
    private static function nomeInsegnamento(string $v): string
    {
        $v = trim($v);
        $i = mb_strrpos($v, ' – ');

        return $i === false ? $v : trim(mb_substr($v, 0, $i));
    }

    /** @return array{il: int, metodo?: string}|null la conferma del docente (data e come è entrato) */
    private function conferma(array $confermate, string $nome): ?array
    {
        foreach ($confermate as $c) {
            if ($c['nome'] === $nome && $c['stato'] === 'confermata' && !empty($c['esito_il'])) {
                $a = (array) json_decode((string) ($c['accesso_json'] ?? ''), true);
                $metodo = (\App\Tutorato\Costanti::METODI_ACCESSO[$a['metodo'] ?? 'ateneo'] ?? '') ?: 'credenziali di Ateneo';

                return ['il' => (int) strtotime((string) $c['esito_il']), 'metodo' => $metodo . (!empty($a['livello']) ? ' livello ' . (int) $a['livello'] : '')];
            }
        }

        return null;
    }

    /**
     * I dati dell'attestato nella forma che serve ad AttestatoPdf.
     *
     * @param array<string, mixed> $p
     * @param array<string, mixed> $m
     * @return array<string, mixed>|null null se il modulo non è un attestato
     */
    public function dati(array $p, array $m): ?array
    {
        $tipo = self::tipo($m);
        if ($tipo === '') {
            return null;
        }
        $risposte = (array) json_decode((string) $p['risposte_json'], true);
        $confermate = $this->conferme->dellaPratica((int) $p['id']);
        $acc = (array) json_decode((string) ($p['accesso_json'] ?? ''), true);
        $metodo = (\App\Tutorato\Costanti::METODI_ACCESSO[$acc['metodo'] ?? 'ateneo'] ?? '') ?: 'credenziali di Ateneo';
        $righe = [];
        if ($tipo === 'esame') {
            $doc = self::valore($risposte, 'Docente titolare');
            $righe[] = ['insegnamento' => self::nomeInsegnamento(self::valore($risposte, 'Insegnamento')), 'docente' => $this->conferme->nomeVisibile($doc),
                'data' => self::valore($risposte, 'Data dell\'esame'), 'dalle' => self::valore($risposte, 'Dalle ore'), 'alle' => self::valore($risposte, 'Alle ore'),
                'aula' => self::valore($risposte, 'Aula'), 'conferma' => $this->conferma($confermate, $doc)];
        } else {
            foreach ($risposte as $r) {
                if (mb_strtolower((string) ($r['etichetta'] ?? '')) !== mb_strtolower(ModelliPratiche::TABELLA_LEZIONI) || empty($r['righe'])) {
                    continue;
                }
                $cols = array_map(static fn ($n): string => mb_strtolower(trim((string) $n)), (array) $r['colonne']);
                $c = static fn (array $riga, string $nome): string => trim((string) ($riga[array_search(mb_strtolower($nome), $cols, true)] ?? ''));
                foreach ((array) $r['righe'] as $riga) {
                    $riga = (array) $riga;
                    $doc = $c($riga, 'Docente');
                    $dal = $c($riga, 'Dal');
                    $righe[] = ['insegnamento' => self::nomeInsegnamento($c($riga, 'Insegnamento')), 'docente' => $this->conferme->nomeVisibile($doc), 'dal' => $dal,
                        'al' => $c($riga, 'Al') !== '' ? $c($riga, 'Al') : $dal, 'dalle' => $c($riga, 'Dalle ore'), 'alle' => $c($riga, 'Alle ore'), 'aula' => $c($riga, 'Aula'),
                        'conferma' => $this->conferma($confermate, $doc)];
                }
            }
        }

        return [
            'tipo' => $tipo, 'codice' => (string) $p['codice'], 'inviata_il' => (int) strtotime((string) $p['creata_il']),
            'studente' => ['nome' => trim($p['nome'] . ' ' . $p['cognome']), 'matricola' => (string) ($p['matricola'] !== '' ? $p['matricola'] : self::valore($risposte, 'Matricola')),
                           'corso' => self::valore($risposte, 'Al Corso di Laurea in'), 'email' => (string) $p['email']],
            'motivo' => self::valore($risposte, 'Motivo della richiesta'), 'ente' => self::valore($risposte, 'Ente o azienda'), 'pec' => self::valore($risposte, 'PEC o email dell\'azienda'),
            'accesso' => $metodo . (!empty($acc['livello']) ? ' livello ' . (int) $acc['livello'] : ''), 'ip' => (string) ($p['ip_invio'] ?? ''),
            'righe' => $righe, 'rilasciato_il' => time(),
        ];
    }

    /** Il PDF dell'attestato (null se la pratica non è un attestato). */
    public function pdf(int $id): ?string
    {
        $p = $this->pratiche->perId($id);
        $m = $p ? $this->moduli->perId((int) $p['modulo_id']) : null;
        $d = $p && $m ? $this->dati($p, $m) : null;

        return $d ? $this->generatore->pdf($d) : null;
    }

    /** La PEC o email dell'azienda a cui mandare l'attestato protocollato. */
    public function destinatario(array $p): string
    {
        return self::valore((array) json_decode((string) $p['risposte_json'], true), 'PEC o email dell\'azienda');
    }

    public function inGenera(array $p): bool
    {
        return $this->iter->passoCon('genera', $this->moduli->perId((int) $p['modulo_id']), $p);
    }

    public function inUscita(array $p): bool
    {
        return $this->iter->passoCon('uscita', $this->moduli->perId((int) $p['modulo_id']), $p);
    }

    /**
     * L'ufficio controlla l'attestato e lo manda al Direttore: il PDF si salva nella pratica (interno) e la pratica passa alla firma. Ritorna [errore|null, messaggio].
     *
     * @return array{0: ?string, 1: string}
     */
    public function mandaAlDirettore(int $id, int $uid, string $autoreNome = ''): array
    {
        $p = $this->pratiche->perId($id);
        if (!$p || !$this->inGenera($p)) {
            return ['La pratica non è nel passo in cui si controlla l\'attestato.', ''];
        }
        if (!$this->conferme->tutteConfermate($id)) {
            return ['Non tutti i docenti hanno confermato.', ''];
        }
        $pdf = $this->pdf($id);
        $rel = $pdf ? $this->allegati->salvaPdf($pdf) : null;
        if (!$rel) {
            return ['Non riesco a preparare l\'attestato in PDF: riprova.', ''];
        }
        $this->storico->evento($id, 'attivita', 'ufficio', $uid, null, 'Attestato preparato dal portale, controllato e mandato al Direttore per la firma.', $rel, 'Attestato_' . $p['codice'] . '.pdf', true, $autoreNome);
        [$err, $msg] = $this->flusso->trasmettiAlPasso($id, (int) $p['passo'], $uid, $autoreNome);

        return [$err, $err ? '' : 'Attestato mandato al Direttore. ' . $msg];
    }

    /**
     * Protocollo in uscita: numero, data e PDF firmato e protocollato. Lo studente lo trova nella pratica (e riceve l'email), l'iter si conclude.
     * Ritorna [errore|null, messaggio].
     *
     * @param array<string, mixed>|null $file elemento di $_FILES
     * @return array{0: ?string, 1: string}
     */
    public function protocolloUscita(int $id, string $numero, string $data, ?array $file, int $uid, string $autoreNome = ''): array
    {
        $p = $this->pratiche->perId($id);
        if (!$p || !$this->inUscita($p)) {
            return ['La pratica non è nel passo del protocollo in uscita.', ''];
        }
        $numero = mb_substr(trim($numero), 0, 100);
        if ($numero === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || $data > date('Y-m-d')) {
            return ['Indica il numero e la data del protocollo (non nel futuro).', ''];
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'pdf' || !is_file($tmp)) {
            return ['Carica il PDF firmato e protocollato (fino a 10 MB).', ''];
        }
        $rel = $this->allegati->salvaPdf((string) file_get_contents($tmp));
        if (!$rel) {
            return ['Il file non è un PDF valido (fino a 10 MB).', ''];
        }
        $this->pratiche->impostaProtocollo($id, $numero, $data);
        $this->storico->evento(
            $id,
            'attivita',
            'ufficio',
            $uid,
            null,
            'Attestato firmato e protocollato in uscita: prot. n. ' . $numero . ' del ' . date('d/m/Y', (int) strtotime($data)) . '.',
            $rel,
            'Firmato_direttore_' . $p['codice'] . '.pdf',
            false,
            $autoreNome
        );
        [$err, $msg] = $this->flusso->trasmettiAlPasso($id, (int) $p['passo'], $uid, $autoreNome);
        if (!$err) {
            $this->pratiche->impostaStato($id, 'chiusa', null);
            $this->storico->evento($id, 'stato', 'ufficio', $uid, 'chiusa', 'Pratica conclusa: attestato rilasciato.', null, null, false, $autoreNome);
        }

        return [$err, $err ? '' : 'Protocollo registrato: lo studente è stato avvisato e può scaricare l\'attestato.'];
    }
}
