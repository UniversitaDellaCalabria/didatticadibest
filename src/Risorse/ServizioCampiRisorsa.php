<?php

declare(strict_types=1);

namespace App\Risorse;

use App\Didattica\CampiModulo;
use App\Didattica\LetturaRisposte;

/**
 * I campi personalizzati del modulo di prenotazione di una risorsa: li costruisce l'admin dal pannello (stesso formato e stessi controlli dei moduli
 * della didattica, ma solo i tipi semplici: testo, numero, data, scelte, dichiarazioni) e le risposte si leggono e si mostrano come per le pratiche.
 */
final class ServizioCampiRisorsa
{
    /** Tipi ammessi (niente allegati né campi dalle anagrafi della didattica). */
    public const TIPI = ['text' => 'Testo breve', 'textarea' => 'Testo lungo', 'email' => 'Email', 'tel' => 'Telefono', 'number' => 'Numero', 'date' => 'Data', 'time' => 'Ora',
        'select' => 'Tendina', 'radio' => 'Scelta singola', 'multicheck' => 'Scelta multipla', 'checkbox' => 'Casella (sì/no)', 'dichiarazione' => 'Dichiarazione da accettare', 'info' => 'Testo informativo'];

    public function __construct(private RisorsaRepository $risorse, private LetturaRisposte $lettura)
    {
    }

    /**
     * I campi dal modulo del pannello (righe parallele c_etichetta[], c_tipo[], c_opzioni[], c_obbl[], c_aiuto[]) come JSON; null = nessun campo.
     *
     * @param array<string, mixed> $post
     */
    public function jsonDaForm(array $post): ?string
    {
        $campi = [];
        foreach ((array) ($post['c_etichetta'] ?? []) as $i => $et) {
            $et = trim((string) $et);
            $tipo = (string) ($post['c_tipo'][$i] ?? 'text');
            if ($et === '' || !isset(self::TIPI[$tipo])) {
                continue;
            }
            $campi[] = ['etichetta' => mb_substr($et, 0, 200), 'tipo' => $tipo, 'opzioni' => mb_substr((string) ($post['c_opzioni'][$i] ?? ''), 0, 1000),
                        'obbligatorio' => ($post['c_obbl'][$i] ?? '0') === '1', 'aiuto' => mb_substr((string) ($post['c_aiuto'][$i] ?? ''), 0, 500)];
        }

        return $campi ? (string) json_encode($campi, JSON_UNESCAPED_UNICODE) : null;
    }

    public function salva(int $risorsaId, array $post): void
    {
        $this->risorse->impostaCampi($risorsaId, $this->jsonDaForm($post));
    }

    /** Copia i campi di un'altra risorsa. Ritorna false se non c'è nulla da copiare. */
    public function copiaDa(int $risorsaId, int $daId): bool
    {
        $da = $risorsaId !== $daId ? $this->risorse->perId($daId) : null;
        if (!$da || trim((string) ($da['campi_json'] ?? '')) === '') {
            return false;
        }
        $this->risorse->impostaCampi($risorsaId, (string) $da['campi_json']);

        return true;
    }

    /**
     * I campi normalizzati della risorsa (come CampiModulo::da: nome «cN», etichetta, tipo, opzioni, obbligatorio…).
     *
     * @param array<string, mixed> $risorsa
     * @return list<array<string, mixed>>
     */
    public function campi(array $risorsa): array
    {
        return CampiModulo::da((string) ($risorsa['campi_json'] ?? ''));
    }

    /**
     * Risposte lette dal modulo di prenotazione (POST campo_cN). Ritorna [json delle risposte o null, errori].
     *
     * @param list<array<string, mixed>> $campi
     * @param array<string, mixed> $post
     * @return array{0: ?string, 1: list<string>}
     */
    public function leggi(array $campi, array $post): array
    {
        if (!$campi) {
            return [null, []];
        }
        [$risposte, $errori] = $this->lettura->leggi($campi, $post, [], false);

        return [$errori ? null : (string) json_encode($risposte, JSON_UNESCAPED_UNICODE), $errori];
    }

    /**
     * Le risposte come coppie [domanda, risposta] leggibili (email, elenchi, export), senza i campi nascosti o vuoti.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function coppie(?string $json): array
    {
        $out = [];
        foreach (json_decode((string) $json, true) ?: [] as $r) {
            $v = $r['valore'] ?? '';
            $v = is_array($v) ? implode(', ', array_map('strval', $v)) : trim((string) $v);
            if (empty($r['nascosto']) && $v !== '') {
                $out[] = [(string) ($r['etichetta'] ?? ''), $v];
            }
        }

        return $out;
    }
}
