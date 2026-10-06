<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Sito;
use App\Infrastructure\Mail\Mailer;

/** Email delle pratiche: allo studente (stato, messaggi, passaggi) e agli uffici (nuove pratiche, messaggi, assegnazioni, promemoria). */
final class NotifichePratiche
{
    private const COLORE = '#047857';

    public function __construct(private Mailer $mailer, private ServizioUffici $ufficio, private UfficioRepository $uffici, private Sito $sito)
    {
    }

    /**
     * Email allo studente (stato, messaggio dell'ufficio) o all'ufficio (nuova pratica, messaggio dello studente).
     *
     * @param array<string, mixed> $p
     */
    public function email(array $p, string $tipo, string $testo = ''): void
    {
        $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $linkS = $this->sito->urlBase() . '/pratiche.php?id=' . (int) $p['id'];
        $linkU = $this->linkPannello((int) $p['id']);
        $intest = '<p><strong>' . $h($p['modulo_titolo']) . '</strong> · pratica <strong>' . $h($p['codice']) . '</strong></p>';
        $bottone = fn ($u, $t) => "<p style='margin-top:18px;'><a href='" . $h($u) . "' style='background:#047857;color:#fff;padding:10px 18px;text-decoration:none;border-radius:6px;font-weight:bold;'>" . $t . '</a></p>';
        $ufficio = $this->ufficio->emailUfficio((string) ($p['email_ufficio'] ?? ''), 'pratiche');
        // Nuove pratiche: ai manager (che smistano) se ci sono; messaggi dello studente: a chi ha la pratica in carico
        $smistano = array_keys(array_filter($this->uffici->tutti(), fn ($u) => (int) $u['smista'] === 1));
        $manager = array_column(array_filter($this->ufficio->operatori(), fn ($o) => in_array((int) $o['ufficio_id'], $smistano, true)), 'email');
        $inCarico = !empty($p['assegnata_a']) ? $this->ufficio->operatore((int) $p['assegnata_a']) : null;
        if ($tipo === 'nuova' && $manager && trim((string) ($p['email_ufficio'] ?? '')) === '') {
            $ufficio = $manager;
        }
        // Pratica che parte da un ufficio (es. protocollo): avvisate le persone di quell'ufficio
        if ($tipo === 'nuova' && !empty($p['ufficio_id']) && ($delUff = array_column(array_filter($this->ufficio->operatori(), fn ($o) => (int) $o['ufficio_id'] === (int) $p['ufficio_id']), 'email'))) {
            $ufficio = $delUff;
        }
        if ($tipo === 'msg_studente' && $inCarico) {
            $ufficio = [$inCarico['email']];
        }
        switch ($tipo) {
            case 'nuova':
                if (filter_var($p['email'], FILTER_VALIDATE_EMAIL)) {
                    $this->invia($p['email'], 'Pratica ricevuta: ' . $p['modulo_titolo'], '<p>Gentile ' . $h($p['nome']) . ',</p><p>abbiamo ricevuto la tua richiesta.</p>' . $intest . "<p>Puoi seguirne lo stato e scrivere all'ufficio dalla tua Area personale.</p>" . $bottone($linkS, 'Vedi la pratica'));
                }
                foreach ($ufficio as $e) {
                    $this->invia($e, 'Nuova pratica: ' . $p['modulo_titolo'] . ' – ' . trim($p['cognome'] . ' ' . $p['nome']), '<p>È arrivata una nuova pratica.</p>' . $intest . '<p>' . $h(trim($p['nome'] . ' ' . $p['cognome'])) . ' · ' . $h($p['email']) . ($p['matricola'] !== '' ? ' · matricola ' . $h($p['matricola']) : '') . '</p>' . $bottone($linkU, 'Apri nel pannello'));
                }
                break;
            case 'stato':
                $st = Costanti::STATI_PRATICA[$p['stato']][0] ?? $p['stato'];
                if (filter_var($p['email'], FILTER_VALIDATE_EMAIL)) {
                    $this->invia($p['email'], 'Pratica ' . $p['codice'] . ': ' . $st, '<p>Gentile ' . $h($p['nome']) . ',</p><p>la tua pratica è ora: <strong>' . $h($st) . '</strong>.</p>' . $intest . ($testo !== '' ? "<p style='background:#f1f5f9;padding:10px;border-radius:6px;'>" . nl2br($h($testo)) . '</p>' : '') . $bottone($linkS, 'Vedi la pratica'));
                }
                break;
            case 'msg_ufficio':
                if (filter_var($p['email'], FILTER_VALIDATE_EMAIL)) {
                    $this->invia($p['email'], 'Nuovo messaggio sulla pratica ' . $p['codice'], '<p>Gentile ' . $h($p['nome']) . ", l'ufficio ti ha scritto:</p>" . $intest . "<p style='background:#f1f5f9;padding:10px;border-radius:6px;'>" . nl2br($h($testo)) . '</p>' . $bottone($linkS, 'Rispondi'));
                }
                break;
            case 'passaggio':
                // Lo studente sa a chi è passata la pratica (la nota per l'operatore resta interna)
                if (filter_var($p['email'], FILTER_VALIDATE_EMAIL)) {
                    $this->invia($p['email'], 'Pratica ' . $p['codice'] . ': passata a ' . $testo, '<p>Gentile ' . $h($p['nome']) . ',</p><p>la tua pratica è passata a: <strong>' . $h($testo) . '</strong>.</p>' . $intest . "<p>Se l'ufficio ti chiede altri documenti li puoi aggiungere dalla pratica.</p>" . $bottone($linkS, 'Vedi la pratica'));
                }
                break;
            case 'msg_studente':
                foreach ($ufficio as $e) {
                    $this->invia($e, 'Messaggio sulla pratica ' . $p['codice'] . ' – ' . trim($p['cognome'] . ' ' . $p['nome']), '<p>Nuovo messaggio dello studente:</p>' . $intest . "<p style='background:#f1f5f9;padding:10px;border-radius:6px;'>" . nl2br($h($testo)) . '</p>' . $bottone($linkU, 'Apri nel pannello'));
                }
                break;
        }
    }

    /** Link alla pratica nel pannello Didattica. */
    public function linkPannello(int $id): string
    {
        return $this->sito->urlBase() . '/admin/didattica.php?tab=pratiche&id=' . $id;
    }

    /** Email col colore della Didattica. */
    public function invia(string $a, string $oggetto, string $corpo): void
    {
        $this->mailer->invia($a, $oggetto, $corpo, self::COLORE);
    }

    /** Pulsante verde «Apri…» con il link. */
    public function bottone(string $url, string $testo): string
    {
        return "<p style='margin-top:18px;'><a href='" . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . "' style='background:#047857;color:#fff;padding:10px 18px;text-decoration:none;border-radius:6px;font-weight:bold;'>" . $testo . '</a></p>';
    }
}
