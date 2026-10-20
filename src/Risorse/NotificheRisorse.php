<?php

declare(strict_types=1);

namespace App\Risorse;

use App\Auth\Abilitazioni\IdsGestori;
use App\Auth\ServizioUtenti;
use App\Core\Sito;
use App\Email\InvioEmail;
use App\Portale\Colori;

/** Email delle prenotazioni di risorse: a chi ha prenotato (conferma, approvazione, annullamento, promemoria) e ai gestori. */
final class NotificheRisorse
{
    public function __construct(private RisorsaRepository $risorse, private ServizioUtenti $utenti, private InvioEmail $invio, private Sito $sito, private ResponsabileRisorsaRepository $responsabili, private ContatoreRisorsaRepository $contatori)
    {
    }

    /**
     * Chi riceve le notifiche: indirizzi della risorsa, altrimenti i gestori dell'area con le notifiche attive, altrimenti gli amministratori.
     *
     * @param array<string, mixed> $p prenotazione con email_notifiche e pagina_id
     * @return list<string>
     */
    public function emailGestori(array $p): array
    {
        $out = array_filter(array_map('trim', explode(',', (string) ($p['email_notifiche'] ?? ''))), static fn ($e): bool => (bool) filter_var($e, FILTER_VALIDATE_EMAIL));
        // I responsabili della risorsa (scelti dall'anagrafe) ricevono le richieste insieme agli indirizzi indicati
        if (!empty($p['risorsa_id'])) {
            $out = array_merge($out, $this->responsabili->emailNotifiche((int) $p['risorsa_id']));
        }
        if ($out) {
            return array_values(array_unique(array_map('strtolower', $out)));
        }
        $pag = $this->risorse->gestoriArea((int) $p['pagina_id']);
        $ids = $pag ? IdsGestori::daCampi($pag['gestore_utente_id'] ?? 0, $pag['gestori_utenti_ids'] ?? '', $pag['permessi_gestori_json'] ?? '') : [];
        if ($ids) {
            foreach ($this->risorse->emailUtenti($ids) as $e) {
                if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
                    $out[] = $e;
                }
            }
        }

        return $out ?: $this->utenti->emailAmministratori();
    }

    /**
     * Elenco HTML delle quantità prenotate e delle risposte ai campi personalizzati della prenotazione (vuoto se non ce ne sono).
     *
     * @param array<string, mixed> $p
     */
    private function dettagli(array $p): string
    {
        $righe = [];
        $usi = !empty($p['id']) ? $this->contatori->usiDellaPrenotazione((int) $p['id']) : [];
        if ($usi) {
            $righe[] = ['Quantità', ServizioContatoriRisorse::testoUsi($usi)];
        }
        $righe = array_merge($righe, ServizioCampiRisorsa::coppie($p['risposte_json'] ?? null));
        if (!$righe) {
            return '';
        }

        return '<ul>' . implode('', array_map(static fn ($r): string => '<li>' . htmlspecialchars($r[0], ENT_QUOTES, 'UTF-8') . ': <strong>' . htmlspecialchars($r[1], ENT_QUOTES, 'UTF-8') . '</strong></li>', $righe)) . '</ul>';
    }

    /**
     * Email a chi ha prenotato. $tipo: registrata | approvata | rifiutata | annullata_gestore | promemoria.
     * $altre: altre prenotazioni della stessa serie nella stessa email (solo «registrata»); $saltate: date non disponibili [data => motivo].
     *
     * @param array<string, mixed> $p
     * @param list<array<string, mixed>> $altre
     * @param array<string, string> $saltate
     */
    public function emailPrenotazione(array $p, string $tipo, array $altre = [], array $saltate = []): bool
    {
        if (empty($p['email'])) {
            return false;
        }
        $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $base = $this->sito->urlBase();
        $chiave = match ($tipo) {
            'registrata' => $p['stato'] === 'da_approvare' ? 'risorsa_in_attesa' : 'risorsa_registrata',
            'approvata' => 'risorsa_approvata',
            'rifiutata' => 'risorsa_rifiutata',
            'annullata_gestore' => 'risorsa_annullata_gestore',
            default => 'risorsa_promemoria',
        };
        $righe = '';
        foreach (array_merge([$p], $altre) as $q) {
            $righe .= '<li>' . $h(Prenotazioni::quando($q)) . ' · codice <strong>' . $h($q['codice']) . '</strong></li>';
        }
        $ics = in_array($tipo, ['registrata', 'approvata', 'promemoria'], true)
            ? "<p><a href='" . $h("$base/risorsa_ics.php?code=" . urlencode((string) $p['codice'])) . "' style='background:#334155;color:#fff;padding:8px 14px;text-decoration:none;border-radius:4px;font-weight:bold;'>📥 Aggiungi al calendario (.ics)</a></p>" : '';

        return $this->invio->invia(
            (string) $p['email'],
            $chiave,
            [
                'nome' => trim($p['nome'] . ' ' . $p['cognome']),
                'risorsa' => (string) $p['risorsa_nome'],
                'luogo_testo' => $p['luogo'] !== '' ? ' (' . $p['luogo'] . ')' : '',
                'elenco' => "<ul>$righe</ul>",
                'motivo' => $p['motivo'] !== '' ? '<p>Motivo: ' . $h($p['motivo']) . '</p>' : '',
                'dettagli' => $this->dettagli($p),
                'saltate' => $saltate ? '<p>Non prenotate perché non disponibili: ' . $h(implode(', ', array_map(static fn ($d, $m): string => date('d/m/Y', (int) strtotime((string) $d)) . " ($m)", array_keys($saltate), $saltate))) . '.</p>' : '',
                'ics' => $ics,
            ],
            "$base/area_personale.php",
            [],
            '',
            Colori::valido($p['colore_primario'] ?? '')
        );
    }

    /**
     * Si è liberato uno slot per cui l'utente era in lista d'attesa: l'avviso con il link al calendario (lo slot non resta riservato).
     *
     * @param array<string, mixed> $a riga della lista d'attesa
     * @param array<string, mixed> $r risorsa con risorsa_nome, slug e colore_primario
     */
    public function emailSlotLibero(array $a, array $r, int $ore): bool
    {
        if (empty($a['email'])) {
            return false;
        }
        $quando = date('d/m/Y', (int) strtotime((string) $a['inizio'])) . ' ' . date('H:i', (int) strtotime((string) $a['inizio'])) . '–' . date('H:i', (int) strtotime((string) $a['fine']));
        $link = $this->sito->urlBase() . '/' . rawurlencode((string) ($r['slug'] ?? '')) . '.php?risorsa=' . (int) $a['risorsa_id'] . '&dal=' . urlencode(date('Y-m-d', (int) strtotime((string) $a['inizio'])));

        return $this->invio->invia(
            (string) $a['email'],
            'risorsa_slot_libero',
            ['nome' => trim($a['nome'] . ' ' . $a['cognome']), 'risorsa' => (string) $r['risorsa_nome'], 'quando' => $quando, 'quando_breve' => date('d/m H:i', (int) strtotime((string) $a['inizio'])), 'ore' => (string) $ore],
            $link,
            [],
            '',
            Colori::valido($r['colore_primario'] ?? '')
        );
    }

    /**
     * Avviso di una scadenza della risorsa (in arrivo o scaduta) ai responsabili e agli indirizzi di notifica.
     *
     * @param array<string, mixed> $s scadenza con risorsa_nome, risorsa_id, pagina_id, email_notifiche, scaduta
     */
    public function emailScadenza(array $s): int
    {
        $scaduta = (string) ($s['scaduta'] ?? '0') === '1';
        $dati = [
            'voce' => (string) $s['nome'],
            'risorsa' => (string) $s['risorsa_nome'],
            'data' => date('d/m/Y', (int) strtotime((string) $s['scade_il'])),
            'note' => trim((string) ($s['note'] ?? '')) !== '' ? '<p>' . htmlspecialchars((string) $s['note'], ENT_QUOTES, 'UTF-8') . '</p>' : '',
        ];
        $link = $this->sito->urlBase() . '/risorse_cruscotto.php?r=' . (int) $s['risorsa_id'];
        $n = 0;
        foreach ($this->emailGestori(['risorsa_id' => $s['risorsa_id'], 'pagina_id' => $s['pagina_id'], 'email_notifiche' => $s['email_notifiche'] ?? '']) as $em) {
            $n += $this->invio->invia($em, $scaduta ? 'risorsa_scadenza_scaduta' : 'risorsa_scadenza_in_arrivo', $dati, $link, [], '', Colori::valido($s['colore_primario'] ?? '')) ? 1 : 0;
        }

        return $n;
    }

    /**
     * Promemoria per il diario di bordo del viaggio: a chi ha prenotato e agli altri conducenti indicati. Ritorna true se almeno una email è partita.
     *
     * @param array<string, mixed> $p prenotazione con risorsa_nome
     */
    public function emailDiario(array $p): bool
    {
        $link = $this->sito->urlBase() . '/diario_bordo.php?c=' . urlencode((string) $p['codice']);
        $dest = array_filter([strtolower((string) ($p['email'] ?? ''))]);
        foreach (ServizioDiarioBordo::coConducenti($p) as $c) {
            $dest[] = (string) ($c['email'] ?? '');
        }
        $dati = ['nome' => trim($p['nome'] . ' ' . $p['cognome']), 'risorsa' => (string) $p['risorsa_nome'], 'quando' => Prenotazioni::quando($p)];
        $fatto = false;
        foreach (array_unique(array_filter($dest, static fn ($e): bool => (bool) filter_var($e, FILTER_VALIDATE_EMAIL))) as $e) {
            $fatto = $this->invio->invia($e, 'risorsa_diario', $dati, $link, [], '', Colori::valido($p['colore_primario'] ?? '')) || $fatto;
        }

        return $fatto;
    }

    /**
     * Email ai gestori: nuova prenotazione (o richiesta da approvare) oppure annullamento da parte dell'utente.
     *
     * @param array<string, mixed> $p
     */
    public function notificaGestori(array $p, string $tipo, int $n = 1): void
    {
        $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $link = $this->sito->urlBase() . '/admin/prenotazioni_risorse.php?p_id=' . (int) $p['pagina_id'] . ($p['stato'] === 'da_approvare' ? '&stato=da_approvare' : '');
        $chiave = $tipo === 'annullata' ? 'risorsa_gestori_annullata' : ($p['stato'] === 'da_approvare' ? 'risorsa_gestori_da_approvare' : 'risorsa_gestori_nuova');
        $dati = [
            'persona' => trim($p['nome'] . ' ' . $p['cognome']),
            'email' => (string) $p['email'],
            'risorsa' => (string) $p['risorsa_nome'],
            'quando' => Prenotazioni::quando($p),
            'quando_breve' => date('d/m H:i', (int) strtotime((string) $p['inizio'])),
            'altre_date' => $n > 1 ? ' e altre ' . ($n - 1) . ' date (ogni settimana)' : '',
            'motivo' => $p['motivo'] !== '' ? '<p>Motivo: ' . $h($p['motivo']) . '</p>' : '',
            'dettagli' => $this->dettagli($p),
        ];
        // Chi è solo responsabile della risorsa (senza accesso al pannello dell'area) trova le richieste nella sua Area personale
        $linkResp = $this->sito->urlBase() . '/risorse_responsabile.php';
        $soloResp = !empty($p['risorsa_id']) ? $this->responsabili->emailNotifiche((int) $p['risorsa_id']) : [];
        foreach ($this->emailGestori($p) as $em) {
            $this->invio->invia($em, $chiave, $dati, in_array($em, $soloResp, true) ? $linkResp : $link, [], '', Colori::valido($p['colore_primario'] ?? ''));
        }
    }
}
