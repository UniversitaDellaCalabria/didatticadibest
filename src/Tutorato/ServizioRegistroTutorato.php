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
    /** Giorni senza attività nel registro (o dalla firma del Direttore) dopo i quali il tutor riceve il promemoria automatico. */
    public const GIORNI_REGISTRO_FERMO = 7;

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
        $candidate = $this->incarichi->firmateDi($cf, (string) ($u['email'] ?? ''), (string) ($u['persona_id'] ?? ''));
        foreach ($candidate as $i) {
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
        return $this->aggiungiDate($id, [$data], $ore, $attivita);
    }

    /**
     * Il tutor segna la stessa attività (ore e testo) in più giorni: o si segnano tutti o nessuno (un solo controllo sul totale delle ore).
     *
     * @param list<string> $date
     */
    public function aggiungiDate(int $id, array $date, mixed $ore, string $attivita): ?string
    {
        $date = array_values(array_unique(array_filter(array_map('trim', $date), static fn ($d) => $d !== '')));
        if (!$date) {
            return "Indica la data (non nel futuro).";
        }
        [$errore, $ore, $attivita] = $this->controllaRiga($this->incarichi->perId($id), $date, $ore, $attivita, 0.0);
        if ($errore !== null) {
            return $errore;
        }
        foreach ($date as $d) {
            $this->registro->aggiungi($id, $d, $ore, $attivita);
        }

        return null;
    }

    /** «Ripeti l'ultima»: stessa attività e stesse ore dell'ultima riga segnata, con la data di oggi. */
    public function ripetiUltima(int $id): ?string
    {
        $righe = $this->registro->righe($id);
        if (!$righe) {
            return "Non c'è ancora nessuna riga da ripetere.";
        }
        $u = $righe[count($righe) - 1];

        return $this->aggiungi($id, $this->orologio->adesso()->format('Y-m-d'), (string) $u['ore'], (string) $u['attivita']);
    }

    /** Il tutor corregge una riga non ancora approvata (con gli stessi controlli di aggiungi()): torna da approvare. */
    public function modifica(int $id, int $riga, string $data, mixed $ore, string $attivita): ?string
    {
        $attuale = null;
        foreach ($this->registro->righe($id) as $r) {
            if ((int) $r['id'] === $riga) {
                $attuale = $r;
            }
        }
        if ($attuale === null || $attuale['stato'] === 'approvata') {
            return "Si possono correggere solo le righe non ancora approvate.";
        }
        [$errore, $ore, $attivita] = $this->controllaRiga($this->incarichi->perId($id), [$data], $ore, $attivita, (float) $attuale['ore']);
        if ($errore !== null) {
            return $errore;
        }

        return $this->registro->correggiSeNonApprovata($id, $riga, $data, $ore, $attivita) ? null : "Non è stato possibile correggere la riga.";
    }

    /**
     * Controlli comuni a aggiungi e modifica.
     *
     * @param array<string, mixed>|null $i
     * @param list<string> $date
     * @param float $oreTolte ore della riga che si sta correggendo (non contano nel totale)
     * @return array{0: ?string, 1: float, 2: string} errore, ore, attività
     */
    private function controllaRiga(?array $i, array $date, mixed $ore, string $attivita, float $oreTolte): array
    {
        $no = static fn (string $m): array => [$m, 0.0, ''];
        if (!$i || !self::aperto($i)) {
            return $no("Il registro non è aperto per questo incarico.");
        }
        foreach ($date as $data) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || $data > $this->orologio->adesso()->format('Y-m-d')) {
                return $no("Indica la data (non nel futuro).");
            }
            if ($i['data_inizio'] && $data < $i['data_inizio']) {
                return $no("La data è prima dell'inizio dell'incarico (" . date('d/m/Y', (int) strtotime($i['data_inizio'])) . ").");
            }
        }
        $ore = DatiLettera::numeroItaliano($ore);
        if ($ore === null || $ore <= 0 || $ore > 12 || fmod($ore * 2, 1) != 0) {
            return $no("Indica le ore (da 0,5 a 12, a mezz'ore).");
        }
        $attivita = mb_substr(trim($attivita), 0, 1000);
        if ($attivita === '') {
            return $no("Scrivi l'attività svolta.");
        }
        $tot = self::ore($this->registro->righe((int) $i['id']))['totale'] - $oreTolte;
        if ($i['ore'] !== null && $tot + $ore * count($date) > (float) $i['ore'] + 0.001) {
            return $no("Con queste ore superi le " . DatiLettera::oreTesto($i['ore']) . " ore dell'incarico (già segnate: " . DatiLettera::oreTesto($tot) . ").");
        }

        return [null, $ore, $attivita];
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
        $this->notifiche->inviaModello(
            $i['docente_email'],
            'tutorato_fine_dichiarata',
            ServizioIncarichi::datiEmail($i) + ['ore' => DatiLettera::oreTesto($o['totale']), 'ore_previste' => DatiLettera::oreTesto($i['ore']), 'da_approvare' => $o['inviata'] > 0 ? ' (' . DatiLettera::oreTesto($o['inviata']) . ' da approvare)' : ''],
            $this->sito->urlBase() . '/registro_tutorato.php?id=' . $id
        );
        return null;
    }

    /** Il tutor ha il registro indietro: lettera aperta, fine attività non ancora dichiarata, meno ore segnate di quelle previste. @param array<string, mixed> $i */
    public function registroIndietro(array $i): bool
    {
        return self::aperto($i) && (string) $i['fine_stato'] === '' && self::ore($this->registro->righe((int) $i['id']))['totale'] < (float) $i['ore'];
    }

    /**
     * Le lettere di un bando i cui tutor hanno il registro indietro (per l'invito in blocco).
     *
     * @return list<int>
     */
    public function idRegistroIndietro(int $bandoId): array
    {
        $r = [];
        foreach ($this->incarichi->delBando($bandoId) as $x) {
            if (($i = $this->incarichi->perId((int) $x['id'])) && $this->registroIndietro($i) && trim((string) $i['email']) !== '') {
                $r[] = (int) $i['id'];
            }
        }

        return $r;
    }

    /** Pulsante manuale: invita il tutor a compilare il registro. Ritorna un errore o null. */
    public function invitaARegistro(int $id, string $autore = ''): ?string
    {
        $i = $this->incarichi->perId($id);
        if (!$i) {
            return 'Lettera non trovata.';
        }
        if (!self::aperto($i) || (string) $i['fine_stato'] !== '') {
            return 'Il registro si compila dopo la firma del Direttore e fino a quando il tutor non dichiara concluse le attività.';
        }
        if (trim((string) $i['email']) === '') {
            return 'La lettera non ha l\'indirizzo email del tutor.';
        }
        $this->notifiche->inviaModello(
            $i['email'],
            'tutorato_invito_registro',
            ServizioIncarichi::datiEmail($i) + ['ore_segnate' => DatiLettera::oreTesto(self::ore($this->registro->righe($id))['totale']), 'ore_previste' => DatiLettera::oreTesto($i['ore'])],
            $this->sito->urlBase() . '/registro_tutorato.php?id=' . $id
        );
        $this->lettere->evento($id, 'invito_registro', 'Invito a compilare il registro inviato a ' . $i['email'], $autore);

        return null;
    }

    /**
     * Invito in blocco: a tutti i tutor del bando che hanno il registro indietro.
     *
     * @return int quanti inviti sono partiti
     */
    public function invitaARegistroIlBando(int $bandoId, string $autore = ''): int
    {
        $n = 0;
        foreach ($this->idRegistroIndietro($bandoId) as $id) {
            if ($this->invitaARegistro($id, $autore) === null) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Pulsante manuale: il portale prepara la dichiarazione di fine attività (le ore devono essere tutte approvate) e il docente riceve
     * l'email per firmarla. Se la dichiarazione è già pronta e aspetta la firma, l'email si rimanda con lo stesso link.
     */
    public function inviaFineAlDocente(int $id, string $autore = ''): ?string
    {
        $i = $this->incarichi->perId($id);
        if (!$i) {
            return 'Lettera non trovata.';
        }
        if (trim((string) $i['docente_email']) === '') {
            return 'La lettera non ha l\'indirizzo email del docente responsabile.';
        }
        $rimandata = (string) $i['fine_stato'] === 'da_firmare';
        if ($rimandata) {
            $tok = (string) $i['token_fine'];
        } else {
            [$tok, $err] = $this->confermaFine($id, $autore);
            if ($err !== null) {
                return $err;
            }
            $i = $this->incarichi->perId($id) ?? $i;
        }
        $this->notifiche->inviaModello(
            $i['docente_email'],
            'tutorato_docente_fine_da_firmare',
            ServizioIncarichi::datiEmail($i) + ['ore' => DatiLettera::oreTesto($i['ore_approvate']), 'ore_previste' => DatiLettera::oreTesto($i['ore'])],
            $this->sito->urlBase() . '/firma_incarico.php?t=' . $tok
        );
        $this->lettere->evento($id, 'fine_al_docente', ($rimandata ? 'Email di nuovo al docente' : 'Dichiarazione di fine attività inviata al docente') . ' per la firma: ' . $i['docente_email'], $autore);

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
        $dati = ServizioIncarichi::datiEmail($i) + ['ore' => DatiLettera::oreTesto($i['ore_approvate']), 'ore_previste' => DatiLettera::oreTesto($i['ore'])];
        foreach ($this->notifiche->emailOperatori($i) as $e) {
            $this->notifiche->inviaModello(
                $e,
                'tutorato_fine_firmata_operatore',
                $dati + ['tabella' => $tab],
                $this->sito->urlBase() . '/admin/tutorato.php?vista=validare',
                $file ? [['path' => $file, 'nome' => 'FINE_ATTIVITA_' . str_replace('LETTERA_INCARICO_', '', DatiLettera::nomeFile($i))]] : []
            );
        }
        $this->notifiche->inviaModello($i['email'], 'tutorato_attivita_completate', $dati);
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
                $modello = null;
                $dati = ServizioIncarichi::datiEmail($i) + ['ore_segnate' => DatiLettera::oreTesto(self::ore($reg)['totale']), 'ore_previste' => DatiLettera::oreTesto($i['ore'])];
                if ($i['data_fine'] && $oggi >= date('Y-m-d', (int) strtotime($i['data_fine'] . ' -7 days'))) {
                    $modello = 'tutorato_promemoria_registro_scadenza';
                    $dati['periodo'] = ($oggi > $i['data_fine'] ? 'è terminato il ' : 'termina il ') . date('d/m/Y', (int) strtotime($i['data_fine']));
                } elseif ((!$i['data_inizio'] || $oggi >= $i['data_inizio']) && (!$ultima || $ultima < $this->orologio->adesso()->modify('-' . self::GIORNI_REGISTRO_FERMO . ' days')->format('Y-m-d')) && ($i['firmata_direttore_il'] !== null && strtotime($i['firmata_direttore_il']) < $ora - self::GIORNI_REGISTRO_FERMO * 86400)) {
                    $modello = 'tutorato_promemoria_registro_fermo';
                }
                if ($modello) {
                    $this->notifiche->inviaModello($i['email'], $modello, $dati, $link_reg);
                    $this->incarichi->segnaPromemoriaTutor($id);
                    $n++;
                }
            }
            // 2. Docente: ore da approvare da più di 7 giorni o fine attività dichiarata dal tutor da più di 3 giorni
            if (self::aperto($i) && (!$i['promemoria_docente_il'] || strtotime($i['promemoria_docente_il']) < $ora - 7 * 86400)) {
                $vecchie = $this->registro->contaDaApprovareVecchie($id);
                $fine = $i['fine_stato'] === 'richiesta' && strtotime((string) $i['fine_richiesta_il']) < $ora - 3 * 86400;
                if ($vecchie || $fine) {
                    $this->notifiche->inviaModello($i['docente_email'], $fine ? 'tutorato_promemoria_docente_fine' : 'tutorato_promemoria_docente_ore', ServizioIncarichi::datiEmail($i), $link_reg);
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
                    $this->notifiche->inviaModello($ferma[2], 'tutorato_sollecito_firma', ServizioIncarichi::datiEmail($i) + ['documento' => $ferma[4], 'data' => date('d/m/Y', (int) strtotime($ferma[1]))], $ferma[3]);
                    $this->incarichi->segnaSollecito($id);
                    $n++;
                    $this->lettere->evento($id, 'sollecito', 'Sollecito della firma inviato a ' . $ferma[2]);
                    // Al terzo sollecito lo sa anche l'operatore
                    if ((int)$i['solleciti'] + 1 >= 3) {
                        foreach ($this->notifiche->emailOperatori($i) as $e) {
                            $this->notifiche->inviaModello($e, 'tutorato_firma_ferma', ServizioIncarichi::datiEmail($i) + ['documento' => $ferma[4], 'firmatario' => $ferma[2]], $this->sito->urlBase() . '/admin/tutorato.php?incarico=' . $id);
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
     * metadati dell'accesso SPID/CIE), i PDF (UNILAV compreso), il registro e la dichiarazione individuale con i dati bancari. Restano codice, nome e cognome, bando, ore, compenso, protocolli e storico:
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
            $this->incarichi->eliminaDichiarazione((int)$i['id']);
            $this->incarichi->anonimizza((int)$i['id']);
            $this->incarichi->cancellaIpEventi((int)$i['id']);
            $this->lettere->evento((int)$i['id'], 'conservazione', "Dati personali, PDF e registro cancellati dopo $mesi mesi (conservazione)");
            $n++;
        }
        return $n;
    }
}
