<?php

declare(strict_types=1);

namespace App\ConvenzioniTesi;

use App\Core\Orologio;

/**
 * Le convenzioni di tesi della persona per la tessera dell'area personale: quelle legate alla sua email (verificata: vale quella del login o quella
 * confermata col codice, vedi Auth/VerificaEmail), ognuna con i passi di PercorsoAzienda. Il link personale (token) lo vede solo chi ha quell'email.
 * Una convenzione stipulata scompare da qui un anno dopo la scadenza, o appena ne esiste una nuova aperta (il rinnovo).
 */
final class ServizioConvenzioniAzienda
{
    public const GIORNI_STORICO = 365;

    public function __construct(private ConvenzioneTesiRepository $conv, private Orologio $orologio)
    {
    }

    /**
     * @param array<string, mixed>|null $utente
     * @return list<array{id: int, azienda: string, stato: string, percorso: array<string, mixed>}> prima quelle con qualcosa da fare
     */
    public function perUtente(?array $utente): array
    {
        $email = strtolower(trim((string) ($utente['email'] ?? '')));
        if ($email === '' || empty($utente['id'])) {
            return [];
        }
        $oggi = $this->orologio->adesso()->format('Y-m-d');

        return self::elenco($this->conv->perEmail([$email]), $oggi);
    }

    /**
     * @param list<array<string, mixed>> $righe convenzioni della persona, dalla più recente
     * @return list<array{id: int, azienda: string, stato: string, percorso: array<string, mixed>}>
     */
    public static function elenco(array $righe, string $oggi): array
    {
        $aperte = [];
        foreach ($righe as $c) {
            if ((string) $c['stato'] !== Costanti::PROTOCOLLATA) {
                $aperte[strtolower((string) $c['email'])] = true;
            }
        }
        $limite = date('Y-m-d', (int) strtotime($oggi . ' -' . self::GIORNI_STORICO . ' days'));
        $out = [];
        foreach ($righe as $c) {
            if ((string) $c['stato'] === Costanti::PROTOCOLLATA) {
                if (isset($aperte[strtolower((string) $c['email'])]) || ((string) ($c['valida_al'] ?? '') !== '' && (string) $c['valida_al'] < $limite)) {
                    continue;
                }
            }
            $out[] = ['id' => (int) $c['id'], 'azienda' => (string) (($c['azienda'] ?? '') ?: $c['email']), 'stato' => (string) $c['stato'], 'percorso' => PercorsoAzienda::per($c, $oggi)];
        }
        usort($out, static fn (array $a, array $b): int => ($a['percorso']['azione'] === null) <=> ($b['percorso']['azione'] === null));

        return $out;
    }
}
