<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Sito;
use App\Email\InvioEmail;

/**
 * Email delle pratiche: allo studente (stato, messaggi, passaggi) e agli uffici (nuove pratiche, messaggi, assegnazioni, promemoria).
 * I testi stanno nel catalogo (CatalogoDidattica) e si modificano dalla pagina «Testi delle email» del pannello.
 */
final class NotifichePratiche
{
    public function __construct(private InvioEmail $invio, private ServizioUffici $ufficio, private UfficioRepository $uffici, private Sito $sito, private SostituzioniOperatori $sostituzioni, private SondaggioPratica $sondaggio)
    {
    }

    /**
     * I dati della pratica per i segnaposto delle email.
     *
     * @param array<string, mixed> $p
     * @return array<string, string>
     */
    public static function datiPratica(array $p): array
    {
        return [
            'nome' => (string) $p['nome'],
            'studente' => trim($p['nome'] . ' ' . $p['cognome']),
            'studente_cn' => trim($p['cognome'] . ' ' . $p['nome']),
            'modulo' => (string) $p['modulo_titolo'],
            'codice' => (string) $p['codice'],
            'email_studente' => (string) ($p['email'] ?? ''),
            'matricola_testo' => (string) ($p['matricola'] ?? '') !== '' ? ' · matricola ' . $p['matricola'] : '',
        ];
    }

    /**
     * Email allo studente (stato, messaggio dell'ufficio) o all'ufficio (nuova pratica, messaggio dello studente).
     *
     * @param array<string, mixed> $p
     */
    public function email(array $p, string $tipo, string $testo = ''): void
    {
        $d = self::datiPratica($p);
        $linkS = $this->sito->urlBase() . '/pratiche.php?id=' . (int) $p['id'];
        $linkU = $this->linkPannello((int) $p['id']);
        $ufficio = $this->ufficio->emailUfficio((string) ($p['email_ufficio'] ?? ''), 'pratiche');
        // Nuove pratiche: ai manager (che smistano) se ci sono; messaggi dello studente: a chi ha la pratica in carico
        $smistano = array_keys(array_filter($this->uffici->tutti(), fn ($u) => (int) $u['smista'] === 1));
        $manager = array_column(array_filter($this->ufficio->operatori(), fn ($o) => array_intersect($o['_uffici'], $smistano)), 'email');
        $inCarico = !empty($p['assegnata_a']) ? $this->ufficio->operatore((int) $p['assegnata_a']) : null;
        if ($tipo === 'nuova' && $manager && trim((string) ($p['email_ufficio'] ?? '')) === '') {
            $ufficio = $manager;
        }
        // Pratica che parte da un ufficio (es. protocollo): avvisate le persone di quell'ufficio
        if ($tipo === 'nuova' && !empty($p['ufficio_id']) && ($delUff = array_column(array_filter($this->ufficio->operatori(), fn ($o) => in_array((int) $p['ufficio_id'], $o['_uffici'], true)), 'email'))) {
            $ufficio = $delUff;
        }
        if ($tipo === 'msg_studente' && $inCarico) {
            $ufficio = $this->sostituzioni->destinatari($inCarico);
        }
        $studenteValido = filter_var($p['email'], FILTER_VALIDATE_EMAIL) !== false;
        switch ($tipo) {
            case 'nuova':
                if ($studenteValido) {
                    $this->modello($p['email'], 'pratica_ricevuta', $d, $linkS);
                }
                foreach ($ufficio as $e) {
                    $this->modello($e, 'pratica_nuova_ufficio', $d, $linkU);
                }
                break;
            case 'stato':
                $st = Costanti::STATI_PRATICA[$p['stato']][0] ?? $p['stato'];
                if ($studenteValido) {
                    $this->modello($p['email'], 'pratica_stato', $d + ['stato' => $st, 'nota' => InvioEmail::riquadro($testo)], $linkS, $this->invitoSondaggio($p));
                }
                break;
            case 'msg_ufficio':
                if ($studenteValido) {
                    $this->modello($p['email'], 'pratica_messaggio_ufficio', $d + ['messaggio' => InvioEmail::riquadro($testo)], $linkS);
                }
                break;
            case 'passaggio':
                // Lo studente sa a chi è passata la pratica (la nota per l'operatore resta interna)
                if ($studenteValido) {
                    $this->modello($p['email'], 'pratica_passaggio', $d + ['destinazione' => $testo], $linkS);
                }
                break;
            case 'msg_studente':
                foreach ($ufficio as $e) {
                    $this->modello($e, 'pratica_messaggio_studente', $d + ['messaggio' => InvioEmail::riquadro($testo)], $linkU);
                }
                break;
        }
    }

    /**
     * Email dal catalogo con il colore della Didattica; $dati vale per i segnaposto, $coda è HTML da mettere dopo il pulsante.
     *
     * @param array<string, scalar|null> $dati
     */
    public function modello(?string $a, string $chiave, array $dati, string $link = '', string $coda = ''): void
    {
        $this->invio->invia($a, $chiave, $dati, $link, [], $coda);
    }

    /**
     * Allo studente: l'ufficio ha caricato le marche da bollo in Esse3 e può procedere al pagamento.
     *
     * @param array<string, mixed> $p
     */
    public function marcheCaricate(array $p): void
    {
        if (!filter_var($p['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $this->modello($p['email'], 'pratica_marche_caricate', self::datiPratica($p), $this->sito->urlBase() . '/pratiche.php?id=' . (int) $p['id']);
    }

    /**
     * Allo studente: il documento firmato dal Direttore è pronto, si può scaricare e stampare.
     *
     * @param array<string, mixed> $p
     */
    public function documentoPronto(array $p): void
    {
        if (!filter_var($p['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $this->modello($p['email'], 'pratica_documento_pronto', self::datiPratica($p), $this->sito->urlBase() . '/pratiche.php?id=' . (int) $p['id']);
    }

    /** Link alla pratica nel pannello Didattica. */
    public function linkPannello(int $id): string
    {
        return $this->sito->urlBase() . '/admin/didattica.php?tab=pratiche&id=' . $id;
    }

    /** A pratica conclusa: l'invito (facoltativo) al sondaggio di due domande; vuoto se la pratica non è conclusa o lo studente ha già risposto. @param array<string, mixed> $p */
    private function invitoSondaggio(array $p): string
    {
        $link = $this->sondaggio->link($p);

        return $link === '' ? '' : "<p style='margin-top:22px;color:#475569;font-size:13px;'>Se vuoi, aiutaci a migliorare il servizio: <a href='" . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . "'>due domande, un minuto</a> (facoltativo).</p>";
    }

    /**
     * Gli indirizzi di un operatore: il suo e, se è assente, quello di chi lo sostituisce.
     *
     * @param array<string, mixed> $operatore
     * @return list<string>
     */
    public function destinatariOperatore(array $operatore): array
    {
        return $this->sostituzioni->destinatari($operatore);
    }
}
