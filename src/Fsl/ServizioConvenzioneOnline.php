<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Anagrafi\ServizioScuole;
use App\Core\Sito;
use App\Infrastructure\Mail\Mailer;
use App\Infrastructure\Storage\Upload;

/**
 * Convenzione FSL e Allegato A compilati online dalla scuola con un modulo guidato (convenzione_online.php): dati dell'istituto e
 * del Dirigente, logo, attività dell'Allegato A (quelle già prenotate e altre ancora da prenotare). Accesso con il codice della
 * prenotazione o con il link personale (token).
 */
final class ServizioConvenzioneOnline
{
    /** Loghi delle scuole: cartella non raggiungibile dal web, usati solo per creare i documenti. */
    private const CARTELLA_LOGHI = 'uploads/convenzioni_compilate/';

    public function __construct(
        private ConvenzioneCompilataRepository $compilate,
        private PrenotazioneFslRepository $prenotazioni,
        private PrecompilazioneConvenzione $precompilazione,
        private DocumentoConvenzione $documenti,
        private ModelliConvenzione $modelli,
        private ServizioScuole $scuole,
        private Upload $upload,
        private Mailer $mailer,
        private Sito $sito,
        private AllegatoAPdf $allegatoPdf
    ) {
    }

    /** @return array<string, mixed>|null */
    public function perToken(string $token): ?array
    {
        return $this->compilate->perToken($token);
    }

    /**
     * Dal codice della prenotazione al link personale: una sola compilazione per prenotazione (se c'è già si riprende).
     * Ritorna il token oppure null se il codice non corrisponde a una prenotazione di un'attività FSL.
     */
    public function tokenDaCodice(string $codice): ?string
    {
        $pr = preg_match('/^[A-Z0-9-]{4,50}$/', $codice) ? $this->prenotazioni->perCodiceConvenzione($codice) : null;
        if (!$pr) {
            return null;
        }
        $cc = $this->compilate->ultimaDellaPrenotazione((int) $pr['id']) ?: $this->compilate->cheContiene((int) $pr['id']);
        if (!$cc) {
            $cc = $this->compilate->crea(bin2hex(random_bytes(16)), $pr['scuola_codice'] ?: null, (int) $pr['id'], (string) $pr['email']);
        }

        return $cc['token'] ?? null;
    }

    /**
     * Tutto ciò che serve alla pagina per una compilazione: prenotazione, modelli, attività prenotate e prenotabili, dati già salvati e
     * valori proposti (dall'anagrafe delle scuole e dalla prenotazione). Null se la prenotazione collegata non esiste più.
     *
     * @param array<string, mixed> $cc riga di convenzioni_compilate
     * @return array{p0: array<string, string|null>, cfg: array<string, string>, prenotate: array<int, array<string, string|null>>, prenotabili: array<int, array<string, mixed>>, dati: array<string, mixed>, s: array<string, mixed>}|null
     */
    public function contesto(array $cc): ?array
    {
        $p0 = $this->prenotazioni->dati((int) $cc['prenotazione_id']);
        if (!$p0) {
            return null;
        }
        $cfg = $this->modelli->dati($p0);
        $dati = json_decode((string) $cc['dati_json'], true) ?: [];
        // Le prenotazioni già scelte restano nell'elenco anche se la scuola non è dell'anagrafe (programma FSL: più prenotazioni insieme)
        $scelte = array_values(array_filter(array_map(static fn ($a): int => (int) ($a['pr'] ?? 0), (array) ($dati['attivita'] ?? []))));
        [$prenotate, $prenotabili] = $this->precompilazione->attivitaScuola((int) $cc['prenotazione_id'], $cc['scuola_codice'], $scelte);
        // Valori proposti: dall'anagrafe delle scuole e dalla prenotazione
        $base = $this->precompilazione->dati($p0);
        $anagrafe = !empty($cc['scuola_codice']) ? $this->scuole->perCodice($cc['scuola_codice']) : null;
        $predefiniti = [
            'denominazione' => $base['ISTITUTO_FIRMA'], 'codice' => $anagrafe ? (string) ($anagrafe['istituto_codice'] ?: $anagrafe['codice']) : '',
            'comune' => $base['COMUNE'], 'indirizzo' => $base['INDIRIZZO'], 'cf' => '', 'dirigente' => '', 'luogo_nascita' => '', 'data_nascita' => '', 'dir_cf' => '',
            'pec' => '', 'email' => (string) $cc['email'],
        ];

        return ['p0' => $p0, 'cfg' => $cfg, 'prenotate' => $prenotate, 'prenotabili' => $prenotabili, 'dati' => $dati, 's' => ($dati['scuola'] ?? []) + $predefiniti];
    }

    /**
     * Documento Word dai dati salvati: percorso del file temporaneo e nome del download (null se il modello manca). Segna la compilazione come scaricata.
     *
     * @param array<string, mixed> $cc
     * @param array<string, mixed> $ctx risultato di contesto()
     * @return array{file: string, nome: string}|null
     */
    public function scarica(string $doc, array $cc, array $ctx): ?array
    {
        // 'convenzione' = Word con l'Allegato A in fondo; 'convenzione_sola' = Word senza Allegato A; 'allegato' = Allegato A in Word;
        // 'allegato_pdf' = Allegato A in PDF con la scheda completa di ogni attività (da firmare in PAdES)
        $doc = in_array($doc, ['allegato', 'allegato_pdf', 'convenzione_sola'], true) ? $doc : 'convenzione';
        $radice = $this->sito->radice();
        $logo = $cc['logo'] && is_file($radice . '/' . $cc['logo']) ? $radice . '/' . $cc['logo'] : null;
        $prot = trim((string) ($cc['protocollo'] ?? '') . (!empty($cc['protocollo_data']) ? ' del ' . date('d/m/Y', strtotime($cc['protocollo_data'])) : ''));
        $prot = (string) ($cc['protocollo'] ?? '') !== '' ? $prot : '';
        $s = $ctx['s'];
        $sigla = preg_replace('/[^A-Za-z0-9]+/', '_', (string) ($s['codice'] ?: $s['denominazione']));
        if ($doc === 'allegato_pdf') {
            $pdf = $this->allegatoPdf->genera($s, $this->vociAllegato($ctx['dati'], $ctx['prenotate'], $ctx['prenotabili']), $logo, $prot);
            $file = tempnam(sys_get_temp_dir(), 'all') . '.pdf';
            if (file_put_contents($file, $pdf) === false) {
                return null;
            }
            $nome = 'Allegato_A_FSL_' . $sigla . '.pdf';
        } else {
            [$scuola, $att] = $this->datiDocumenti($ctx['dati'], $ctx['prenotate'], $ctx['prenotabili']);
            $file = $this->documenti->genera($doc === 'convenzione_sola' ? 'convenzione' : $doc, $scuola, $att, $logo, $prot, $doc === 'convenzione_sola');
            if (!$file) {
                return null;
            }
            $nome = ($doc === 'allegato' ? 'Allegato_A_FSL_' : 'Convenzione_FSL_') . $sigla . '.docx';
        }
        $this->compilate->segnaScaricata((int) $cc['id']);

        return ['file' => $file, 'nome' => $nome];
    }

    /**
     * Convenzione o Allegato A già compilati per le prenotazioni indicate, senza che la scuola abbia usato il modulo online (documenti per il
     * pannello): dati della scuola dall'anagrafe e dalle prenotazioni; se la scuola ha compilato il modulo, i suoi dati (Dirigente, codice
     * fiscale, PEC, studenti, tutor, protocollo e logo) hanno la precedenza. Senza logo il documento non lo mette: si aggiunge a mano.
     *
     * @param list<int> $prenotazioniIds prenotazioni della stessa scuola
     * @return array{file: string, nome: string}|null file temporaneo da cancellare dopo l'uso
     */
    public function scaricaPerPrenotazioni(string $doc, array $prenotazioniIds): ?array
    {
        $prenotate = [];
        foreach (array_values(array_unique(array_map('intval', $prenotazioniIds))) as $id) {
            if ($id > 0 && ($p = $this->prenotazioni->dati($id))) {
                $prenotate[$id] = $p;
            }
        }
        if (!$prenotate) {
            return null;
        }
        $p0 = reset($prenotate);
        $base = $this->precompilazione->dati($p0);
        $anagrafe = !empty($p0['scuola_codice']) ? $this->scuole->perCodice((string) $p0['scuola_codice']) : null;
        $s = [
            'denominazione' => $base['ISTITUTO_FIRMA'] !== '' ? $base['ISTITUTO_FIRMA'] : ServizioScuole::nomeScrittoNelModulo($p0),
            'codice' => $anagrafe ? (string) ($anagrafe['istituto_codice'] ?: $anagrafe['codice']) : '', 'comune' => $base['COMUNE'], 'indirizzo' => $base['INDIRIZZO'],
            'cf' => '', 'dirigente' => '', 'luogo_nascita' => '', 'data_nascita' => '', 'dir_cf' => '', 'pec' => '', 'email' => (string) ($p0['email'] ?? ''),
        ];
        // Compilazione online della scuola (la più recente), se c'è: i suoi dati valgono più di quelli proposti
        $cc = $this->compilate->perPrenotazioni(array_keys($prenotate))[0] ?? null;
        $dati = $cc ? (json_decode((string) $cc['dati_json'], true) ?: []) : [];
        $s = array_filter((array) ($dati['scuola'] ?? []), static fn ($v): bool => trim((string) $v) !== '') + $s;
        $voci = [];
        foreach (array_keys($prenotate) as $id) {
            $scelta = [];
            foreach ((array) ($dati['attivita'] ?? []) as $a) {
                if ((int) ($a['pr'] ?? 0) === $id) {
                    $scelta = $a;
                    break;
                }
            }
            $voci[] = ['pr' => $id, 'studenti' => (int) ($scelta['studenti'] ?? 0), 'tutor' => (string) ($scelta['tutor'] ?? '')];
        }
        // id 0: scaricare dal pannello non segna la compilazione come «scaricata dalla scuola»
        $ccFinta = ['id' => 0, 'logo' => $cc['logo'] ?? null, 'protocollo' => $cc['protocollo'] ?? '', 'protocollo_data' => $cc['protocollo_data'] ?? null];

        return $this->scarica($doc, $ccFinta, ['s' => $s, 'dati' => ['scuola' => $s, 'attivita' => $voci], 'prenotate' => $prenotate, 'prenotabili' => []]);
    }

    /**
     * Le attività scelte per l'Allegato A in PDF: prenotazione (o attività ancora da prenotare) con studenti e docente referente.
     *
     * @param array<string, mixed> $dati
     * @param array<int, array<string, string|null>> $prenotate
     * @param array<int, array<string, mixed>> $prenotabili
     * @return list<array{p: array<string, mixed>, studenti: int, tutor: string}>
     */
    private function vociAllegato(array $dati, array $prenotate, array $prenotabili): array
    {
        $voci = [];
        foreach ($dati['attivita'] ?? [] as $a) {
            $p = !empty($a['pr']) ? ($prenotate[(int) $a['pr']] ?? null) : ($prenotabili[(int) ($a['ev'] ?? 0)] ?? null);
            if ($p) {
                $voci[] = ['p' => $p, 'studenti' => (int) ($a['studenti'] ?? 0), 'tutor' => (string) preg_replace('/^(\s*(prof(\.ssa|essoressa|essore)?|dott(\.ssa|oressa|ore)?)\.?\s*\/?)+/i', '', (string) ($a['tutor'] ?? ''))];
            }
        }

        return $voci;
    }

    /**
     * Controlla i dati dell'istituto e del Dirigente e carica o toglie il logo (PNG o JPG fino a 2 MB, in una cartella non raggiungibile dal web).
     * Solo la denominazione è obbligatoria: chi non conosce gli altri dati li lascia vuoti e nel documento restano da completare a mano.
     * Ritorna gli errori, i valori ripuliti e il percorso del logo da salvare (null = nessun logo).
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed>|null $fileLogo elemento $_FILES['logo']
     * @return array{errori: list<string>, s: array<string, string>, logo: string|null}
     */
    public function validaScuola(array $post, ?array $fileLogo, ?string $logoAttuale): array
    {
        $in = static fn (string $k, int $max = 255): string => mb_substr(trim((string) ($post[$k] ?? '')), 0, $max);
        $s = [
            'denominazione' => $in('denominazione'), 'codice' => strtoupper($in('codice', 20)), 'comune' => $in('comune'), 'indirizzo' => $in('indirizzo'),
            'cf' => strtoupper((string) preg_replace('/\s+/', '', $in('cf', 20))), 'dirigente' => $in('dirigente'), 'luogo_nascita' => $in('luogo_nascita'),
            'data_nascita' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $in('data_nascita', 10)) ? $in('data_nascita', 10) : '',
            'dir_cf' => strtoupper((string) preg_replace('/\s+/', '', $in('dir_cf', 20))), 'pec' => $in('pec'), 'email' => $in('email'),
        ];
        $errori = [];
        if ($s['denominazione'] === '') {
            $errori[] = "Indica la denominazione dell'istituzione scolastica.";
        }
        if ($s['cf'] !== '' && !preg_match('/^(\d{11}|[A-Z0-9]{16})$/', $s['cf'])) {
            $errori[] = "Il codice fiscale dell'istituto ha 11 cifre.";
        }
        if ($s['dir_cf'] !== '' && !preg_match('/^[A-Z0-9]{16}$/', $s['dir_cf'])) {
            $errori[] = 'Il codice fiscale del Dirigente ha 16 caratteri.';
        }
        if ($s['pec'] !== '' && !filter_var($s['pec'], FILTER_VALIDATE_EMAIL)) {
            $errori[] = 'La PEC della scuola non è valida.';
        }
        if ($s['email'] !== '' && !filter_var($s['email'], FILTER_VALIDATE_EMAIL)) {
            $errori[] = "L'email di riferimento non è valida.";
        }
        $logo = $logoAttuale;
        if (!empty($post['togli_logo'])) {
            $logo = null;
        }
        if (($fileLogo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            if ($fileLogo['size'] > 2 * 1024 * 1024) {
                $errori[] = 'Il logo supera 2 MB.';
            } else {
                $dir = $this->sito->radice() . '/' . self::CARTELLA_LOGHI;
                $this->upload->cartellaProtetta($dir, 'Loghi delle scuole per le convenzioni: usati solo per creare i documenti');
                $fn = $this->upload->salva($fileLogo, $dir, ['png', 'jpg', 'jpeg'], ['image/png', 'image/jpeg']);
                if ($fn) {
                    $logo = self::CARTELLA_LOGHI . $fn;
                } else {
                    $errori[] = "Il logo deve essere un'immagine PNG o JPG.";
                }
            }
        }

        return ['errori' => $errori, 's' => $s, 'logo' => $logo];
    }

    /**
     * Apre la compilazione della convenzione per le prenotazioni fatte insieme dal programma FSL: dati della scuola e del Dirigente, logo e
     * attività dell'Allegato A già scelte (le prenotazioni, con studenti e docente referente). Il link personale è quello dell'email riepilogativa.
     *
     * @param array<string, string> $scuola risultato di validaScuola()
     * @param list<array{pr: int, studenti: int, tutor: string}> $attivita
     * @param string $convenzione 'si' (già stipulata), 'no' (da stipulare) o 'rinnovo' (quella registrata non copre il periodo)
     * @return array<string, string|null>|null la riga di convenzioni_compilate
     */
    public function creaDaProgramma(array $scuola, ?string $logo, array $attivita, int $prenotazioneId, ?string $codiceScuola, string $email, string $convenzione): ?array
    {
        $cc = $this->compilate->crea(bin2hex(random_bytes(16)), $codiceScuola ?: null, $prenotazioneId, $email);
        if (!$cc) {
            return null;
        }
        $json = json_encode(['scuola' => $scuola, 'attivita' => $attivita, 'origine' => 'programma', 'convenzione' => $convenzione], JSON_UNESCAPED_UNICODE);
        $this->compilate->salvaDati((int) $cc['id'], (string) $json, $logo, $email);

        return $this->compilate->perToken((string) $cc['token']);
    }

    /**
     * Salva il modulo: controlla i dati, carica o toglie il logo e, alla prima compilazione, manda l'email con il link per riprendere.
     * Ritorna gli errori da mostrare (vuoto = salvato) e i valori inseriti, da riproporre nel modulo.
     *
     * @param array<string, mixed> $cc
     * @param array<string, mixed> $ctx risultato di contesto()
     * @param array<string, mixed> $post
     * @param array<string, mixed>|null $fileLogo elemento $_FILES['logo']
     * @return array{errori: list<string>, s: array<string, mixed>}
     */
    public function salva(array $cc, array $ctx, array $post, ?array $fileLogo): array
    {
        $v = $this->validaScuola($post, $fileLogo, $cc['logo'] ?: null);
        $s = $v['s'];
        $errori = $v['errori'];
        $att = [];
        foreach ((array) ($post['att'] ?? []) as $k => $a) {
            if (empty($a['scelta'])) {
                continue;
            }
            $voce = ['studenti' => max(0, min(500, (int) ($a['studenti'] ?? 0))), 'tutor' => mb_substr(trim((string) ($a['tutor'] ?? '')), 0, 150)];
            if (str_starts_with((string) $k, 'pr') && isset($ctx['prenotate'][(int) substr((string) $k, 2)])) {
                $att[] = ['pr' => (int) substr((string) $k, 2)] + $voce;
            } elseif (str_starts_with((string) $k, 'ev') && isset($ctx['prenotabili'][(int) substr((string) $k, 2)])) {
                $att[] = ['ev' => (int) substr((string) $k, 2)] + $voce;
            }
        }
        if (!$att) {
            $errori[] = "Scegli almeno un'attività per l'Allegato A.";
        }
        $logo = $v['logo'];
        if ($errori) {
            return ['errori' => $errori, 's' => $s];
        }
        $prima = empty($cc['dati_json']);
        $json = json_encode(array_diff_key($ctx['dati'], ['scuola' => 1, 'attivita' => 1]) + ['scuola' => $s, 'attivita' => $att], JSON_UNESCAPED_UNICODE);
        $email = $s['email'] ?: (string) $cc['email'];
        $this->compilate->salvaDati((int) $cc['id'], (string) $json, $logo, $email);
        if ($cc['logo'] && $cc['logo'] !== $logo && is_file($this->sito->radice() . '/' . $cc['logo'])) {
            @unlink($this->sito->radice() . '/' . $cc['logo']);
        }
        // Email con il link per riprendere e scaricare i documenti (alla prima compilazione)
        if ($prima && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->inviaLink((string) $cc['token'], $email, $s['denominazione'], $ctx['cfg']['pec']);
        }

        return ['errori' => [], 's' => $s];
    }

    /**
     * Le convenzioni e gli Allegati A delle prenotazioni della persona (Area personale): per ognuna il link personale, lo stato della convenzione
     * e le attività dell'Allegato A con lo stato di ogni prenotazione. Chi ha più compilazioni le vede tutte, dalla più recente.
     *
     * @param list<int> $prenotazioniIds prenotazioni della persona
     * @return list<array{token: string, scuola: string, convenzione: string, aggiornata: string, protocollo: string, attivita: list<array{titolo: string, turno: string, codice: string, stato: string, mia: bool}>}>
     */
    public function dellePrenotazioni(array $prenotazioniIds): array
    {
        $mie = array_map('intval', $prenotazioniIds);
        $out = [];
        foreach ($this->compilate->perPrenotazioni($prenotazioniIds) as $cc) {
            $dati = json_decode((string) ($cc['dati_json'] ?? ''), true) ?: [];
            $ids = array_values(array_unique(array_merge([(int) $cc['prenotazione_id']], array_map(static fn ($a): int => (int) ($a['pr'] ?? 0), (array) ($dati['attivita'] ?? [])))));
            $attivita = [];
            foreach (array_filter($ids) as $id) {
                if ($p = $this->prenotazioni->dati($id)) {
                    $attivita[] = ['titolo' => (string) $p['evento_titolo'], 'turno' => trim((string) ($p['nome_turno'] ?? '')), 'codice' => (string) $p['codice_prenotazione'],
                        'stato' => (string) ($p['stato'] ?? 'confermata'), 'mia' => in_array($id, $mie, true)];
                }
            }
            $out[] = [
                'token' => (string) $cc['token'], 'scuola' => (string) (($dati['scuola']['denominazione'] ?? '') ?: 'Scuola'),
                'convenzione' => (string) ($dati['convenzione'] ?? ''), 'aggiornata' => (string) ($cc['aggiornata_il'] ?? ''), 'protocollo' => (string) ($cc['protocollo'] ?? ''),
                'attivita' => $attivita,
            ];
        }

        return $out;
    }

    /** Protocollo assegnato dal Dipartimento a una compilazione online: compare nei documenti Word generati. */
    public function salvaProtocollo(int $id, string $protocollo, string $data): void
    {
        $this->compilate->salvaProtocollo($id, mb_substr(trim($protocollo), 0, 100), preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) ? $data : null);
    }

    /**
     * Le compilazioni dell'ultimo anno (pannello FSL).
     *
     * @return list<array<string, string|null>>
     */
    public function recenti(): array
    {
        return $this->compilate->recenti(50);
    }

    /**
     * I segnaposto della scuola e le righe dell'Allegato A dai dati salvati.
     *
     * @param array<string, mixed> $dati
     * @param array<int, array<string, string|null>> $prenotate
     * @param array<int, array<string, mixed>> $prenotabili
     * @return array{0: array<string, string>, 1: list<array<string, string>>}
     */
    private function datiDocumenti(array $dati, array $prenotate, array $prenotabili): array
    {
        $s = $dati['scuola'] ?? [];
        $scuola = [
            'ISTITUTO' => trim($s['denominazione'] ?? '') !== '' ? $s['denominazione'] . (trim($s['codice'] ?? '') !== '' ? ' (codice meccanografico ' . $s['codice'] . ')' : '') : '',
            'ISTITUTO_FIRMA' => $s['denominazione'] ?? '', 'COMUNE' => $s['comune'] ?? '', 'INDIRIZZO' => $s['indirizzo'] ?? '', 'CF_ISTITUTO' => $s['cf'] ?? '',
            'DIRIGENTE' => $s['dirigente'] ?? '', 'DIRIGENTE_FIRMA' => $s['dirigente'] ?? '', 'DIR_LUOGO_NASCITA' => $s['luogo_nascita'] ?? '',
            'DIR_DATA_NASCITA' => !empty($s['data_nascita']) ? date('d/m/Y', strtotime($s['data_nascita'])) : '', 'DIR_CF' => $s['dir_cf'] ?? '',
        ];
        $att = [];
        foreach ($dati['attivita'] ?? [] as $a) {
            $p = !empty($a['pr']) ? ($prenotate[(int) $a['pr']] ?? null) : ($prenotabili[(int) ($a['ev'] ?? 0)] ?? null);
            if (!$p) {
                continue;
            }
            $v = $this->precompilazione->dati($p);
            // il modello scrive già "Prof./Prof.ssa" davanti al tutor della scuola
            $att[] = [
                'TITOLO' => $v['TITOLO'], 'DESCRIZIONE' => $v['DESCRIZIONE'], 'PERIODO' => $v['PERIODO'], 'DURATA' => $v['DURATA'], 'TUTOR_DIBEST' => $v['TUTOR_DIBEST'],
                'STUDENTI' => (int) ($a['studenti'] ?? 0) > 0 ? (string) (int) $a['studenti'] : $v['STUDENTI'],
                'TUTOR_SCUOLA' => trim((string) preg_replace('/^(\s*(prof(\.ssa|essoressa|essore)?|dott(\.ssa|oressa|ore)?)\.?\s*\/?)+/i', '', (string) ($a['tutor'] ?? ''))) ?: $v['TUTOR_SCUOLA'],
            ];
        }

        return [$scuola, $att];
    }

    private function inviaLink(string $token, string $email, string $denominazione, string $pec): void
    {
        $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $link = $this->sito->urlBase() . '/convenzione_online.php?t=' . $token;
        $corpo = "<p>Gentile docente,</p><p>la Convenzione e l'Allegato A per <strong>" . $h($denominazione) . '</strong> sono pronti. Da questo link puoi scaricarli o correggerli:</p>'
            . "<p style='margin:18px 0;'><a href='" . $h($link) . "' style='background:#B30000;color:#fff;padding:10px 18px;text-decoration:none;border-radius:6px;font-weight:bold;'>Apri la convenzione</a></p>"
            . '<p>Poi il Dirigente Scolastico li <strong>firma digitalmente in PAdES</strong> (PDF firmato, non .p7m) e la scuola li invia via PEC a <a href=\'mailto:' . $h($pec) . "'>" . $h($pec) . '</a>. Appena riceviamo la convenzione confermiamo le prenotazioni.</p>';
        $this->mailer->invia($email, 'Convenzione Formazione Scuola Lavoro – documenti da firmare', $corpo);
    }
}
