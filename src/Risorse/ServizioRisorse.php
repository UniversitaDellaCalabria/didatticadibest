<?php

declare(strict_types=1);

namespace App\Risorse;

use App\Anagrafi\Anagrafe;
use App\Auth\Abilitazioni\IdsGestori;
use App\Core\Orologio;

/**
 * Prenotazione a slot di aule, laboratori e sportelli: slot liberi di un giorno, chi può prenotare, prenotazione
 * (anche ogni settimana) senza sovrapposizioni, cambio di stato con le relative email.
 */
final class ServizioRisorse
{
    public function __construct(private RisorsaRepository $risorse, private NotificheRisorse $notifiche, private Orologio $orologio, private ServizioContatoriRisorse $contatori, private DiarioBordoRepository $diario, private ?ListaAttesaRisorse $attesa = null)
    {
    }

    /**
     * Le date di una prenotazione ripetuta, a partire dalla prima, secondo la frequenza ('settimana', 'quindici' o 'mese') e fino alla data indicata (al massimo
     * $massimo ripetizioni oltre la prima). «mese» = lo stesso giorno della settimana nella stessa posizione del mese (es. il secondo martedì; il quinto diventa l'ultimo).
     *
     * @return list<string> date Y-m-d, la prima compresa
     */
    public static function dateRipetute(int $t0, string $ripetiFino, string $frequenza = 'settimana', int $massimo = 40): array
    {
        $date = [date('Y-m-d', $t0)];
        $ordinali = [1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', 5 => 'last'];
        $n = min(5, (int) ceil((int) date('j', $t0) / 7));
        for ($k = 1; $k <= $massimo; ++$k) {
            $t = match ($frequenza) {
                'quindici' => strtotime('+' . (2 * $k) . ' week', $t0),
                'mese' => strtotime($ordinali[$n] . ' ' . date('l', $t0) . ' of ' . date('Y-m', (int) strtotime('first day of +' . $k . ' month', $t0)) . ' ' . date('H:i:s', $t0)),
                default => strtotime("+$k week", $t0),
            };
            if (!$t || date('Y-m-d', $t) > $ripetiFino) {
                break;
            }
            $date[] = date('Y-m-d', $t);
        }

        return $date;
    }

    /**
     * Mette in lista d'attesa per uno slot occupato. Ritorna un messaggio d'errore oppure null.
     *
     * @param array<string, mixed> $r
     * @param array<string, mixed> $u
     */
    public function mettiInAttesa(array $r, array $u, string $inizio, int $nSlot, string $motivo = ''): ?string
    {
        if ($this->attesa === null) {
            return 'La lista d\'attesa non è disponibile.';
        }
        if (!(int) $r['attiva'] || !$this->puoPrenotare($r, $u)) {
            return 'Non sei abilitato a prenotare questa risorsa.';
        }
        $t0 = strtotime($inizio);
        if (!$t0) {
            return 'Orario non valido.';
        }
        $d = date('Y-m-d', $t0);
        $slot = $this->slot($r, $d);
        $idx = null;
        foreach ($slot as $k => $s) {
            if ($s['inizio'] === date('Y-m-d H:i:s', $t0)) {
                $idx = $k;
                break;
            }
        }
        if ($idx === null) {
            return 'Orario non disponibile.';
        }
        $nSlot = max(1, min(max(1, (int) $r['max_slot']), $nSlot));
        $occupato = false;
        for ($j = 0; $j < $nSlot; ++$j) {
            $s = $slot[$idx + $j] ?? null;
            if (!$s || $s['fascia'] !== $slot[$idx]['fascia'] || !in_array($s['stato'], ['libero', 'occupato'], true)) {
                return 'Per questo orario non si può prenotare.';
            }
            $occupato = $occupato || $s['stato'] === 'occupato';
        }
        if (!$occupato) {
            return 'Lo slot è libero: puoi prenotarlo subito.';
        }

        return $this->attesa->aggiungi($r, $u, $slot[$idx]['inizio'], $slot[$idx + $nSlot - 1]['fine'], $nSlot, $motivo);
    }

    /**
     * Slot di un giorno: [['inizio' => 'Y-m-d H:i:s', 'fine' => …, 'stato' => libero|occupato|passato|lontano|chiuso, 'fascia' => n], …].
     * passato = prima dell'anticipo minimo; lontano = oltre i giorni prenotabili; fascia = indice della fascia oraria
     * (gli slot consecutivi si possono unire solo nella stessa fascia).
     *
     * @param array<string, mixed> $r
     * @return list<array{inizio: string, fine: string, stato: string, fascia: int}>
     */
    public function slot(array $r, string $data, ?int $escludiPrenotazione = null): array
    {
        $giorno = (int) date('N', (int) strtotime($data));
        $fasce = $this->risorse->orari((int) $r['id'])[$giorno] ?? [];
        if (!$fasce) {
            return [];
        }
        $chiuso = $this->risorse->chiusura((int) $r['id'], (int) $r['pagina_id'], $data);
        $durata = max(5, (int) $r['durata_slot']);
        $occ = $this->risorse->occupatiNelGiorno((int) $r['id'], $data, $escludiPrenotazione);
        $adesso = $this->orologio->adesso()->getTimestamp();
        $minimo = $adesso + max(0, (int) $r['anticipo_ore']) * 3600;
        $massimo = (int) strtotime(date('Y-m-d', (int) strtotime('+' . max(1, (int) $r['max_giorni']) . ' days', $adesso)) . ' 23:59:59');
        $out = [];
        foreach ($fasce as $n => [$dalle, $alle]) {
            $t = (int) strtotime("$data $dalle");
            $fineF = strtotime("$data $alle");
            while ($t + $durata * 60 <= $fineF) {
                $f = $t + $durata * 60;
                $stato = 'libero';
                if ($chiuso !== null) {
                    $stato = 'chiuso';
                } elseif ($t < $minimo) {
                    $stato = 'passato';
                } elseif ($t > $massimo) {
                    $stato = 'lontano';
                } else {
                    foreach ($occ as [$a, $b]) {
                        if ($t < $b && $f > $a) {
                            $stato = 'occupato';
                            break;
                        }
                    }
                }
                $out[] = ['inizio' => date('Y-m-d H:i:s', $t), 'fine' => date('Y-m-d H:i:s', $f), 'stato' => $stato, 'fascia' => $n];
                $t = $f;
            }
        }

        return $out;
    }

    /**
     * Nomi dei gruppi dell'utente (ruolo principale e secondari), in minuscolo.
     *
     * @param array<string, mixed> $u
     * @return list<string>
     */
    public function gruppiUtenteNomi(array $u): array
    {
        $ids = array_filter(array_map('intval', array_merge([(int) ($u['ruolo_id'] ?? 5)], explode(',', (string) ($u['ruoli_secondari'] ?? '')))));
        if (!$ids) {
            return [];
        }

        return $this->risorse->nomiRuoli(array_values($ids));
    }

    /**
     * Chi può prenotare: amministratori e gestori dell'area sempre; poi in base a risorse.accesso.
     *
     * @param array<string, mixed> $r
     * @param array<string, mixed>|null $u
     */
    public function puoPrenotare(array $r, ?array $u): bool
    {
        if (!$u || empty($u['id'])) {
            return false;
        }
        $sec = explode(',', (string) ($u['ruoli_secondari'] ?? ''));
        if ((int) ($u['ruolo_id'] ?? 5) === 1 || in_array('1', $sec, true)) {
            return true;
        }
        $pag = $this->risorse->gestoriArea((int) $r['pagina_id']);
        if ($pag && in_array((int) $u['id'], IdsGestori::daCampi($pag['gestore_utente_id'] ?? 0, $pag['gestori_utenti_ids'] ?? '', $pag['permessi_gestori_json'] ?? ''), true)) {
            return true;
        }
        $gruppi = $this->gruppiUtenteNomi($u);
        $docente = in_array(mb_strtolower(Anagrafe::GRUPPI_PERSONALE['docenti']), $gruppi, true);
        $personale = $docente || (int) ($u['ruolo_id'] ?? 5) === 4 || in_array('4', $sec, true)
                     || in_array(mb_strtolower(Anagrafe::GRUPPI_PERSONALE['pta']), $gruppi, true) || in_array(mb_strtolower(Anagrafe::GRUPPI_PERSONALE['altro']), $gruppi, true);
        $studente = (int) ($u['ruolo_id'] ?? 5) === 3 || in_array('3', $sec, true) || !empty($u['matricola_studente']);

        return match ((string) $r['accesso']) {
            'studenti' => $studente,
            'docenti' => $docente,
            'personale' => $personale,
            default => true,
        };
    }

    /**
     * Prenota $nSlot slot consecutivi da $inizio (Y-m-d H:i:s) e, se la risorsa lo consente, anche ripetuta (ogni settimana, ogni due settimane o ogni mese: $frequenza) fino a $ripetiFino.
     * La prima occorrenza deve riuscire; le successive in conflitto vengono saltate.
     *
     * @param array<string, mixed> $r
     * @param array<string, mixed> $u
     * @return array{codici: list<string>, saltate: array<string, string>, errore: ?string, stato: string, serie: ?string}
     */
    public function prenota(array $r, array $u, string $inizio, int $nSlot, string $motivo = '', ?string $ripetiFino = null, array $extra = [], string $frequenza = 'settimana'): array
    {
        $esito = ['codici' => [], 'saltate' => [], 'errore' => null, 'stato' => (int) $r['approvazione'] === 1 ? 'da_approvare' : 'confermata', 'serie' => null];
        if (!(int) $r['attiva']) {
            $esito['errore'] = 'La risorsa non è prenotabile.';

            return $esito;
        }
        if (!$this->puoPrenotare($r, $u)) {
            $esito['errore'] = 'Non sei abilitato a prenotare questa risorsa.';

            return $esito;
        }
        // Diario di bordo: chi non ha compilato quello dell'ultimo viaggio non prenota di nuovo (se la risorsa lo chiede)
        if ((int) ($r['diario_blocca'] ?? 0) === 1 && (int) ($r['diario_attivo'] ?? 0) === 1 && ($manca = $this->diario->senzaDiario((int) $u['id'], (string) ($u['email'] ?? ''), (int) $r['id']))) {
            $esito['errore'] = 'Prima di prenotare di nuovo compila il diario di bordo del viaggio del ' . date('d/m/Y', (int) strtotime((string) $manca[0]['inizio'])) . ' (dalle tue prenotazioni).';

            return $esito;
        }
        $nSlot = max(1, min(max(1, (int) $r['max_slot']), $nSlot));
        $t0 = strtotime($inizio);
        if (!$t0) {
            $esito['errore'] = 'Orario non valido.';

            return $esito;
        }
        $date = [date('Y-m-d', $t0)];
        if ($ripetiFino && (int) $r['ripetizione'] === 1 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ripetiFino)) {
            $date = self::dateRipetute($t0, $ripetiFino, isset(Costanti::FREQUENZE[$frequenza]) ? $frequenza : 'settimana');
            if (count($date) > 1) {
                $esito['serie'] = strtoupper(bin2hex(random_bytes(4)));
            }
        }
        $ora = date('H:i:s', $t0);
        $motivo = mb_substr(trim(strip_tags($motivo)), 0, 500);
        $rid = (int) $r['id'];
        // Quantità dei contatori della risorsa (id contatore => quantità) e risposte ai campi personalizzati (JSON): vedi $extra
        $contatori = $this->contatori->elenco($rid);
        $qta = (array) ($extra['quantita'] ?? []);
        $risposte = isset($extra['risposte_json']) ? (string) $extra['risposte_json'] : null;
        $coConducenti = isset($extra['co_conducenti_json']) ? (string) $extra['co_conducenti_json'] : null;
        if (!$this->risorse->bloccaCalendario($rid)) {
            $esito['errore'] = 'Il calendario è occupato: riprova tra un istante.';

            return $esito;
        }
        try {
            foreach ($date as $i => $d) {
                $slot = $this->slot($r, $d);
                $idx = null;
                foreach ($slot as $k => $s) {
                    if ($s['inizio'] === "$d $ora") {
                        $idx = $k;
                        break;
                    }
                }
                $motivoNo = null;
                if ($idx === null) {
                    $motivoNo = 'orario non disponibile';
                } else {
                    for ($j = 0; $j < $nSlot; ++$j) {
                        $s = $slot[$idx + $j] ?? null;
                        if (!$s || $s['fascia'] !== $slot[$idx]['fascia']) {
                            $motivoNo = "durata oltre l'orario di apertura";
                            break;
                        }
                        if ($s['stato'] !== 'libero') {
                            $motivoNo = ['occupato' => 'già prenotato', 'chiuso' => 'chiuso', 'passato' => 'troppo vicino', 'lontano' => 'troppo lontano'][$s['stato']] ?? 'non disponibile';
                            break;
                        }
                    }
                }
                if ($motivoNo !== null) {
                    if ($i === 0) {
                        $esito['errore'] = 'Lo slot scelto non è più disponibile (' . $motivoNo . '): scegline un altro.';

                        return $esito;
                    }
                    $esito['saltate'][$d] = $motivoNo;
                    continue;
                }
                // Contatori: la quantità deve esserci per questa data (la prima data ferma tutto, le successive della serie si saltano)
                if ($contatori && ($errQ = $this->contatori->controlla($contatori, $qta, $d)) !== null) {
                    if ($i === 0) {
                        $esito['errore'] = $errQ;

                        return $esito;
                    }
                    $esito['saltate'][$d] = 'quantità non disponibile';
                    continue;
                }
                $ini = $slot[$idx]['inizio'];
                $fin = $slot[$idx + $nSlot - 1]['fine'];
                $codice = 'RS-' . strtoupper(bin2hex(random_bytes(4)));
                if ($this->risorse->inserisciPrenotazione($rid, (int) $u['id'], (string) ($u['nome'] ?? ''), (string) ($u['cognome'] ?? ''), strtolower((string) ($u['email'] ?? '')), $ini, $fin, $motivo, $esito['stato'], $esito['serie'], $codice)) {
                    $esito['codici'][] = $codice;
                    $this->attesa?->soddisfatta((int) $u['id'], $rid, $ini, $fin);
                    if ($contatori || $risposte !== null || $coConducenti !== null) {
                        $nuova = $this->risorse->prenotazione($codice);
                        if ($nuova) {
                            $this->contatori->registra((int) $nuova['id'], $contatori, $qta, $d);
                            if ($risposte !== null) {
                                $this->risorse->impostaRisposte((int) $nuova['id'], $risposte);
                            }
                            if ($coConducenti !== null) {
                                $this->diario->impostaCoConducenti((int) $nuova['id'], $coConducenti);
                            }
                        }
                    }
                }
            }
        } finally {
            $this->risorse->rilasciaCalendario($rid);
        }
        if (!$esito['codici']) {
            $esito['errore'] = 'Nessuna prenotazione registrata.';
        }

        return $esito;
    }

    /** @return array<string, mixed>|null prenotazione con risorsa e area (per id o per codice) */
    public function prenotazione(int|string $chiave): ?array
    {
        return $this->risorse->prenotazione($chiave);
    }

    /**
     * Approva / rifiuta / annulla (gestore) oppure annulla (utente). Ritorna true se lo stato è cambiato.
     * Le email: a chi ha prenotato se agisce il gestore, ai gestori se annulla l'utente.
     */
    public function cambiaStato(int $id, string $nuovo, bool $daGestore = true): bool
    {
        $p = $this->risorse->prenotazione($id);
        if (!$p) {
            return false;
        }
        $da = ['confermata' => ['da_approvare'], 'rifiutata' => ['da_approvare'], 'annullata' => ['confermata', 'da_approvare']][$nuovo] ?? [];
        if (!in_array($p['stato'], $da, true)) {
            return false;
        }
        if (!$this->risorse->cambiaStato($id, $nuovo, (string) $p['stato'])) {
            return false;
        }
        $p['stato'] = $nuovo;
        // Annullata o rifiutata: lo slot torna libero e il primo in lista d'attesa viene avvisato
        if (in_array($nuovo, ['annullata', 'rifiutata'], true)) {
            $this->attesa?->liberato($p);
        }
        if ($daGestore) {
            $this->notifiche->emailPrenotazione($p, $nuovo === 'confermata' ? 'approvata' : ($nuovo === 'rifiutata' ? 'rifiutata' : 'annullata_gestore'));
        } else {
            $this->notifiche->notificaGestori($p, 'annullata');
        }

        return true;
    }
}
