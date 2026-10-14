<?php

declare(strict_types=1);

namespace App\Risorse;

use App\Auth\Abilitazioni\IdsGestori;
use App\Auth\ServizioUtenti;
use App\Core\Sito;
use App\Infrastructure\Mail\Mailer;
use App\Portale\Colori;

/** Email delle prenotazioni di risorse: a chi ha prenotato (conferma, approvazione, annullamento, promemoria) e ai gestori. */
final class NotificheRisorse
{
    public function __construct(private RisorsaRepository $risorse, private ServizioUtenti $utenti, private Mailer $mailer, private Sito $sito, private ResponsabileRisorsaRepository $responsabili, private ContatoreRisorsaRepository $contatori)
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
        $titoli = ['registrata' => $p['stato'] === 'da_approvare' ? 'Richiesta ricevuta, in attesa di approvazione' : 'Prenotazione confermata',
            'approvata' => 'Prenotazione approvata', 'rifiutata' => 'Prenotazione non approvata', 'annullata_gestore' => 'Prenotazione annullata',
            'promemoria' => 'Promemoria: prenotazione di domani'];
        $righe = '';
        foreach (array_merge([$p], $altre) as $q) {
            $righe .= '<li>' . $h(Prenotazioni::quando($q)) . ' · codice <strong>' . $h($q['codice']) . '</strong></li>';
        }
        $corpo = '<p>Gentile <strong>' . $h(trim($p['nome'] . ' ' . $p['cognome'])) . '</strong>,</p>'
               . '<p><strong>' . $h($titoli[$tipo] ?? '') . '</strong>: ' . $h($p['risorsa_nome']) . ($p['luogo'] !== '' ? ' (' . $h($p['luogo']) . ')' : '') . ".</p><ul>$righe</ul>"
               . ($p['motivo'] !== '' ? '<p>Motivo: ' . $h($p['motivo']) . '</p>' : '')
               . $this->dettagli($p)
               . ($saltate ? '<p>Non prenotate perché non disponibili: ' . $h(implode(', ', array_map(static fn ($d, $m): string => date('d/m/Y', (int) strtotime((string) $d)) . " ($m)", array_keys($saltate), $saltate))) . '.</p>' : '')
               . ($tipo === 'registrata' && $p['stato'] === 'da_approvare' ? "<p>Riceverai un'email quando la richiesta sarà approvata.</p>" : '')
               . (in_array($tipo, ['registrata', 'approvata', 'promemoria'], true)
                    ? "<p><a href='" . $h("$base/risorsa_ics.php?code=" . urlencode((string) $p['codice'])) . "' style='background:#334155;color:#fff;padding:8px 14px;text-decoration:none;border-radius:4px;font-weight:bold;'>📥 Aggiungi al calendario (.ics)</a></p>" : '')
               . "<p>Le tue prenotazioni sono nella tua <a href='" . $h("$base/area_personale.php") . "'>Area personale</a>, dove puoi anche annullarle.</p>";

        return $this->mailer->invia((string) $p['email'], ($titoli[$tipo] ?? 'Prenotazione') . ': ' . $p['risorsa_nome'], $corpo, Colori::valido($p['colore_primario'] ?? ''));
    }

    /**
     * Avviso di una scadenza della risorsa (in arrivo o scaduta) ai responsabili e agli indirizzi di notifica.
     *
     * @param array<string, mixed> $s scadenza con risorsa_nome, risorsa_id, pagina_id, email_notifiche, scaduta
     */
    public function emailScadenza(array $s): int
    {
        $h = static fn ($t): string => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
        $scaduta = (string) ($s['scaduta'] ?? '0') === '1';
        $data = date('d/m/Y', (int) strtotime((string) $s['scade_il']));
        $link = $this->sito->urlBase() . '/risorse_cruscotto.php?r=' . (int) $s['risorsa_id'];
        $corpo = '<p><strong>' . $h($s['nome']) . '</strong> di <strong>' . $h($s['risorsa_nome']) . '</strong> ' . ($scaduta ? "è scaduta il $data" : "scade il $data") . '.</p>'
               . (trim((string) ($s['note'] ?? '')) !== '' ? '<p>' . $h($s['note']) . '</p>' : '')
               . "<p>Quando è stata rinnovata inserisci la nuova data dal cruscotto della risorsa, così l'avviso si azzera.</p><p><a href='" . $h($link) . "' style='background:#b45309;color:#fff;padding:10px 18px;text-decoration:none;border-radius:6px;font-weight:bold;'>Apri il cruscotto</a></p>";
        $n = 0;
        foreach ($this->emailGestori(['risorsa_id' => $s['risorsa_id'], 'pagina_id' => $s['pagina_id'], 'email_notifiche' => $s['email_notifiche'] ?? '']) as $em) {
            $n += $this->mailer->invia($em, ($scaduta ? 'Scaduta: ' : 'In scadenza: ') . $s['nome'] . ' – ' . $s['risorsa_nome'], $corpo, Colori::valido($s['colore_primario'] ?? '')) ? 1 : 0;
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
        $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $link = $this->sito->urlBase() . '/diario_bordo.php?c=' . urlencode((string) $p['codice']);
        $dest = array_filter([strtolower((string) ($p['email'] ?? ''))]);
        foreach (ServizioDiarioBordo::coConducenti($p) as $c) {
            $dest[] = (string) ($c['email'] ?? '');
        }
        $corpo = '<p>Gentile <strong>' . $h(trim($p['nome'] . ' ' . $p['cognome'])) . '</strong>,</p><p>hai usato <strong>' . $h($p['risorsa_nome']) . '</strong> (' . $h(Prenotazioni::quando($p)) . '): '
               . "ti chiediamo di compilare il <strong>diario di bordo</strong> del viaggio (km, carburante, segnalazioni). Chi ha indicato altri conducenti può compilarlo anche da loro.</p>"
               . "<p><a href='" . $h($link) . "' style='background:#0369a1;color:#fff;padding:10px 18px;text-decoration:none;border-radius:6px;font-weight:bold;'>Compila il diario di bordo</a></p>";
        $fatto = false;
        foreach (array_unique(array_filter($dest, static fn ($e): bool => (bool) filter_var($e, FILTER_VALIDATE_EMAIL))) as $e) {
            $fatto = $this->mailer->invia($e, 'Diario di bordo da compilare: ' . $p['risorsa_nome'], $corpo, Colori::valido($p['colore_primario'] ?? '')) || $fatto;
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
        $cosa = $tipo === 'annullata' ? 'ha annullato la prenotazione' : ($p['stato'] === 'da_approvare' ? 'chiede di prenotare' : 'ha prenotato');
        $corpo = '<p><strong>' . $h(trim($p['nome'] . ' ' . $p['cognome'])) . '</strong> (' . $h($p['email']) . ") $cosa <strong>" . $h($p['risorsa_nome']) . '</strong>:'
               . ' ' . $h(Prenotazioni::quando($p)) . ($n > 1 ? ' e altre ' . ($n - 1) . ' date (ogni settimana)' : '') . '.</p>'
               . ($p['motivo'] !== '' ? '<p>Motivo: ' . $h($p['motivo']) . '</p>' : '')
               . $this->dettagli($p)
               . "<p><a href='" . $h($link) . "'>" . ($p['stato'] === 'da_approvare' && $tipo !== 'annullata' ? 'Approva o rifiuta' : 'Apri le prenotazioni') . '</a></p>';
        $ogg = ($tipo === 'annullata' ? 'Annullata: ' : ($p['stato'] === 'da_approvare' ? 'Da approvare: ' : 'Nuova prenotazione: ')) . $p['risorsa_nome'] . ' – ' . date('d/m H:i', (int) strtotime((string) $p['inizio']));
        // Chi è solo responsabile della risorsa (senza accesso al pannello dell'area) trova le richieste nella sua Area personale
        $linkResp = $this->sito->urlBase() . '/risorse_responsabile.php';
        $soloResp = !empty($p['risorsa_id']) ? $this->responsabili->emailNotifiche((int) $p['risorsa_id']) : [];
        foreach ($this->emailGestori($p) as $em) {
            $c = in_array($em, $soloResp, true) ? str_replace($h($link), $h($linkResp), $corpo) : $corpo;
            $this->mailer->invia($em, $ogg, $c, Colori::valido($p['colore_primario'] ?? ''));
        }
    }
}
