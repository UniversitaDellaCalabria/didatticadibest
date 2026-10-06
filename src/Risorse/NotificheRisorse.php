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
    public function __construct(private RisorsaRepository $risorse, private ServizioUtenti $utenti, private Mailer $mailer, private Sito $sito)
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
               . ($saltate ? '<p>Non prenotate perché non disponibili: ' . $h(implode(', ', array_map(static fn ($d, $m): string => date('d/m/Y', (int) strtotime((string) $d)) . " ($m)", array_keys($saltate), $saltate))) . '.</p>' : '')
               . ($tipo === 'registrata' && $p['stato'] === 'da_approvare' ? "<p>Riceverai un'email quando la richiesta sarà approvata.</p>" : '')
               . (in_array($tipo, ['registrata', 'approvata', 'promemoria'], true)
                    ? "<p><a href='" . $h("$base/risorsa_ics.php?code=" . urlencode((string) $p['codice'])) . "' style='background:#334155;color:#fff;padding:8px 14px;text-decoration:none;border-radius:4px;font-weight:bold;'>📥 Aggiungi al calendario (.ics)</a></p>" : '')
               . "<p>Le tue prenotazioni sono nella tua <a href='" . $h("$base/area_personale.php") . "'>Area personale</a>, dove puoi anche annullarle.</p>";

        return $this->mailer->invia((string) $p['email'], ($titoli[$tipo] ?? 'Prenotazione') . ': ' . $p['risorsa_nome'], $corpo, Colori::valido($p['colore_primario'] ?? ''));
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
               . "<p><a href='" . $h($link) . "'>" . ($p['stato'] === 'da_approvare' && $tipo !== 'annullata' ? 'Approva o rifiuta' : 'Apri le prenotazioni') . '</a></p>';
        $ogg = ($tipo === 'annullata' ? 'Annullata: ' : ($p['stato'] === 'da_approvare' ? 'Da approvare: ' : 'Nuova prenotazione: ')) . $p['risorsa_nome'] . ' – ' . date('d/m H:i', (int) strtotime((string) $p['inizio']));
        foreach ($this->emailGestori($p) as $em) {
            $this->mailer->invia($em, $ogg, $corpo, Colori::valido($p['colore_primario'] ?? ''));
        }
    }
}
