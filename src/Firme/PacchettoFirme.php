<?php

declare(strict_types=1);

namespace App\Firme;

/**
 * Lo ZIP dei documenti scelti da firmare sul proprio computer: un PDF per documento, con il nome <tipo>_<id>_<codice>.pdf che serve poi per
 * abbinare i file firmati alla ricarica. Il file temporaneo lo cancella chi lo scarica.
 */
final class PacchettoFirme
{
    /**
     * I documenti scelti, riconosciuti da «tipo:id»: solo quelli che aspettano davvero la firma della persona (l'elenco arriva dalla scrivania).
     *
     * @param list<DocumentoDaFirmare> $disponibili
     * @param list<string> $scelti
     * @return list<DocumentoDaFirmare>
     */
    public static function scelti(array $disponibili, array $scelti): array
    {
        $out = [];
        foreach ($scelti as $chiave) {
            if (!preg_match('/^([a-z_]+):(\d+)$/', (string) $chiave, $m)) {
                continue;
            }
            $d = ScrivaniaFirme::trova($disponibili, $m[1], (int) $m[2]);
            if ($d !== null && !isset($out[$d->nomeFile])) {
                $out[$d->nomeFile] = $d;
            }
        }

        return array_values($out);
    }

    /**
     * Crea lo ZIP in un file temporaneo.
     *
     * @param list<DocumentoDaFirmare> $documenti
     * @return array{percorso: string|null, inclusi: int, senza_pdf: list<string>}
     */
    public static function crea(array $documenti): array
    {
        $senza = [];
        $validi = [];
        foreach ($documenti as $d) {
            if ($d->percorso !== null && is_file($d->percorso)) {
                $validi[] = $d;
            } else {
                $senza[] = $d->titolo;
            }
        }
        if (!$validi || !class_exists(\ZipArchive::class)) {
            return ['percorso' => null, 'inclusi' => 0, 'senza_pdf' => $senza];
        }
        $tmp = tempnam(sys_get_temp_dir(), 'firme');
        $zip = new \ZipArchive();
        if ($tmp === false || $zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            return ['percorso' => null, 'inclusi' => 0, 'senza_pdf' => $senza];
        }
        foreach ($validi as $d) {
            $zip->addFile((string) $d->percorso, $d->nomeFile);
        }
        $zip->close();

        return ['percorso' => $tmp, 'inclusi' => count($validi), 'senza_pdf' => $senza];
    }
}
