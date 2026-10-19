<?php

declare(strict_types=1);

namespace App\Tutorato;

use App\Ateneo\ServizioUfficiAteneo;
use App\Core\Orologio;
use App\Core\Sito;
use App\Infrastructure\Pdf\Documento;

/**
 * Ultima parte dell'iter del tutorato: il referente controlla la pratica (decreti, UNILAV, dichiarazione individuale, ore e compenso) e la passa
 * all'ufficio pagamenti, una per volta o tutte insieme; l'ufficio la lavora e segna «pagato» (con data e riferimento) oppure la rimanda al
 * referente con una nota. Il referente può anche rimandare la fine attività al docente con il motivo. A pagamento disposto il tutor riceve l'email.
 */
final class ServizioPagamenti
{
    public function __construct(
        private IncaricoRepository $incarichi,
        private BandoRepository $bandi,
        private RegistroRepository $registro,
        private ServizioDichiarazione $dichiarazione,
        private ServizioIncarichi $lettere,
        private ServizioUfficiAteneo $uffici,
        private ArchivioIncarichi $archivio,
        private FileTutorato $file,
        private NotificheIncarichi $notificheLettere,
        private NotifichePagamenti $notifiche,
        private Sito $sito,
        private Orologio $orologio
    ) {
    }

    // ------------------------------------------------------------------ referente

    /**
     * Cosa manca perché la pratica si possa passare all'ufficio pagamenti (vuoto = tutto presente).
     *
     * @param array<string, mixed> $i lettera con i dati del bando
     * @return list<string>
     */
    public function controlli(array $i): array
    {
        $m = [];
        if ((string) ($i['iter'] ?? Costanti::ITER_PORTALE) === Costanti::ITER_UNITICKET) {
            $m[] = 'la lettera segue l\'iter per Uniticket: i pagamenti non passano dal portale';
        }
        if ((string) $i['stato'] !== 'protocollata') {
            $m[] = 'la lettera di incarico non è ancora protocollata';
        }
        if (!in_array((string) $i['fine_stato'], ['firmata', 'protocollata'], true) || $i['ore_approvate'] === null) {
            $m[] = 'la fine attività non è ancora firmata dal docente titolare';
        }
        if ($this->dichiarazione->richiesta() && !$this->dichiarazione->stato((int) $i['id'])['completa']) {
            $m[] = 'la dichiarazione individuale non è compilata';
        }
        foreach (['decreto_bando_file' => 'il decreto del bando', 'decreto_commissione_file' => 'il decreto della commissione', 'decreto_atti_file' => 'il decreto di approvazione atti'] as $k => $t) {
            if (empty($i[$k])) {
                $m[] = "manca $t (PDF)";
            }
        }
        if (empty($i['unilav_file'])) {
            $m[] = "manca l'UNILAV";
        } elseif ($i['ore_approvate'] !== null && !Compenso::stesseOre($i['unilav_ore'] !== null ? (float) $i['unilav_ore'] : null, (float) $i['ore_approvate'])) {
            $m[] = "l'UNILAV è da aggiornare: riporta " . DatiLettera::oreTesto($i['unilav_ore']) . ' ore, quelle approvate sono ' . DatiLettera::oreTesto($i['ore_approvate']);
        }
        if (empty($i['ufficio_ateneo_id'])) {
            $m[] = "nel bando manca l'ufficio di Ateneo che paga";
        }

        return $m;
    }

    /**
     * Ore, lordo per l'Ente e compenso in lettera (÷ 1,24) sulle ore approvate, con quanto scritto nella lettera.
     *
     * @param array<string, mixed> $i
     * @return array{ore: float, lordo: float, lettera: float, lettera_originale: float, cambiato: bool}
     */
    public function compenso(array $i): array
    {
        $ore = $i['ore_approvate'] !== null ? (float) $i['ore_approvate'] : (float) $i['ore'];
        $orig = (float) $i['compenso'];
        $lett = Compenso::lettera($ore);

        return ['ore' => $ore, 'lordo' => Compenso::lordo($ore), 'lettera' => $lett, 'lettera_originale' => $orig, 'cambiato' => !Compenso::stesseOre($ore, (float) $i['ore'])];
    }

    /**
     * Pratiche da validare (fine attività firmata dal docente) con i controlli e il compenso di ciascuna.
     *
     * @return list<array<string, mixed>>
     */
    public function daValidare(): array
    {
        $out = [];
        foreach ($this->incarichi->daValidare() as $i) {
            $i['_controlli'] = $this->controlli($i);
            $i['_compenso'] = $this->compenso($i);
            $out[] = $i;
        }

        return $out;
    }

    /**
     * Passa le pratiche all'ufficio pagamenti. Chi non è pronta resta dov'è con il motivo; per ogni ufficio parte una sola email con l'elenco.
     *
     * @param list<int> $ids
     * @return array{passate: int, errori: array<int, string>}
     */
    public function passa(array $ids, string $da): array
    {
        $errori = [];
        $perUfficio = [];
        foreach (array_values(array_unique(array_filter(array_map('intval', $ids)))) as $id) {
            $i = $this->incarichi->perId($id);
            if (!$i || !in_array((string) $i['pagamento_stato'], ['', 'rimandata'], true)) {
                $errori[$id] = 'la pratica non è da passare ai pagamenti';
                continue;
            }
            if ($m = $this->controlli($i)) {
                $errori[$id] = implode('; ', $m);
                continue;
            }
            $this->incarichi->passaAiPagamenti($id, $da, $this->compenso($i)['lettera']);
            $this->lettere->evento($id, 'pagamento_passato', 'Validata dal referente e passata all\'ufficio pagamenti (compenso € ' . Compenso::testo($this->compenso($i)['lettera']) . ')', $da);
            $perUfficio[(int) $i['ufficio_ateneo_id']][] = $this->incarichi->perId($id) ?? $i;
        }
        foreach ($perUfficio as $ufficioId => $pratiche) {
            $u = $this->uffici->ufficio($ufficioId);
            $this->notifiche->alUfficio($this->uffici->emailDelUfficio($ufficioId), (string) ($u['nome'] ?? 'Ufficio pagamenti'), $pratiche, $da);
        }

        return ['passate' => array_sum(array_map('count', $perUfficio)), 'errori' => $errori];
    }

    /** Il referente rimanda la fine attività al docente con il motivo (obbligatorio): il docente riceve l'email e deve rifirmare. Ritorna l'errore o null. */
    public function rimandaAlDocente(int $id, string $motivo, string $da): ?string
    {
        $i = $this->incarichi->perId($id);
        $motivo = trim($motivo);
        if (!$i || !in_array((string) $i['fine_stato'], ['firmata', 'protocollata'], true) || in_array((string) $i['pagamento_stato'], ['in_ufficio', 'pagata'], true)) {
            return 'Questa pratica non si può rimandare al docente.';
        }
        if ($motivo === '') {
            return 'Scrivi il motivo: il docente lo riceve per email.';
        }
        $this->incarichi->riapriFine($id);
        $this->lettere->evento($id, 'fine_rimandata', 'Fine attività rimandata al docente: ' . mb_substr($motivo, 0, 300), $da);
        $this->notifiche->rimandataAlDocente($i, mb_substr($motivo, 0, 500));

        return null;
    }

    // ------------------------------------------------------------------ ufficio pagamenti

    /**
     * Pratiche dell'ufficio (da pagare o pagate) con compenso e controlli.
     *
     * @param list<string> $stati in_ufficio | pagata
     * @return list<array<string, mixed>>
     */
    public function dellUfficio(int $ufficioId, array $stati): array
    {
        $out = [];
        foreach ($this->incarichi->perUfficio($ufficioId, $stati) as $i) {
            $i['_compenso'] = $this->compenso($i);
            $out[] = $i;
        }

        return $out;
    }

    /** Quante pratiche aspettano di essere pagate dagli uffici della persona. @param array<string, mixed>|null $utente */
    public function daPagare(?array $utente): int
    {
        $n = 0;
        foreach ($this->uffici->ufficiDellUtente($utente) as $u) {
            $n += count($this->incarichi->perUfficio((int) $u['id'], ['in_ufficio']));
        }

        return $n;
    }

    /** La persona è dell'ufficio che paga questa pratica. @param array<string, mixed>|null $utente @param array<string, mixed> $i */
    public function dellUfficioDi(?array $utente, array $i): bool
    {
        return !empty($i['ufficio_ateneo_id']) && $this->uffici->utenteMembro($utente, (int) $i['ufficio_ateneo_id']);
    }

    /**
     * Pagamento disposto: data e riferimento obbligatori; il tutor riceve l'email. Ritorna l'errore o null.
     *
     * @param array<string, mixed>|null $utente
     */
    public function pagata(int $id, string $data, string $riferimento, ?array $utente): ?string
    {
        $i = $this->incarichi->perId($id);
        if (!$i || !$this->dellUfficioDi($utente, $i)) {
            return 'Non puoi lavorare questa pratica.';
        }
        if ((string) $i['pagamento_stato'] !== 'in_ufficio') {
            return 'La pratica non è da pagare.';
        }
        $riferimento = mb_substr(trim($riferimento), 0, 100);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || strtotime($data) === false) {
            return 'Indica la data del pagamento.';
        }
        if ($data > $this->orologio->adesso()->format('Y-m-d')) {
            return 'La data del pagamento non può essere nel futuro.';
        }
        if ($riferimento === '') {
            return 'Indica il riferimento del pagamento (mandato, numero di ordinativo…).';
        }
        $da = $this->chi($utente);
        $this->incarichi->segnaPagata($id, $data, $riferimento, $da);
        $this->lettere->evento($id, 'pagata', 'Pagamento disposto il ' . date('d/m/Y', (int) strtotime($data)) . ' (' . $riferimento . ')', $da);
        $this->notifiche->pagato($this->incarichi->perId($id) ?? $i, $data, $riferimento);

        return null;
    }

    /** L'ufficio rimanda la pratica al referente con una nota (obbligatoria). Ritorna l'errore o null. @param array<string, mixed>|null $utente */
    public function rimandaAlReferente(int $id, string $nota, ?array $utente): ?string
    {
        $i = $this->incarichi->perId($id);
        if (!$i || !$this->dellUfficioDi($utente, $i)) {
            return 'Non puoi lavorare questa pratica.';
        }
        if ((string) $i['pagamento_stato'] !== 'in_ufficio') {
            return 'La pratica non è da pagare.';
        }
        $nota = trim($nota);
        if ($nota === '') {
            return 'Scrivi perché la rimandi: il referente lo riceve per email.';
        }
        $da = $this->chi($utente);
        $this->incarichi->rimandaAlReferente($id, mb_substr($nota, 0, 500));
        $this->lettere->evento($id, 'pagamento_rimandato', 'Rimandata dall\'ufficio pagamenti: ' . mb_substr($nota, 0, 300), $da);
        $this->notifiche->rimandataAlReferente($this->notificheLettere->emailOperatori($i), $i, mb_substr($nota, 0, 500), $da);

        return null;
    }

    // ------------------------------------------------------------------ documenti

    /**
     * Chi sta guardando la pratica: referente (o amministratore), ufficio pagamenti (solo pratiche passate), tutor (le proprie) o nessuno.
     *
     * @param array<string, mixed>|null $utente
     * @param array<string, mixed> $i
     */
    public function ruolo(?array $utente, array $i, bool $referente): ?string
    {
        if ($referente) {
            return 'referente';
        }
        if (in_array((string) $i['pagamento_stato'], ['in_ufficio', 'pagata'], true) && $this->dellUfficioDi($utente, $i)) {
            return 'ufficio';
        }
        $cf = strtoupper(trim((string) ($utente['codice_fiscale'] ?? '')));
        $email = strtolower(trim((string) ($utente['email'] ?? '')));
        if ($utente && !empty($utente['id']) && (($cf !== '' && $cf === strtoupper((string) $i['codice_fiscale'])) || ($email !== '' && $email === strtolower((string) $i['email'])))) {
            return 'tutor';
        }

        return null;
    }

    /**
     * I documenti della pratica per la scheda: chiave, etichetta, dettaglio e se il file c'è.
     *
     * @param array<string, mixed> $i
     * @return list<array{chiave: string, etichetta: string, dettaglio: string, disponibile: bool}>
     */
    public function documenti(array $i): array
    {
        $d = static fn ($x) => $x ? date('d/m/Y', (int) strtotime((string) $x)) : '';
        $dec = static fn (string $n, $data) => trim(($n !== '' ? 'D.D. n. ' . $n : '') . ($data ? ' del ' . $d($data) : ''));
        $prot = trim((string) $i['protocollo'] . ($i['protocollo_data'] ? ' del ' . $d($i['protocollo_data']) : ''));
        $stato = $this->dichiarazione->stato((int) $i['id']);

        $documenti = [
            ['chiave' => 'decreto_bando', 'etichetta' => 'Decreto del bando', 'dettaglio' => $dec((string) $i['decreto_bando'], $i['decreto_bando_data']), 'disponibile' => !empty($i['decreto_bando_file'])],
            ['chiave' => 'decreto_commissione', 'etichetta' => 'Decreto della commissione', 'dettaglio' => $dec((string) $i['decreto_commissione'], $i['decreto_commissione_data']), 'disponibile' => !empty($i['decreto_commissione_file'])],
            ['chiave' => 'decreto_atti', 'etichetta' => 'Decreto di approvazione atti', 'dettaglio' => $dec((string) $i['decreto_atti'], $i['decreto_atti_data']), 'disponibile' => !empty($i['decreto_atti_file'])],
            ['chiave' => 'unilav', 'etichetta' => 'UNILAV', 'dettaglio' => !empty($i['unilav_file']) ? DatiLettera::oreTesto($i['unilav_ore']) . ' ore · caricato il ' . $d($i['unilav_il']) : '', 'disponibile' => !empty($i['unilav_file'])],
            ['chiave' => 'lettera', 'etichetta' => 'Lettera di incarico', 'dettaglio' => 'firmata da studente, docente e Direttore' . ($prot !== '' ? ' · prot. ' . $prot : ''), 'disponibile' => $this->archivio->pdfCorrente($i) !== null],
            ['chiave' => 'registro', 'etichetta' => 'Registro delle presenze', 'dettaglio' => $i['ore_approvate'] !== null ? DatiLettera::oreTesto($i['ore_approvate']) . ' ore approvate' : '', 'disponibile' => $this->registro->righe((int) $i['id']) !== []],
            ['chiave' => 'fine', 'etichetta' => 'Lettera di fine attività', 'dettaglio' => $i['fine_firmata_il'] ? 'firmata dal docente titolare (PAdES) · ' . $d($i['fine_firmata_il']) : '', 'disponibile' => $this->archivio->fineCorrente($i) !== null && in_array((string) $i['fine_stato'], ['firmata', 'protocollata'], true)],
            ['chiave' => 'dichiarazione', 'etichetta' => 'Dichiarazione individuale', 'dettaglio' => $stato['completa'] ? 'compilata online dal tutor' : '', 'disponibile' => $stato['completa']],
        ];
        if ((string) $i['iter'] === Costanti::ITER_UNITICKET) {
            $documenti[] = ['chiave' => 'scafis', 'etichetta' => 'Modello SCAFIS (dichiarazione individuale firmata)', 'dettaglio' => (string) $i['iter'] === Costanti::ITER_UNITICKET ? 'con i dati dell\'identità digitale, da allegare su Uniticket' : '', 'disponibile' => (string) $i['iter'] === Costanti::ITER_UNITICKET && $this->archivio->scafisCorrente($i) !== null];
        }

        return $documenti;
    }

    /**
     * Un documento della pratica da scaricare: percorso di un file esistente oppure contenuto generato. Null se non c'è.
     *
     * @param array<string, mixed> $i
     * @param array<string, mixed>|null $utente
     * @return array{percorso: string|null, contenuto: string|null, nome: string}|null
     */
    public function documento(array $i, string $chiave, ?array $utente): ?array
    {
        $nome = 'Tutorato_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $i['codice']) . '_' . $chiave . '.pdf';
        $path = static fn (?string $p) => $p !== null ? ['percorso' => $p, 'contenuto' => null, 'nome' => $nome] : null;

        return match ($chiave) {
            'decreto_bando' => $path($this->file->percorsoDecreto($i, 'bando')),
            'decreto_commissione' => $path($this->file->percorsoDecreto($i, 'commissione')),
            'decreto_atti' => $path($this->file->percorsoDecreto($i, 'atti')),
            'unilav' => $path($this->file->percorsoUnilav($i)),
            'lettera' => $path($this->archivio->pdfCorrente($i)),
            'fine' => in_array((string) $i['fine_stato'], ['firmata', 'protocollata'], true) ? $path($this->archivio->fineCorrente($i)) : null,
            'registro' => ($pdf = $this->pdfRegistro($i)) !== null ? ['percorso' => null, 'contenuto' => $pdf, 'nome' => $nome] : null,
            'dichiarazione' => ($pdf = $this->dichiarazione->pdf($i, $utente)) !== null ? ['percorso' => null, 'contenuto' => $pdf, 'nome' => $nome] : null,
            'scafis' => $this->apriScafis($i, $utente, $path),
            default => null,
        };
    }

    /**
     * Il file SCAFIS (iter per Uniticket) con l'IBAN: ogni apertura si registra come per la dichiarazione.
     *
     * @param array<string, mixed> $i
     * @param array<string, mixed>|null $utente
     * @return array{percorso: string|null, contenuto: string|null, nome: string}|null
     */
    private function apriScafis(array $i, ?array $utente, callable $path): ?array
    {
        if ((string) $i['iter'] !== Costanti::ITER_UNITICKET || ($p = $this->archivio->scafisCorrente($i)) === null) {
            return null;
        }
        $this->dichiarazione->registraApertura((int) $i['id'], $utente, 'scafis_file');

        return $path($p);
    }

    /** Registro delle presenze (ore approvate) in PDF; null se non c'è niente. @param array<string, mixed> $i */
    public function pdfRegistro(array $i): ?string
    {
        $righe = array_values(array_filter($this->registro->righe((int) $i['id']), static fn ($r) => $r['stato'] === 'approvata'));
        if (!$righe) {
            return null;
        }
        $nome = trim($i['cognome'] . ' ' . $i['nome']);
        $pdf = new Documento(['logo' => $this->sito->radice() . '/' . Costanti::LOGO_LETTERA, 'logo_larghezza' => 210, 'piede' => "Registro delle attività di tutorato – lettera di incarico {$i['codice']}",
            'info' => ['Title' => 'Registro delle attività – ' . $nome, 'Author' => 'Dipartimento DiBEST – Università della Calabria', 'Subject' => 'Registro delle attività di tutorato ' . $i['codice']]]);
        $pdf->paragrafo('**REGISTRO ATTIVITÀ DI TUTORAGGIO**', ['al' => 'centro', 'dopo' => 12]);
        $pdf->paragrafo("**Tutor:** $nome · **Bando:** {$i['bando_titolo']} · **Attività:** " . mb_substr(trim((string) preg_replace('/\s+/', ' ', (string) $i['attivita'])), 0, 200), ['dopo' => 10]);
        $pdf->tabella(['Data', 'Ore', 'Attività svolta'], array_map(static fn ($r) => [date('d/m/Y', strtotime((string) $r['data'])), DatiLettera::oreTesto($r['ore']), (string) $r['attivita']], $righe), ['larghezze' => [1.2, 0.7, 6]]);
        $tot = array_sum(array_map(static fn ($r) => (float) $r['ore'], $righe));
        $pdf->paragrafo('Totale ore approvate dal docente responsabile: **' . DatiLettera::oreTesto($tot) . '** su ' . DatiLettera::oreTesto($i['ore']) . ' previste dalla lettera di incarico.', ['sz' => 10, 'prima' => 8]);

        return $pdf->pdf();
    }

    /** @param array<string, mixed>|null $utente */
    private function chi(?array $utente): string
    {
        return trim((string) ($utente['nome'] ?? '') . ' ' . (string) ($utente['cognome'] ?? '')) ?: (string) ($utente['email'] ?? '');
    }
}
