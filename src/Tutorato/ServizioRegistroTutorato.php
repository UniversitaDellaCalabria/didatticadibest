<?php

declare(strict_types=1);

namespace App\Tutorato;

use App\Core\Orologio;
use App\Core\Sito;
use App\Sistema\FileEnv;

/**
 * Tutorato dopo la firma della lettera di incarico: registro delle attività (il tutor segna giorno, ore e attività, il docente
 * responsabile approva o respinge), fine attività (il tutor dichiara concluse le attività, il docente conferma e il portale
 * prepara la «dichiarazione di fine attività» con le ore approvate; il docente la firma in PAdES e l'operatore la protocolla),
 * promemoria del cron (registro, ore da approvare, solleciti delle firme ferme) e conservazione dei dati.
 */
final class ServizioRegistroTutorato
{
    public function __construct(
        private IncaricoRepository $incarichi,
        private RegistroRepository $registro,
        private ServizioIncarichi $lettere,
        private DocumentiIncarico $documenti,
        private ArchivioIncarichi $archivio,
        private NotificheIncarichi $notifiche,
        private VerificaPdfFirmato $verifica,
        private Sito $sito,
        private Orologio $orologio,
        private FileEnv $env
    ) {
    }

    /**
     * Righe del registro della lettera, in ordine di data.
     *
     * @return list<array<string, mixed>>
     */
    public function righe(int $id): array
    {
        return $this->registro->righe($id);
    }

    /**
     * Ore per stato: ['inviata' => …, 'approvata' => …, 'respinta' => …, 'totale' => inviate + approvate].
     *
     * @param list<array<string, mixed>> $righe
     * @return array<string, float>
     */
    public static function ore(array $righe): array
    {
        $o = ['inviata' => 0.0, 'approvata' => 0.0, 'respinta' => 0.0];
        foreach ($righe as $r) {
            $o[$r['stato']] = ($o[$r['stato']] ?? 0) + (float) $r['ore'];
        }

        return $o + ['totale' => $o['inviata'] + $o['approvata']];
    }

    /**
     * Il registro si compila dopo la firma del direttore e finché il docente non conferma la fine delle attività.
     *
     * @param array<string, mixed> $i
     */
    public static function aperto(array $i): bool
    {
        return in_array($i['stato'], ['firmata', 'protocollata'], true) && in_array((string) $i['fine_stato'], ['', 'richiesta'], true);
    }

    /**
     * Incarichi visibili nel registro: come tutor (codice fiscale dell'accesso) o come docente responsabile.
     *
     * @param array<string, mixed>|null $u
     * @return list<array<string, mixed>>
     */
    public function incarichiRegistro(?array $u): array
    {
        if (!$u || empty($u['id'])) {
            return [];
        }
        $out = [];
        $cf = strtoupper(trim((string)($u['codice_fiscale'] ?? '')));
        foreach ($this->incarichi->idFirmate() as $idLettera) {
            $i = $this->incarichi->perId($idLettera);
            if (!$i) {
                continue;
            }
            if ($cf !== '' && $cf === strtoupper((string) $i['codice_fiscale'])) {
                $out[] = $i + ['_ruolo' => 'tutor'];
            } elseif ($this->lettere->firmatario($i, 'docente', $u)) {
                $out[] = $i + ['_ruolo' => 'docente'];
            }
        }
        return $out;
    }

    /** Il tutor segna un giorno di attività. Ritorna un errore o null. */
    public function aggiungi(int $id, string $data, mixed $ore, string $attivita): ?string
    {
        $i = $this->incarichi->perId($id);
        if (!$i || !self::aperto($i)) {
            return "Il registro non è aperto per questo incarico.";
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || $data > $this->orologio->adesso()->format('Y-m-d')) {
            return "Indica la data (non nel futuro).";
        }
        if ($i['data_inizio'] && $data < $i['data_inizio']) {
            return "La data è prima dell'inizio dell'incarico (" . date('d/m/Y', (int) strtotime($i['data_inizio'])) . ").";
        }
        $ore = DatiLettera::numeroItaliano($ore);
        if ($ore === null || $ore <= 0 || $ore > 12 || fmod($ore * 2, 1) != 0) {
            return "Indica le ore (da 0,5 a 12, a mezz'ore).";
        }
        $attivita = mb_substr(trim($attivita), 0, 1000);
        if ($attivita === '') {
            return "Scrivi l'attività svolta.";
        }
        $tot = self::ore($this->registro->righe($id))['totale'];
        if ($i['ore'] !== null && $tot + $ore > (float)$i['ore'] + 0.001) {
            return "Con queste ore superi le " . DatiLettera::oreTesto($i['ore']) . " ore dell'incarico (già segnate: " . DatiLettera::oreTesto($tot) . ").";
        }
        $this->registro->aggiungi($id, $data, $ore, $attivita);
        return null;
    }

    public function togli(int $id, int $riga): ?string
    {
        $i = $this->incarichi->perId($id);
        if (!$i || !self::aperto($i)) {
            return "Il registro non è aperto.";
        }
        return $this->registro->togliSeDaApprovare($id, $riga) ? null : "Si possono togliere solo le righe non ancora approvate.";
    }

    /**
     * Il docente approva o respinge righe del registro ($righe = id; vuoto = tutte quelle da approvare).
     *
     * @param list<int> $righe
     */
    public function decidi(int $id, string $esito, array $righe = [], string $nota = ''): int
    {
        $esito = $esito === 'respinta' ? 'respinta' : 'approvata';
        $n = 0;
        foreach ($this->registro->righe($id) as $r) {
            if ($r['stato'] !== 'inviata' || ($righe && !in_array((int)$r['id'], array_map('intval', $righe), true))) {
                continue;
            }
            $n += ($this->registro->decidi((int)$r['id'], $esito, mb_substr(trim($nota), 0, 500)));
        }
        return $n;
    }

    /** Il tutor dichiara concluse le attività: il docente riceve l'email per approvare le ore e confermare. */
    public function richiediFine(int $id): ?string
    {
        $i = $this->incarichi->perId($id);
        if (!$i || !self::aperto($i) || $i['fine_stato'] !== '') {
            return "Le attività non si possono dichiarare concluse ora.";
        }
        if (!$this->registro->righe($id)) {
            return "Prima segna nel registro le attività svolte.";
        }
        $this->incarichi->segnaFineRichiesta($id);
        $this->lettere->evento($id, 'fine_richiesta', 'Il tutor ha dichiarato concluse le attività', trim($i['nome'] . ' ' . $i['cognome']));
        $o = self::ore($this->registro->righe($id));
        $h = fn ($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $this->notifiche->invia(
            $i['docente_email'],
            "Tutorato: fine attività di " . trim($i['cognome'] . ' ' . $i['nome']),
            "<p>Gentile " . $h($i['docente_titolo'] . ' ' . trim($i['docente_nome'] . ' ' . $i['docente_cognome'])) . ",</p><p>" . $h(trim($i['nome'] . ' ' . $i['cognome'])) . " ha dichiarato concluse le attività di tutorato (" . $h($i['bando_titolo']) . ") di cui è responsabile.</p>"
            . "<p>Ore nel registro: <strong>" . DatiLettera::oreTesto($o['totale']) . "</strong> su " . DatiLettera::oreTesto($i['ore']) . ($o['inviata'] > 0 ? " (" . DatiLettera::oreTesto($o['inviata']) . " da approvare)" : '') . ".</p>"
            . "<p>Controlli il registro, approvi le ore e confermi la fine delle attività: il portale prepara la dichiarazione di fine attività da firmare in PAdES.</p>",
            $this->sito->urlBase() . '/registro_tutorato.php?id=' . $id,
            'Apri il registro'
        );
        return null;
    }

    /**
     * Il docente conferma la fine: ore approvate, dichiarazione in PDF da firmare (link firma_incarico.php?t=token_fine).
     *
     * @return array{0: string|null, 1: string|null} [token, errore]
     */
    public function confermaFine(int $id, string $autore = ''): array
    {
        $i = $this->incarichi->perId($id);
        if (!$i || !in_array($i['stato'], ['firmata', 'protocollata'], true) || !in_array((string)$i['fine_stato'], ['', 'richiesta'], true)) {
            return [null, "La fine delle attività non si può confermare ora."];
        }
        $reg = $this->registro->righe($id);
        $o = self::ore($reg);
        if ($o['inviata'] > 0) {
            return [null, "Ci sono ancora " . DatiLettera::oreTesto($o['inviata']) . " ore da approvare o respingere."];
        }
        if ($o['approvata'] <= 0) {
            return [null, "Nel registro non ci sono ore approvate."];
        }
        [$pdf] = $this->documenti->pdfFine($i, $reg, $o['approvata']);
        $rel = $this->archivio->scrivi((string)$i['codice'], 'fine', $pdf);
        if ($rel === null) {
            return [null, "Non è stato possibile salvare la dichiarazione."];
        }
        $tok = bin2hex(random_bytes(20));
        $this->incarichi->segnaFineDaFirmare($id, $rel, $tok, (float)$o['approvata']);
        $this->lettere->evento($id, 'fine_confermata', 'Fine attività confermata dal docente: ' . DatiLettera::oreTesto($o['approvata']) . ' ore approvate; dichiarazione da firmare', $autore);
        return [$tok, null];
    }

    /** Firma PAdES del docente sulla dichiarazione di fine attività: avviso «attività completate» all'operatore (con il PDF) e al tutor. */
    public function registraFirmaFine(int $id, string $pdf, string $come = 'caricamento'): ?string
    {
        $i = $this->incarichi->perId($id);
        if (!$i || $i['fine_stato'] !== 'da_firmare' || !($cor = $this->archivio->fineCorrente($i))) {
            return "La dichiarazione non è in attesa di firma.";
        }
        [$err, $info] = $this->verifica->verifica((string)file_get_contents($cor), $pdf, (string)$i['docente_cf']);
        if ($err) {
            return $err;
        }
        $rel = $this->archivio->scrivi((string)$i['codice'], 'fine_firmata', $pdf);
        if ($rel === null) {
            return "Non è stato possibile salvare il PDF firmato.";
        }
        $this->incarichi->segnaFineFirmata($id, $rel);
        $this->lettere->evento($id, 'fine_firmata', "Dichiarazione di fine attività firmata in PAdES ($come" . ($info['nome'] !== '' ? ', certificato di ' . $info['nome'] : '') . ')', $i['docente_titolo'] . ' ' . trim($i['docente_nome'] . ' ' . $i['docente_cognome']));
        $i = $this->incarichi->perId($id) ?? $i;
        $reg = $this->registro->righe($id);
        $h = fn ($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $tab = "<table style='border-collapse:collapse;font-size:13px;'><tr><th style='text-align:left;padding:3px 8px;border-bottom:1px solid #cbd5e1;'>Data</th><th style='padding:3px 8px;border-bottom:1px solid #cbd5e1;'>Ore</th><th style='text-align:left;padding:3px 8px;border-bottom:1px solid #cbd5e1;'>Attività</th></tr>";
        foreach ($reg as $r) {
            if ($r['stato'] === 'approvata') {
                $tab .= "<tr><td style='padding:3px 8px;'>" . date('d/m/Y', strtotime($r['data'])) . "</td><td style='padding:3px 8px;text-align:center;'>" . DatiLettera::oreTesto($r['ore']) . "</td><td style='padding:3px 8px;'>" . $h($r['attivita']) . "</td></tr>";
            }
        }
        $tab .= "</table>";
        $file = $this->archivio->fineCorrente($i);
        foreach ($this->notifiche->emailOperatori($i) as $e) {
            $this->notifiche->invia(
                $e,
                "Attività completate: " . trim($i['cognome'] . ' ' . $i['nome']) . " – " . DatiLettera::oreTesto($i['ore_approvate']) . " ore",
                "<p>Il tutor <strong>" . $h(trim($i['nome'] . ' ' . $i['cognome'])) . "</strong> (" . $h($i['bando_titolo']) . ") ha completato le attività: <strong>" . DatiLettera::oreTesto($i['ore_approvate']) . " ore</strong> approvate su " . DatiLettera::oreTesto($i['ore']) . ".</p>"
                . "<p>La dichiarazione di fine attività è firmata in PAdES dal docente responsabile: la trovi in allegato e nel pannello, da protocollare.</p>" . $tab,
                $this->sito->urlBase() . '/admin/tutorato.php?incarico=' . $id,
                'Apri nel pannello',
                $file ? [['path' => $file, 'nome' => 'FINE_ATTIVITA_' . str_replace('LETTERA_INCARICO_', '', DatiLettera::nomeFile($i))]] : []
            );
        }
        $this->notifiche->invia(
            $i['email'],
            "Tutorato: attività completate",
            "<p>Gentile " . $h($i['nome'] . ' ' . $i['cognome']) . ",</p><p>il docente responsabile ha confermato la fine delle tue attività di tutorato: <strong>" . DatiLettera::oreTesto($i['ore_approvate']) . " ore</strong>. L'Ufficio procede con gli adempimenti per il compenso.</p>"
        );
        return null;
    }

    public function protocollaFine(int $id, string $prot, string $autore = ''): ?string
    {
        $i = $this->incarichi->perId($id);
        $prot = mb_substr(trim($prot), 0, 100);
        if (!$i || !in_array($i['fine_stato'], ['firmata', 'protocollata'], true)) {
            return "La dichiarazione non è ancora firmata.";
        }
        if ($prot === '') {
            return "Scrivi il numero di protocollo.";
        }
        $this->incarichi->segnaFineProtocollata($id, $prot);
        $this->lettere->evento($id, 'fine_protocollo', 'Dichiarazione di fine attività protocollata: ' . $prot, $autore);
        return null;
    }

    /**
     * Dati che servono dopo l'invio della lettera: insegnamento e corso del docente, titolo, periodo (per i promemoria).
     *
     * @param array<string, mixed> $d
     */
    public function salvaDatiFine(int $id, array $d): ?string
    {
        $i = $this->incarichi->perId($id);
        if (!$i || in_array($i['fine_stato'], ['da_firmare', 'firmata', 'protocollata'], true)) {
            return "La dichiarazione di fine attività è già stata preparata.";
        }
        $dt = fn ($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($d[$k] ?? '')) ? $d[$k] : null;
        $tit = in_array($d['docente_titolo'] ?? '', Costanti::TITOLI_DOCENTE, true) ? $d['docente_titolo'] : 'Prof.';
        if ($dt('data_inizio') && $dt('data_fine') && $dt('data_fine') < $dt('data_inizio')) {
            return "La fine del periodo è prima dell'inizio.";
        }
        $this->incarichi->salvaDatiFine($id, mb_substr(trim((string)($d['insegnamento_docente'] ?? '')), 0, 255), mb_substr(trim((string)($d['corso_laurea'] ?? '')), 0, 255), $tit, $dt('data_inizio'), $dt('data_fine'));
        return null;
    }

    /** Promemoria del registro e solleciti delle firme ferme (cron giornaliero). Ritorna le email inviate. */
    public function promemoria(): int
    {
        $n = 0;
        $ora = $this->orologio->adesso()->getTimestamp();
        $oggi = $this->orologio->adesso()->format('Y-m-d');
        $h = fn ($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $gg = max(1, (int)($this->env->valore('FIRME_GIORNI_SOLLECITO') ?? 5));
        foreach ($this->incarichi->idDaSeguire() as $idLettera) {
            $i = $this->incarichi->perId($idLettera);
            if (!$i) {
                continue;
            }
            $id = (int)$i['id'];
            $link_reg = $this->sito->urlBase() . '/registro_tutorato.php?id=' . $id;
            // 1. Tutor: registro da aggiornare, chiusura verso la fine del periodo (al massimo un promemoria ogni 7 giorni)
            if (self::aperto($i) && $i['fine_stato'] === '' && (!$i['promemoria_tutor_il'] || strtotime($i['promemoria_tutor_il']) < $ora - 7 * 86400)) {
                $reg = $this->registro->righe($id);
                $ultima = $reg ? max(array_column($reg, 'data') ?: [null]) : null;
                $testo = null;
                if ($i['data_fine'] && $oggi >= date('Y-m-d', (int) strtotime($i['data_fine'] . ' -7 days'))) {
                    $testo = ($oggi > $i['data_fine'] ? "il periodo dell'incarico è terminato il " : "il periodo dell'incarico termina il ") . date('d/m/Y', (int) strtotime($i['data_fine']))
                           . ": completa il registro delle attività e, quando hai finito, clicca su <strong>«Ho concluso le attività»</strong>. Il docente responsabile confermerà le ore.";
                } elseif ((!$i['data_inizio'] || $oggi >= $i['data_inizio']) && (!$ultima || $ultima < $this->orologio->adesso()->modify('-14 days')->format('Y-m-d')) && ($i['firmata_direttore_il'] !== null && strtotime($i['firmata_direttore_il']) < $ora - 14 * 86400)) {
                    $testo = "non segni attività nel registro da più di due settimane: aggiornalo con i giorni, le ore e le attività svolte.";
                }
                if ($testo) {
                    $this->notifiche->invia($i['email'], "Tutorato: registro delle attività", "<p>Gentile " . $h($i['nome'] . ' ' . $i['cognome']) . ",</p><p>" . $testo . "</p><p>Ore segnate: " . DatiLettera::oreTesto(self::ore($reg)['totale']) . " su " . DatiLettera::oreTesto($i['ore']) . ".</p>", $link_reg, 'Apri il registro');
                    $this->incarichi->segnaPromemoriaTutor($id);
                    $n++;
                }
            }
            // 2. Docente: ore da approvare da più di 7 giorni o fine attività dichiarata dal tutor da più di 3 giorni
            if (self::aperto($i) && (!$i['promemoria_docente_il'] || strtotime($i['promemoria_docente_il']) < $ora - 7 * 86400)) {
                $vecchie = $this->registro->contaDaApprovareVecchie($id);
                $fine = $i['fine_stato'] === 'richiesta' && strtotime((string) $i['fine_richiesta_il']) < $ora - 3 * 86400;
                if ($vecchie || $fine) {
                    $this->notifiche->invia(
                        $i['docente_email'],
                        "Tutorato: registro di " . trim($i['cognome'] . ' ' . $i['nome']) . " da controllare",
                        "<p>Nel registro delle attività di tutorato di <strong>" . $h(trim($i['nome'] . ' ' . $i['cognome'])) . "</strong> " . ($fine ? "il tutor ha dichiarato concluse le attività: approvi le ore e confermi la fine." : "ci sono ore da approvare.") . "</p>",
                        $link_reg,
                        'Apri il registro'
                    );
                    $this->incarichi->segnaPromemoriaDocente($id);
                    $n++;
                }
            }
            // 3. Solleciti delle firme ferme: docente e direttore sulla lettera, docente sulla dichiarazione di fine attività
            $ferma = match (true) {
                $i['stato'] === 'confermata' => ['docente', $i['confermata_il'], $i['docente_email'], $this->sito->urlBase() . '/firma_incarico.php?t=' . $i['token_docente'], 'la lettera di incarico'],
                $i['stato'] === 'firmata_docente' => ['direttore', $i['firmata_docente_il'], $i['direttore_email'], $this->sito->urlBase() . '/firma_incarico.php?t=' . $i['token_direttore'], 'la lettera di incarico'],
                $i['fine_stato'] === 'da_firmare' => ['fine', $i['aggiornata_il'], $i['docente_email'], $this->sito->urlBase() . '/firma_incarico.php?t=' . $i['token_fine'], 'la dichiarazione di fine attività'],
                default => null,
            };
            if ($ferma && $ferma[1]) {
                $dal = strtotime((string)($i['sollecito_il'] ?: $ferma[1]));
                if ($dal < $ora - $gg * 86400 && (int)$i['solleciti'] < 3) {
                    $this->notifiche->invia(
                        $ferma[2],
                        "Sollecito: " . $ferma[4] . " di " . trim($i['cognome'] . ' ' . $i['nome']) . " è in attesa della sua firma",
                        "<p>Gentile,</p><p>" . $h($ferma[4]) . " di tutorato di <strong>" . $h(trim($i['nome'] . ' ' . $i['cognome'])) . "</strong> (" . $h($i['bando_titolo']) . ") aspetta la sua firma digitale in PAdES dal " . date('d/m/Y', (int) strtotime($ferma[1])) . ".</p>",
                        $ferma[3],
                        'Apri e firma'
                    );
                    $this->incarichi->segnaSollecito($id);
                    $n++;
                    $this->lettere->evento($id, 'sollecito', 'Sollecito della firma inviato a ' . $ferma[2]);
                    // Al terzo sollecito lo sa anche l'operatore
                    if ((int)$i['solleciti'] + 1 >= 3) {
                        foreach ($this->notifiche->emailOperatori($i) as $e) {
                            $this->notifiche->invia(
                                $e,
                                "Firma ferma: " . $ferma[4] . " di " . trim($i['cognome'] . ' ' . $i['nome']),
                                "<p>Dopo tre solleciti " . $h($ferma[4]) . " non è ancora firmata da " . $h($ferma[2]) . ": serve un contatto diretto.</p>",
                                $this->sito->urlBase() . '/admin/tutorato.php?incarico=' . $id,
                                'Apri nel pannello'
                            );
                        }
                    }
                }
            }
        }
        return $n;
    }

    /**
     * Conservazione (CONSERVAZIONE_INCARICHI_MESI nel .env, 0 = mai; durata da concordare con il DPO): le lettere protocollate (con la
     * fine attività protocollata, se c'è) o annullate da più di $mesi mesi perdono i dati personali (nascita, residenza, contatti,
     * metadati dell'accesso SPID/CIE), i PDF e il registro. Restano codice, nome e cognome, bando, ore, compenso, protocolli e storico:
     * gli originali firmati sono nel protocollo di Ateneo.
     */
    public function conserva(int $mesi): int
    {
        if ($mesi <= 0) {
            return 0;
        }
        $n = 0;
        foreach ($this->incarichi->idDaConservare($mesi) as $idLettera) {
            $i = $this->incarichi->perId($idLettera);
            if (!$i) {
                continue;
            }
            $this->archivio->eliminaDellaLettera($i);
            $this->registro->eliminaDellaLettera((int)$i['id']);
            $this->incarichi->anonimizza((int)$i['id']);
            $this->incarichi->cancellaIpEventi((int)$i['id']);
            $this->lettere->evento((int)$i['id'], 'conservazione', "Dati personali, PDF e registro cancellati dopo $mesi mesi (conservazione)");
            $n++;
        }
        return $n;
    }
}
