<?php

declare(strict_types=1);

namespace App\ConvenzioniTesi;

/**
 * Il percorso di UNA convenzione di tesi vista dall'azienda, a passi: dati → (modifiche agli articoli) → firma e caricamento → protocollo e firma del
 * Direttore → stipulata. Dice a che passo si è e qual è l'unica cosa da fare (un pulsante che porta alla pagina con il link personale), oppure cosa si
 * aspetta. Solo regole: la riga di convenzioni_tesi arriva già letta; niente database né HTML.
 */
final class PercorsoAzienda
{
    /**
     * @param array<string, mixed> $c riga di convenzioni_tesi (stato, token, valida_al)
     * @return array{passi: list<array{chiave: string, nome: string, stato: string, nota: string}>, azione: ?array{testo: string, link: string}, attesa: string, scade_presto: bool}
     *   stato di un passo = fatto | qui | ''
     */
    public static function per(array $c, string $oggi): array
    {
        $stato = (string) ($c['stato'] ?? Costanti::INVITATA);
        $link = 'convenzione_tesi.php?t=' . urlencode((string) ($c['token'] ?? ''));
        $al = (string) ($c['valida_al'] ?? '');
        $stipulata = $stato === Costanti::PROTOCOLLATA;
        $scadePresto = $stipulata && $al !== '' && $al <= date('Y-m-d', (int) strtotime($oggi . ' +' . ServizioConvenzioniTesi::GIORNI_AVVISO_SCADENZA . ' days'));
        $scaduta = $stipulata && $al !== '' && $al < $oggi;

        $inUfficio = [Costanti::CARICATA, Costanti::IN_CARICO, Costanti::DA_FIRMARE, Costanti::FIRMATA];
        $firmataAzienda = $stipulata || in_array($stato, $inUfficio, true);
        $notaUfficio = match ($stato) {
            Costanti::CARICATA => 'ricevuta, il Dipartimento la prende in carico',
            Costanti::IN_CARICO => 'in carico al Dipartimento',
            Costanti::DA_FIRMARE => 'dal Direttore per la firma',
            Costanti::FIRMATA => 'firmata dal Direttore, manca il protocollo',
            default => '',
        };

        $voci = [['dati', 'Dati dell\'azienda', $stato !== Costanti::INVITATA, $stato === Costanti::INVITATA ? 'da inserire' : '']];
        if (in_array($stato, [Costanti::MODIFICHE, Costanti::RISPOSTA], true)) {
            $voci[] = ['modifiche', 'Modifiche agli articoli', false, $stato === Costanti::MODIFICHE ? 'le valuta il Dipartimento' : 'c\'è una risposta da vedere'];
        }
        $voci[] = ['firma', 'Firma e caricamento', $firmataAzienda, $stato === Costanti::RIFIUTATA ? 'il file è stato rifiutato: da ricaricare' : ($firmataAzienda ? '' : 'da scaricare, firmare in PAdES e caricare')];
        $voci[] = ['ufficio', 'Protocollo e firma del Direttore', $stipulata, $notaUfficio];
        $voci[] = ['stipulata', 'Stipulata', $stipulata, $stipulata && $al !== '' ? ($scaduta ? 'scaduta il ' : 'scade il ') . date('d/m/Y', (int) strtotime($al)) : ''];

        $passi = [];
        $qui = null;
        foreach ($voci as [$chiave, $nome, $fatto, $nota]) {
            $s = $fatto ? 'fatto' : ($qui === null ? 'qui' : '');
            $qui ??= $fatto ? null : $chiave;
            $passi[] = ['chiave' => $chiave, 'nome' => $nome, 'stato' => $s, 'nota' => ($fatto && $chiave !== 'stipulata') ? '' : ($s === 'qui' || $chiave === 'stipulata' ? $nota : '')];
        }

        $azione = null;
        $attesa = '';
        switch ($stato) {
            case Costanti::INVITATA:
                $azione = ['testo' => 'Inserisci i dati dell\'azienda', 'link' => $link];
                break;
            case Costanti::COMPILATA:
                $azione = ['testo' => 'Scarica, firma e carica la convenzione', 'link' => $link];
                break;
            case Costanti::RISPOSTA:
                $azione = ['testo' => 'Vedi la risposta alle modifiche', 'link' => $link];
                break;
            case Costanti::RIFIUTATA:
                $azione = ['testo' => 'Carica di nuovo la convenzione firmata', 'link' => $link];
                break;
            case Costanti::MODIFICHE:
                $attesa = 'Il Dipartimento sta valutando le modifiche che hai proposto.';
                break;
            case Costanti::PROTOCOLLATA:
                if ($scadePresto) {
                    $azione = ['testo' => $scaduta ? 'Rinnova la convenzione (scaduta)' : 'Rinnova la convenzione', 'link' => $link];
                }
                break;
            default:
                $attesa = $stato === Costanti::DA_FIRMARE ? 'La convenzione aspetta la firma del Direttore.' : 'Convenzione ricevuta: il Dipartimento la sta lavorando, ti scriviamo a ogni passo.';
        }

        return ['passi' => $passi, 'azione' => $azione, 'attesa' => $attesa, 'scade_presto' => $scadePresto];
    }
}
