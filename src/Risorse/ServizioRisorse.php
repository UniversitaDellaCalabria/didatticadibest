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
    public function __construct(private RisorsaRepository $risorse, private NotificheRisorse $notifiche, private Orologio $orologio)
    {
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
     * Prenota $nSlot slot consecutivi da $inizio (Y-m-d H:i:s) e, se la risorsa lo consente, ogni settimana fino a $ripetiFino.
     * La prima occorrenza deve riuscire; le successive in conflitto vengono saltate.
     *
     * @param array<string, mixed> $r
     * @param array<string, mixed> $u
     * @return array{codici: list<string>, saltate: array<string, string>, errore: ?string, stato: string, serie: ?string}
     */
    public function prenota(array $r, array $u, string $inizio, int $nSlot, string $motivo = '', ?string $ripetiFino = null): array
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
        $nSlot = max(1, min(max(1, (int) $r['max_slot']), $nSlot));
        $t0 = strtotime($inizio);
        if (!$t0) {
            $esito['errore'] = 'Orario non valido.';

            return $esito;
        }
        $date = [date('Y-m-d', $t0)];
        if ($ripetiFino && (int) $r['ripetizione'] === 1 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ripetiFino)) {
            for ($k = 1; $k <= 25; ++$k) {
                $d = date('Y-m-d', strtotime("+$k week", $t0));
                if ($d > $ripetiFino) {
                    break;
                }
                $date[] = $d;
            }
            if (count($date) > 1) {
                $esito['serie'] = strtoupper(bin2hex(random_bytes(4)));
            }
        }
        $ora = date('H:i:s', $t0);
        $motivo = mb_substr(trim(strip_tags($motivo)), 0, 500);
        $rid = (int) $r['id'];
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
                $ini = $slot[$idx]['inizio'];
                $fin = $slot[$idx + $nSlot - 1]['fine'];
                $codice = 'RS-' . strtoupper(bin2hex(random_bytes(4)));
                if ($this->risorse->inserisciPrenotazione($rid, (int) $u['id'], (string) ($u['nome'] ?? ''), (string) ($u['cognome'] ?? ''), strtolower((string) ($u['email'] ?? '')), $ini, $fin, $motivo, $esito['stato'], $esito['serie'], $codice)) {
                    $esito['codici'][] = $codice;
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
        if ($daGestore) {
            $this->notifiche->emailPrenotazione($p, $nuovo === 'confermata' ? 'approvata' : ($nuovo === 'rifiutata' ? 'rifiutata' : 'annullata_gestore'));
        } else {
            $this->notifiche->notificaGestori($p, 'annullata');
        }

        return true;
    }
}
