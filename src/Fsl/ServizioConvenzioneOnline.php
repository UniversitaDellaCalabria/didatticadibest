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
        private Sito $sito
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
        $cc = $this->compilate->ultimaDellaPrenotazione((int) $pr['id']);
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
        [$prenotate, $prenotabili] = $this->precompilazione->attivitaScuola((int) $cc['prenotazione_id'], $cc['scuola_codice']);
        $dati = json_decode((string) $cc['dati_json'], true) ?: [];
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
        $doc = $doc === 'allegato' ? 'allegato' : 'convenzione';
        [$scuola, $att] = $this->datiDocumenti($ctx['dati'], $ctx['prenotate'], $ctx['prenotabili']);
        $radice = $this->sito->radice();
        $logo = $cc['logo'] && is_file($radice . '/' . $cc['logo']) ? $radice . '/' . $cc['logo'] : null;
        $prot = trim((string) ($cc['protocollo'] ?? '') . (!empty($cc['protocollo_data']) ? ' del ' . date('d/m/Y', strtotime($cc['protocollo_data'])) : ''));
        $file = $this->documenti->genera($doc, $scuola, $att, $logo, (string) ($cc['protocollo'] ?? '') !== '' ? $prot : '');
        if (!$file) {
            return null;
        }
        $this->compilate->segnaScaricata((int) $cc['id']);
        $s = $ctx['s'];

        return ['file' => $file, 'nome' => ($doc === 'allegato' ? 'Allegato_A_FSL_' : 'Convenzione_FSL_') . preg_replace('/[^A-Za-z0-9]+/', '_', (string) ($s['codice'] ?: $s['denominazione'])) . '.docx'];
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
        if ($s['dirigente'] === '') {
            $errori[] = 'Indica il Dirigente Scolastico.';
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
        // Logo della scuola (facoltativo): PNG o JPG fino a 2 MB, in una cartella non raggiungibile dal web
        $logo = $cc['logo'];
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
        if ($errori) {
            return ['errori' => $errori, 's' => $s];
        }
        $prima = empty($cc['dati_json']);
        $json = json_encode(['scuola' => $s, 'attivita' => $att], JSON_UNESCAPED_UNICODE);
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
