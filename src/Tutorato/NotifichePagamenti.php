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
        foreach ($destinatari as $e) {
            $this->notifiche->invia(
                $e,
                $n === 1 ? 'Tutorato: una pratica da pagare – ' . $this->nome($pratiche[0]) : "Tutorato: $n pratiche da pagare",
                '<p>Gentile ' . $this->h($ufficio) . ',</p><p>' . $this->h($da !== '' ? $da : 'Il referente del tutorato') . ' ha validato e passato ' . ($n === 1 ? '<strong>una pratica</strong>' : "<strong>$n pratiche</strong>")
                . ' all\'ufficio pagamenti:</p><ul>' . $righe . '</ul><p>Nella scheda trovi decreti, UNILAV, lettera di incarico, registro, fine attività e dati per il pagamento.</p>',
                $this->sito->urlBase() . '/pagamenti_tutorato.php',
                'Apri le pratiche da pagare'
            );
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
        foreach ($destinatari as $e) {
            $this->notifiche->invia(
                $e,
                'Tutorato: pratica rimandata dall\'ufficio pagamenti – ' . $this->nome($i),
                '<p>L\'ufficio pagamenti ha rimandato la pratica di <strong>' . $this->h($this->nome($i)) . '</strong> (' . $this->h($i['bando_titolo']) . ')' . ($da !== '' ? ' (' . $this->h($da) . ')' : '') . ' per questo motivo:</p>'
                . "<blockquote style='margin:12px 0;padding:8px 14px;border-left:4px solid #b45309;background:#fffbeb;'>" . nl2br($this->h($nota)) . '</blockquote><p>Correggi quanto serve e passala di nuovo ai pagamenti.</p>',
                $this->sito->urlBase() . '/admin/tutorato.php?vista=validare',
                'Apri le pratiche da validare'
            );
        }
    }

    /** Al docente titolare: il referente non ha validato la fine attività e la rimanda con il motivo. @param array<string, mixed> $i */
    public function rimandataAlDocente(array $i, string $motivo): void
    {
        $this->notifiche->invia(
            (string) $i['docente_email'],
            'Tutorato: fine attività da rivedere – ' . $this->nome($i),
            '<p>Gentile ' . $this->h($i['docente_titolo'] . ' ' . trim($i['docente_nome'] . ' ' . $i['docente_cognome'])) . ',</p><p>il referente del tutorato ha controllato la fine attività di <strong>'
            . $this->h($this->nome($i)) . '</strong> (' . $this->h($i['bando_titolo']) . ') e la rimanda a lei per questo motivo:</p>'
            . "<blockquote style='margin:12px 0;padding:8px 14px;border-left:4px solid #b45309;background:#fffbeb;'>" . nl2br($this->h($motivo)) . '</blockquote>'
            . '<p>Controlli il registro, approvi le ore e confermi di nuovo la fine delle attività: il portale prepara la dichiarazione da firmare.</p>',
            $this->sito->urlBase() . '/registro_tutorato.php?id=' . (int) $i['id'],
            'Apri il registro'
        );
    }

    /** Al tutor: pagamento disposto, a conclusione della pratica. @param array<string, mixed> $i */
    public function pagato(array $i, string $data, string $riferimento): void
    {
        $this->notifiche->invia(
            (string) $i['email'],
            'Tutorato: pagamento disposto',
            '<p>Gentile ' . $this->h(trim($i['nome'] . ' ' . $i['cognome'])) . ',</p><p>il pagamento del tuo incarico di tutorato (' . $this->h($i['bando_titolo']) . ') è stato <strong>disposto il '
            . $this->h(date('d/m/Y', (int) strtotime($data))) . '</strong>' . ($riferimento !== '' ? ' (riferimento: ' . $this->h($riferimento) . ')' : '') . ' sull\'IBAN che hai indicato.</p>'
            . '<p>Con questo la pratica è conclusa. Nell\'Area personale trovi tutti i tuoi documenti.</p>',
            $this->sito->urlBase() . '/area_personale.php',
            'Apri l\'Area personale'
        );
    }
}
