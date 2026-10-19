<?php

declare(strict_types=1);

namespace App\Tutorato;

use App\Core\Sito;

/** Email dell'ultima parte dell'iter del tutorato: validazione del referente, ufficio pagamenti e avviso al tutor. */
final class NotifichePagamenti
{
    public function __construct(private NotificheIncarichi $notifiche, private Sito $sito)
    {
    }

    private function h(mixed $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    private function nome(array $i): string
    {
        return trim($i['cognome'] . ' ' . $i['nome']);
    }

    /**
     * All'ufficio pagamenti: una sola email con l'elenco delle pratiche appena passate.
     *
     * @param list<string> $destinatari
     * @param list<array<string, mixed>> $pratiche
     */
    public function alUfficio(array $destinatari, string $ufficio, array $pratiche, string $da): void
    {
        $righe = '';
        foreach ($pratiche as $i) {
            $righe .= '<li><strong>' . $this->h($this->nome($i)) . '</strong> · ' . $this->h($i['bando_titolo']) . ' · ' . $this->h(DatiLettera::oreTesto($i['ore_approvate'] ?? $i['ore'])) . ' ore</li>';
        }
        $n = count($pratiche);
        $dati = [
            'ufficio' => $ufficio,
            'referente' => $da !== '' ? $da : 'Il referente del tutorato',
            'quante' => $n === 1 ? 'una pratica' : "$n pratiche",
            'dettaglio' => $n === 1 ? ' – ' . $this->nome($pratiche[0]) : '',
            'elenco' => '<ul>' . $righe . '</ul>',
        ];
        foreach ($destinatari as $e) {
            $this->notifiche->inviaModello($e, 'tutorato_pagamenti_da_pagare', $dati, $this->sito->urlBase() . '/pagamenti_tutorato.php');
        }
    }

    /**
     * Ai referenti: l'ufficio pagamenti ha rimandato la pratica con una nota.
     *
     * @param list<string> $destinatari
     * @param array<string, mixed> $i
     */
    public function rimandataAlReferente(array $destinatari, array $i, string $nota, string $da): void
    {
        $dati = ServizioIncarichi::datiEmail($i) + ['da_chi' => $da !== '' ? ' (' . $da . ')' : '', 'nota' => $nota];
        foreach ($destinatari as $e) {
            $this->notifiche->inviaModello($e, 'tutorato_pagamenti_rimandata', $dati, $this->sito->urlBase() . '/admin/tutorato.php?vista=validare');
        }
    }

    /** Al docente titolare: il referente non ha validato la fine attività e la rimanda con il motivo. @param array<string, mixed> $i */
    public function rimandataAlDocente(array $i, string $motivo): void
    {
        $this->notifiche->inviaModello((string) $i['docente_email'], 'tutorato_fine_da_rivedere', ServizioIncarichi::datiEmail($i) + ['motivo' => $motivo], $this->sito->urlBase() . '/registro_tutorato.php?id=' . (int) $i['id']);
    }

    /** Al tutor: pagamento disposto, a conclusione della pratica. @param array<string, mixed> $i */
    public function pagato(array $i, string $data, string $riferimento): void
    {
        $this->notifiche->inviaModello(
            (string) $i['email'],
            'tutorato_pagamento_disposto',
            ServizioIncarichi::datiEmail($i) + ['data' => date('d/m/Y', (int) strtotime($data)), 'riferimento' => $riferimento !== '' ? ' (riferimento: ' . $riferimento . ')' : ''],
            $this->sito->urlBase() . '/area_personale.php'
        );
    }
}
