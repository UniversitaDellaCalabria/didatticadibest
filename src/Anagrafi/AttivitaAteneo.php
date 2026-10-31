<?php

declare(strict_types=1);

namespace App\Anagrafi;

/**
 * Una riga dell'API activities del portale di Ateneo (insegnamenti) portata ai campi dell'archivio.
 * L'elenco dà codice, nome, SSD, semestre, docente, corso di studio e dipartimento; i CFU arrivano solo dal dettaglio
 * (activities/<id>/, campo StudyActivityECTS); la pagina dell'insegnamento sul portale è sempre storage/activities/<id>/.
 */
final class AttivitaAteneo
{
    public const URL_PAGINA = 'https://www.unical.it/storage/activities/';

    /** Pagina dell'insegnamento sul portale di Ateneo, vuota se manca l'id */
    public static function urlPagina(int $id): string
    {
        return $id > 0 ? self::URL_PAGINA . $id . '/' : '';
    }

    /**
     * Campi comuni a archivio e catalogo, da una riga dell'elenco. L'integrato di cui l'insegnamento è modulo sta in
     * StudyActivityFathers (FatherID, FatherName). Null se manca l'id o il nome.
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>|null
     */
    public static function daElenco(array $r): ?array
    {
        $id = (int) ($r['StudyActivityID'] ?? 0);
        $nome = trim((string) ($r['StudyActivityName'] ?? ''));
        if ($id <= 0 || $nome === '') {
            return null;
        }
        $padre = null;
        $padreNome = '';
        $padri = (array) ($r['StudyActivityFathers'] ?? []);
        if ($padri) {
            $p0 = reset($padri);
            $padre = (int) (is_array($p0) ? ($p0['FatherID'] ?? $p0['StudyActivityID'] ?? $p0['id'] ?? 0) : $p0) ?: null;
            $padreNome = is_array($p0) ? mb_substr(Testi::maiuscoleCorso(trim((string) ($p0['FatherName'] ?? $p0['StudyActivityName'] ?? ''))), 0, 255) : '';
        }

        return [
            'id' => $id, 'codice' => mb_substr((string) ($r['StudyActivityCod'] ?? ''), 0, 30),
            'nome' => mb_substr(Testi::maiuscoleCorso($nome), 0, 255),
            'cds_cod' => mb_substr((string) ($r['StudyActivityCdSCod'] ?? ''), 0, 20),
            'cds_nome' => mb_substr(Testi::maiuscoleCorso((string) ($r['StudyActivityCdSName'] ?? '')), 0, 255),
            'anno_corso' => (int) ($r['StudyActivityYear'] ?? 0) ?: null,
            'semestre' => mb_substr((string) ($r['StudyActivitySemester'] ?? ''), 0, 60),
            'ssd_cod' => mb_substr((string) ($r['StudyActivitySSDCod'] ?? ''), 0, 20),
            'ssd' => mb_substr(Testi::maiuscoleCorso((string) ($r['StudyActivitySSD'] ?? '')), 0, 150),
            'lingua' => mb_substr((string) (is_array($r['StudyActivityLanguage'] ?? null) ? implode(', ', $r['StudyActivityLanguage']) : ($r['StudyActivityLanguage'] ?? '')), 0, 60),
            'docente' => mb_substr(Testi::maiuscoleNome((string) ($r['StudyActivityTeacherName'] ?? '')), 0, 150),
            'docente_id' => mb_substr((string) ($r['StudyActivityTeacherID'] ?? ''), 0, 30),
            'partizione' => mb_substr(trim((string) ($r['StudyActivityPartitionDes'] ?? $r['StudyActivityExtendedPartitionDes'] ?? '')), 0, 150),
            'padre_id' => $padre, 'padre_nome' => $padreNome,
            'dipartimento_cod' => mb_substr((string) ($r['DepartmentCod'] ?? ''), 0, 20),
            'dipartimento' => mb_substr(trim((string) ($r['DepartmentName'] ?? '')), 0, 255),
            'cfu' => Testi::cfuAttivitaApi($r),
            'link' => self::urlPagina($id),
        ];
    }

    /** Codice dell'insegnamento integrato di cui l'attività è modulo (StudyActivityRoot del dettaglio), null se non c'è */
    public static function codiceRadice(array $d): ?string
    {
        $c = trim((string) (is_array($d['StudyActivityRoot'] ?? null) ? ($d['StudyActivityRoot']['StudyActivityCod'] ?? '') : ''));

        return $c !== '' ? mb_substr($c, 0, 30) : null;
    }

    /** CFU dal dettaglio dell'attività (StudyActivityECTS), null se non indicati */
    public static function cfuDaDettaglio(array $d): ?float
    {
        $v = $d['StudyActivityECTS'] ?? null;

        return is_numeric($v) && (float) $v > 0 ? (float) $v : null;
    }
}
