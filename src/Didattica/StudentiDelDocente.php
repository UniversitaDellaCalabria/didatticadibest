<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Database;

/**
 * «I miei studenti» del docente, in sola lettura: le pratiche in cui il docente è stato indicato come relatore (o altra persona che firma), con lo stato della
 * pratica e della sua firma. Serve a sapere se un tesista ha inviato la richiesta e a che punto è. Non mostra allegati, risposte o messaggi della pratica: solo
 * studente, tipo di richiesta, codice e stato (il docente conosce già la richiesta perché gli arriva il link per firmarla). Le regole di cosa mostrare stanno
 * in riepilogo() (senza database), la lettura in perUtente().
 */
final class StudentiDelDocente
{
    public const MASSIMO = 100;

    public function __construct(private Database $db)
    {
    }

    /**
     * @param array<string, mixed>|null $utente
     * @return list<array{id: int, codice: string, studente: string, modulo: string, stato: string, firma: string, dal: string}> dalla più recente; firma = attesa | firmata | rifiutata
     */
    public function perUtente(?array $utente): array
    {
        if (!$utente || empty($utente['id'])) {
            return [];
        }
        $email = strtolower(trim((string) ($utente['email'] ?? '')));
        $persona = trim((string) ($utente['persona_id'] ?? ''));
        if ($email === '' && $persona === '') {
            return [];
        }
        $righe = $this->db->righe(
            "SELECT f.id AS firma_id, f.pratica_id, f.stato AS firma, f.richiesta_il, p.codice, p.nome, p.cognome, p.stato, m.titolo
             FROM pratiche_firme f JOIN pratiche p ON p.id = f.pratica_id JOIN didattica_moduli m ON m.id = p.modulo_id
             WHERE f.tipo = 'persona' AND p.stato <> 'bozza' AND ((? <> '' AND f.email = ?) OR (? <> '' AND f.persona_id = ?))
             ORDER BY f.id DESC",
            [$email, $email, $persona, $persona]
        );
        $out = [];
        foreach ($righe as $r) {
            $id = (int) $r['pratica_id'];
            if (isset($out[$id])) {
                continue; // per ogni pratica conta l'ultima richiesta di firma
            }
            $out[$id] = [
                'id' => $id, 'codice' => (string) $r['codice'], 'studente' => trim($r['nome'] . ' ' . $r['cognome']), 'modulo' => (string) $r['titolo'],
                'stato' => (string) $r['stato'], 'firma' => (string) $r['firma'], 'dal' => (string) $r['richiesta_il'],
            ];
        }

        return array_slice(array_values($out), 0, self::MASSIMO);
    }

    /**
     * Il testo della tessera e i numeri per gruppo.
     *
     * @param list<array{stato: string, firma: string}> $pratiche risultato di perUtente()
     * @return array{testo: string, totale: int, attesa: int, in_corso: int, concluse: int}
     */
    public static function riepilogo(array $pratiche): array
    {
        $attesa = $in_corso = $concluse = 0;
        foreach ($pratiche as $p) {
            if ($p['firma'] === 'attesa' && in_array($p['stato'], ['inviata', 'in_lavorazione'], true)) {
                ++$attesa;
            } elseif (in_array($p['stato'], ['accolta', 'respinta', 'chiusa'], true)) {
                ++$concluse;
            } else {
                ++$in_corso;
            }
        }
        $totale = count($pratiche);
        if ($totale === 0) {
            return ['testo' => '', 'totale' => 0, 'attesa' => 0, 'in_corso' => 0, 'concluse' => 0];
        }
        $parti = [];
        if ($attesa > 0) {
            $parti[] = $attesa . ($attesa === 1 ? ' aspetta la tua firma' : ' aspettano la tua firma');
        }
        if ($in_corso > 0) {
            $parti[] = $in_corso . ' in corso';
        }
        if ($concluse > 0) {
            $parti[] = $concluse . ($concluse === 1 ? ' conclusa' : ' concluse');
        }

        return ['testo' => $totale . ($totale === 1 ? ' richiesta di uno studente' : ' richieste di studenti') . ': ' . implode(' · ', $parti) . '.', 'totale' => $totale, 'attesa' => $attesa, 'in_corso' => $in_corso, 'concluse' => $concluse];
    }
}
