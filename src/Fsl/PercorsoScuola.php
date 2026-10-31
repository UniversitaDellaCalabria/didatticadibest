<?php

declare(strict_types=1);

namespace App\Fsl;

/**
 * Il percorso di UNA prenotazione FSL vista dalla scuola, a passi: programma inviato → prenotazione confermata → convenzione → elenco studenti e
 * autorizzazione → attività svolta → attestati. Per ogni prenotazione dice a che passo si è e qual è l'unica cosa da fare (un pulsante).
 * Solo regole: i dati arrivano già calcolati da miei_eventi.php / ServizioConvenzioneOnline / ServizioDocumentiClasse; niente database né HTML.
 */
final class PercorsoScuola
{
    /**
     * @param array{
     *   id: int, codice: string, stato: string, token?: string, convenzione?: string, iter?: string,
     *   documenti?: ?array{elenco: bool, autorizzazione: bool, completi: bool, scadenza: ?string, scaduti: bool},
     *   al?: ?string, attestati?: bool, attestato_inviato?: bool
     * } $d stato = stato della prenotazione; token = link personale della convenzione ('' se non c'è); convenzione = 'si' (già stipulata) | 'no' | 'rinnovo' | '';
     *   iter = stato dell'iter della convenzione firmata; documenti = null se per l'attività non si chiedono elenco e autorizzazione
     * @return array{passi: list<array{chiave: string, nome: string, stato: string, nota: string}>, azione: ?array{testo: string, link: string}, attesa: string, fatto: bool}
     *   stato di un passo = fatto | qui | '' ; azione = l'unica cosa da fare adesso (null se si aspetta altri); attesa = cosa si aspetta se non c'è azione
     */
    public static function per(array $d, string $oggi): array
    {
        $stato = $d['stato'] !== '' ? $d['stato'] : 'confermata';
        $confermata = $stato === 'confermata';
        $codice = urlencode($d['codice']);
        $token = (string) ($d['token'] ?? '');
        $iter = (string) ($d['iter'] ?? '');
        $stipulata = ($d['convenzione'] ?? '') === 'si';
        $docs = $d['documenti'] ?? null;
        $finita = !empty($d['al']) && (string) $d['al'] < $oggi;
        $linkConv = $token !== '' ? 'convenzione_online.php?t=' . urlencode($token) . '#firmata' : 'miei_eventi.php#programma-fsl';

        // Convenzione: già stipulata (non serve), firmata dal Direttore, ricevuta e in carico, oppure da caricare firmata
        $convFatta = $stipulata || in_array($iter, [Costanti::ITER_FIRMATA, Costanti::ITER_PROTOCOLLATA], true);
        $convRicevuta = in_array($iter, [Costanti::ITER_CARICATA, Costanti::ITER_IN_CARICO, Costanti::ITER_DA_FIRMARE], true);
        $notaConv = match (true) {
            $stipulata => 'già in vigore',
            $iter === Costanti::ITER_PROTOCOLLATA => 'firmata e protocollata',
            $iter === Costanti::ITER_FIRMATA => 'firmata dal Direttore',
            $iter === Costanti::ITER_DA_FIRMARE => 'in firma al Direttore',
            $convRicevuta => 'ricevuta, il Dipartimento la prende in carico',
            $iter === Costanti::ITER_RIFIUTATA => 'da ricaricare',
            default => 'da caricare firmata',
        };

        $voci = [
            ['programma', 'Programma inviato', true, ''],
            ['prenotazione', 'Prenotazione confermata', $confermata, match ($stato) {
                'da_approvare' => 'in attesa di conferma',
                'in_attesa' => "in lista d'attesa",
                'richiesta_conferma' => 'posto disponibile: confermalo',
                'annullata', 'rifiutata', 'scaduta' => 'non più valida',
                default => '',
            }],
            ['convenzione', 'Convenzione', $convFatta, $notaConv],
        ];
        if ($docs !== null) {
            $voci[] = ['elenco', 'Elenco studenti', (bool) $docs['completi'],
                $docs['completi'] ? '' : 'manca l\'elenco' . (!empty($docs['scadenza']) ? ' · entro il ' . date('d/m/Y', (int) strtotime((string) $docs['scadenza'])) : '')];
        }
        $voci[] = ['attivita', 'Attività svolta', $finita, ''];
        $haAtt = !empty($d['attestati']);
        if ($haAtt) {
            $voci[] = ['attestati', 'Attestati', !empty($d['attestato_inviato']), !empty($d['attestato_inviato']) ? 'pronti da scaricare' : 'li invia il Dipartimento dopo l\'attività'];
        }

        $passi = [];
        $qui = null;
        foreach ($voci as [$chiave, $nome, $fatto, $nota]) {
            $s = $fatto ? 'fatto' : ($qui === null ? 'qui' : '');
            $qui ??= $fatto ? null : $chiave;
            $passi[] = ['chiave' => $chiave, 'nome' => $nome, 'stato' => $s, 'nota' => $fatto ? ($chiave === 'convenzione' ? $notaConv : '') : ($s === 'qui' ? $nota : '')];
        }

        $azione = null;
        $attesa = '';
        switch ($qui) {
            case 'prenotazione':
                if ($stato === 'richiesta_conferma') {
                    $azione = ['testo' => 'Conferma il posto', 'link' => 'miei_eventi.php?conferma_posto=' . (int) $d['id']];
                } else {
                    $attesa = $stato === 'in_attesa' ? "Sei in lista d'attesa: ti avvisiamo se si libera un posto." : ($stato === 'da_approvare' ? 'Il Dipartimento deve ancora confermare la prenotazione.' : 'La prenotazione non è più valida.');
                }
                break;
            case 'convenzione':
                if ($convRicevuta) {
                    $attesa = $iter === Costanti::ITER_DA_FIRMARE ? 'La convenzione aspetta la firma del Direttore.' : 'Convenzione ricevuta: il Dipartimento la prende in carico (firma e protocollo).';
                } else {
                    $azione = ['testo' => $iter === Costanti::ITER_RIFIUTATA ? 'Carica di nuovo la convenzione firmata' : 'Carica la convenzione firmata', 'link' => $linkConv];
                }
                break;
            case 'elenco':
                $azione = ['testo' => "Carica l'elenco studenti", 'link' => 'elenco_studenti.php?code=' . $codice];
                break;
            case 'attivita':
                $attesa = 'Dopo l\'attività potrai scaricare gli attestati degli studenti.';
                break;
            case 'attestati':
                $attesa = 'Gli attestati arrivano dal Dipartimento dopo l\'attività.';
                break;
            default:
                if ($haAtt) {
                    $azione = ['testo' => 'Attestati degli studenti', 'link' => 'attestati_gruppo.php?code=' . $codice];
                }
        }

        return ['passi' => $passi, 'azione' => $azione, 'attesa' => $attesa, 'fatto' => $qui === null];
    }
}
