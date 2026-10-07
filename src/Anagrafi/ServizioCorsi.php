<?php

declare(strict_types=1);

namespace App\Anagrafi;

use App\Core\Sito;

/**
 * Corsi di studio e insegnamenti del proprio dipartimento (tabelle corsi_studio e insegnamenti): scelta dei corsi nei campi dei
 * moduli, link alla pagina del corso sul portale di Ateneo, insegnamenti per l'anno accademico.
 * Spostato da inc/anagrafi.php; il risultato di alcune letture resta in memoria per la durata della richiesta, come prima.
 */
final class ServizioCorsi
{
    /** @var array<string, list<array<string, mixed>>>|null corsi visibili per tipo */
    private ?array $visibili = null;

    /** @var array<string, array<string, mixed>|null> */
    private array $perCodice = [];

    /** @var array<int, array{id: int, nome: string, corso: string, cfu: float|null, ssd: string, anno: int}>|null */
    private ?array $insegnamentiDipartimento = null;

    public function __construct(
        private CorsoRepository $corsi,
        private InsegnamentoRepository $insegnamenti,
        private ServizioCatalogo $catalogo,
        private ClientApiAteneo $api,
        private Sito $sito,
    ) {
    }

    /**
     * Corsi proposti nel campo "Corso di studio", raggruppati per tipo (Laurea, Laurea Magistrale…).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function visibili(): array
    {
        if ($this->visibili !== null) {
            return $this->visibili;
        }
        $out = [];
        $ordine = ['L' => 1, 'LM' => 2, 'LM5' => 3, 'LM6' => 3, 'FI' => 5];
        foreach ($this->corsi->visibili() as $c) {
            $out[$c['tipo_descrizione'] ?: 'Altri corsi'][] = $c + ['_o' => $ordine[$c['tipo']] ?? 4];
        }
        uasort($out, static fn (array $a, array $b): int => $a[0]['_o'] <=> $b[0]['_o']);

        return $this->visibili = $out;
    }

    /**
     * I corsi di studio di un'attività (scheda progetti_dettagli): quelli di corsi_codici, altrimenti il solo corso_codice delle schede più vecchie.
     *
     * @param array<string, mixed>|null $scheda
     * @return list<array<string, mixed>>
     */
    public function corsiDi(?array $scheda): array
    {
        $codici = array_filter(array_map('trim', explode(',', (string) ($scheda['corsi_codici'] ?? ''))), static fn (string $c): bool => $c !== '');
        if (!$codici && !empty($scheda['corso_codice'])) {
            $codici = [(string) $scheda['corso_codice']];
        }
        $out = [];
        foreach ($codici as $c) {
            if ($corso = $this->corso($c)) {
                $out[] = $corso;
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function corso(?string $codice): ?array
    {
        $codice = trim((string) $codice);
        if ($codice === '' || strlen($codice) > 20) {
            return null;
        }
        if (!array_key_exists($codice, $this->perCodice)) {
            $this->perCodice[$codice] = $this->corsi->perCodice($codice);
        }

        return $this->perCodice[$codice];
    }

    /**
     * Corsi salvati prima che l'anagrafe leggesse l'ID del regolamento (serve per il link alla pagina del corso):
     * lo si chiede alle API del portale per il dipartimento del corso, al massimo una volta ogni 6 ore.
     *
     * @param array<string, mixed> $corso
     * @return array<string, mixed>
     */
    public function completaRegdid(array $corso): array
    {
        $dip = (string) preg_replace('/[^0-9A-Za-z]/', '', (string) ($corso['dipartimento_cod'] ?? ''));
        if ($dip === '') {
            return $corso;
        }
        $segno = $this->sito->radice() . '/cache/regdid_' . $dip . '.try';
        if (is_file($segno) && filemtime($segno) > time() - 6 * 3600) {
            return $corso;
        }
        @touch($segno);
        $cds = $this->api->tutte('cds/', ['departmentcod' => $dip]);
        if (!$cds) {
            return $corso;
        }
        usort($cds, static fn (array $a, array $b): int => (int) ($b['AcademicYear'] ?? 0) <=> (int) ($a['AcademicYear'] ?? 0)); // il più recente per primo
        $fatti = [];
        foreach ($cds as $c) {
            $cc = trim((string) ($c['CdSCod'] ?? ''));
            $rd = (int) ($c['RegDidId'] ?? 0);
            if ($cc === '' || !$rd || isset($fatti[$cc])) {
                continue;
            }
            $fatti[$cc] = $rd;
            $this->corsi->completaRegdid($cc, $rd);
        }
        if (isset($fatti[$corso['codice']])) {
            $corso['regdid_id'] = $fatti[$corso['codice']];
        }

        return $corso;
    }

    /** @return array<string, mixed>|null */
    public function insegnamento(?int $id): ?array
    {
        return $id ? $this->insegnamenti->perId($id) : null;
    }

    /**
     * Insegnamenti presenti di un anno accademico, raggruppati per corso di studio.
     * Senza anno: quello in corso; se non ci sono ancora dati, il più recente disponibile.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function insegnamentiPerCorso(?int $anno = null): array
    {
        if ($anno === null) {
            $anno = $this->catalogo->annoCorrente();
            if (!$this->insegnamenti->contaPresenti($anno)) {
                $anno = $this->insegnamenti->annoPiuRecente();
            }
        }
        if (!$anno) {
            return [];
        }
        $out = [];
        foreach ($this->insegnamenti->dellAnno($anno) as $x) {
            $out[$x['cds_nome'] ?: 'Altri insegnamenti'][] = $x;
        }

        return $out;
    }

    /**
     * Insegnamenti del Dipartimento per le decisioni in seduta (convalide, piano di studi): id => dati con corso e CFU.
     *
     * @return array<int, array{id: int, nome: string, corso: string, cfu: float|null, ssd: string, anno: int}>
     */
    public function insegnamentiDipartimentoScelta(): array
    {
        if ($this->insegnamentiDipartimento !== null) {
            return $this->insegnamentiDipartimento;
        }
        $out = [];
        foreach ($this->insegnamentiPerCorso() as $corso => $ins) {
            foreach ($ins as $i) {
                $out[(int) $i['id']] = ['id' => (int) $i['id'], 'nome' => $i['nome'] . ($i['partizione'] !== '' ? ' (' . $i['partizione'] . ')' : ''), 'corso' => $corso,
                    'cfu' => $i['cfu'] !== null ? (float) $i['cfu'] : null, 'ssd' => (string) $i['ssd_cod'], 'anno' => (int) $i['anno_corso']];
            }
        }

        return $this->insegnamentiDipartimento = $out;
    }
}
