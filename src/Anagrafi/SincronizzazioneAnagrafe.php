<?php

declare(strict_types=1);

namespace App\Anagrafi;

use App\Core\Database;
use App\Core\Sito;

/**
 * Sincronizzazione con le API pubbliche del portale di Ateneo: personale (rubrica e docenti del dipartimento per settore),
 * corsi di studio e insegnamenti delle strutture scelte in "Anagrafe personale". Chi non compare più resta segnato come
 * «non più in Ateneo» (per gli avvisi) e viene cancellato dopo 12 mesi. Spostata da sincronizza_anagrafe() e
 * sincronizza_insegnamenti() di inc/anagrafi.php, dove restano le facciate.
 */
final class SincronizzazioneAnagrafe
{
    public function __construct(
        private Database $db,
        private ClientApiAteneo $api,
        private StruttureRepository $strutture,
        private PersonaRepository $persone,
        private CorsoRepository $corsi,
        private InsegnamentoRepository $insegnamenti,
        private ServizioCatalogo $catalogo,
        private CollegamentoUtente $collegamento,
        private Sito $sito,
    ) {
    }

    /**
     * Scarica personale e corsi di studio delle strutture scelte (o di una sola). Ritorna un riepilogo per struttura.
     *
     * @return array<string, array{ok: bool, esito: string}>
     */
    public function sincronizza(?string $soloStruttura = null): array
    {
        @set_time_limit(900);
        // Inizio dell'aggiornamento (ora del database): chi non viene aggiornato da qui in poi non compare più
        $inizio = $this->strutture->adesso() ?? date('Y-m-d H:i:s');
        $riepilogo = [];
        $tutteOk = true;

        foreach ($this->strutture->codici($soloStruttura) as $cod) {
            $persone = $this->api->tutte('addressbook/', ['structuretree' => $cod]);
            $docenti = $this->api->tutte('teachers/', ['department' => $cod]);
            $corsi = $this->api->tutte('cds/', ['departmentcod' => $cod]);
            if ($persone === null || $docenti === null) {
                $tutteOk = false;
                $esito = 'API non raggiungibili: dati precedenti conservati';
                $this->strutture->segnaErrore($cod, $esito);
                $riepilogo[$cod] = ['ok' => false, 'esito' => $esito];
                continue;
            }
            // Docenti del dipartimento: settore disciplinare e ruolo, anche per chi non è nella rubrica della struttura
            $doc = [];
            foreach ($docenti as $d) {
                if (!empty($d['TeacherID'])) {
                    $doc[$d['TeacherID']] = $d;
                }
            }
            $righe = $this->righePersone($persone, $doc);
            $this->db->transazione(function () use ($righe, $doc, $cod): void {
                foreach ($righe as $id => $r) {
                    $id = (string) $id;
                    [$cognome, $nome] = Testi::separaCognomeNome($r['nominativo'], $id);
                    $email = '';
                    foreach ($r['email'] as $e) {
                        $e = strtolower(trim((string) $e));
                        if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
                            $email = $e;
                            break;
                        }
                    }
                    $d = $doc[$id] ?? null;
                    $docente = $d ? 1 : 0;
                    $ssdCod = $d && !preg_match('/^0+$/', (string) ($d['TeacherSSDCod'] ?? '')) ? (string) $d['TeacherSSDCod'] : '';
                    $this->persone->salvaDaSincronizzazione([
                        'id' => $id, 'cognome' => $cognome, 'nome' => $nome, 'email' => $email,
                        'telefono' => mb_substr(trim((string) ($r['tel'][0] ?? '')), 0, 60),
                        'ufficio' => mb_substr($r['ufficio'], 0, 255), 'ruolo_cod' => $r['ruolo_cod'], 'ruolo' => mb_substr($r['ruolo'], 0, 150),
                        'struttura_cod' => $r['struttura_cod'], 'struttura' => mb_substr($r['struttura'], 0, 255),
                        'gruppo' => Testi::gruppoPersonale($r['ruolo_cod'], (bool) $docente), 'docente' => $docente,
                        'ssd_cod' => $ssdCod, 'ssd' => $ssdCod !== '' ? (string) ($d['TeacherSSDDescription'] ?? '') : '', 'origine' => $cod,
                    ]);
                }
            });

            $nCorsi = $corsi !== null ? $this->salvaCorsi($cod, $corsi) : 0;

            $n = count($righe);
            $nomeS = '';
            foreach ($persone as $p) {
                foreach ((array) ($p['Roles'] ?? []) as $ro) {
                    if (($ro['StructureCod'] ?? '') === $cod) {
                        $nomeS = trim((string) $ro['Structure']);
                        break 2;
                    }
                }
            }
            if ($nomeS === '') {
                foreach ($docenti as $d) {
                    $nomeS = trim((string) ($d['TeacherDepartmentName'] ?? ''));
                    break;
                }
            }
            $esito = "$n persone" . ($corsi !== null ? ", $nCorsi corsi di studio" : ', corsi non disponibili');
            $this->strutture->segnaAggiornata($cod, $n, $nCorsi, $esito, $nomeS);
            $riepilogo[$cod] = ['ok' => true, 'esito' => $esito];
        }

        // Corsi di studio di tutto l'Ateneo per i moduli della Didattica (catalogo: gli insegnamenti si scaricano quando servono)
        if ($soloStruttura === null) {
            $this->catalogo->sincronizzaCorsi();
        }

        // Insegnamenti di tutto l'Ateneo (a capo della prima struttura dell'anagrafe, il proprio dipartimento), poi i CFU dal dettaglio
        $prima = $this->strutture->prima();
        if ($prima !== '' && ($soloStruttura === null || $soloStruttura === $prima) && isset($riepilogo[$prima]) && $riepilogo[$prima]['ok']) {
            $nIns = $this->sincronizzaInsegnamenti();
            $riepilogo[$prima]['esito'] .= $nIns !== null ? ", $nIns insegnamenti di Ateneo" : ', insegnamenti non disponibili';
            if ($nIns !== null) {
                $nDet = $this->catalogo->completaDettagli(150);
                $riepilogo[$prima]['esito'] .= $nDet > 0 ? " ($nDet schede con i CFU)" : '';
            }
        }

        // Chi non compare più in nessuna struttura (solo se le API hanno risposto per tutte)
        if ($tutteOk && $soloStruttura === null) {
            $this->persone->segnaUsciti($inizio);
            $this->collegamento->scollegaSenzaPersona();
        }
        // Data dell'ultimo aggiornamento per il cron settimanale: se le API non hanno risposto si riprova tra un giorno
        $fSync = $this->sito->radice() . '/cache/anagrafe_sync.txt';
        if (@file_put_contents($fSync, date('c')) !== false && !$tutteOk) {
            @touch($fSync, time() - 6 * 86400);
        }

        return $riepilogo;
    }

    /**
     * Insegnamenti di tutto l'Ateneo (o di un solo dipartimento) tenuti dall'anno accademico 2026/2027 (o in corso, se precedente)
     * fino al successivo a quello in corso. Nelle API "academic_year" è la coorte (anno di immatricolazione) e ogni coorte elenca
     * tutto il suo percorso: l'insegnamento si tiene nell'anno coorte + anno di corso - 1. Si scaricano le coorti degli ultimi
     * 6 anni; l'intero percorso di ogni coorte va anche nel catalogo dei moduli. I CFU (solo nel dettaglio) si completano a parte
     * (ServizioCatalogo::completaDettagli). Ritorna quanti ne sono arrivati, null se le API non rispondono.
     */
    public function sincronizzaInsegnamenti(?string $dip = null): ?int
    {
        $aa = $this->catalogo->annoCorrente();
        $dal = min($aa, InsegnamentoRepository::PRIMO_ANNO_ARCHIVIO);
        $elenchi = [];
        for ($coorte = $aa - 5; $coorte <= $aa + 1; $coorte++) {
            $el = $this->api->tutte('activities/', ($dip !== null ? ['department' => $dip] : []) + ['academic_year' => $coorte]);
            if ($el === null) {
                return null;
            }
            $elenchi[$coorte] = $el;
        }
        $visti = [];
        $integrati = [];
        foreach ($elenchi as $coorte => $el) {
            $perCorso = [];
            foreach ($el as $r) {
                $x = AttivitaAteneo::daElenco($r);
                if ($x === null) {
                    continue;
                }
                if ($x['cds_cod'] !== '') {
                    $perCorso[$x['cds_cod']][] = $r;
                }
                $erog = $coorte + max(1, (int) ($r['StudyActivityYear'] ?? 1)) - 1;
                if ($erog < $dal || $erog > $aa + 1) {
                    continue;
                }
                $x = $x + ['anno_accademico' => $erog, 'coorte' => $coorte];
                $x['dipartimento_cod'] = $x['dipartimento_cod'] !== '' ? $x['dipartimento_cod'] : (string) $dip;
                $this->insegnamenti->salva($x);
                $visti[] = $x['id'];
                if ($x['padre_id'] !== null) {
                    // Integrato di cui è modulo: una riga nell'archivio con i dati dei suoi moduli
                    $g = $integrati[$x['padre_id']] ?? array_merge($x, ['id' => $x['padre_id'], 'nome' => $x['padre_nome'] !== '' ? $x['padre_nome'] : 'Insegnamento integrato', 'ssd_cod' => '', 'ssd' => '', 'semestre' => '']);
                    $g['ssd_cod'] = implode(', ', array_values(array_unique(array_filter(array_merge(explode(', ', $g['ssd_cod']), [$x['ssd_cod']])))));
                    $g['ssd'] = implode(', ', array_values(array_unique(array_filter(array_merge(explode(', ', $g['ssd']), [$x['ssd']])))));
                    $g['semestre'] = implode(' / ', array_values(array_unique(array_filter(array_merge(explode(' / ', $g['semestre']), [$x['semestre']])))));
                    $integrati[$x['padre_id']] = $g;
                }
            }
            // Catalogo dei moduli: tutto il percorso della coorte, per corso, senza che gli studenti debbano aspettare le API
            if ($dip === null) {
                foreach ($perCorso as $cds => $righe) {
                    $this->catalogo->salvaInsegnamenti((string) $cds, $coorte, $righe);
                }
            }
        }
        foreach ($integrati as $g) {
            $this->insegnamenti->salvaIntegrato(array_merge($g, ['ssd_cod' => mb_substr($g['ssd_cod'], 0, 20), 'ssd' => mb_substr($g['ssd'], 0, 150), 'semestre' => mb_substr($g['semestre'], 0, 60)]));
            $visti[] = (int) $g['id'];
        }
        $this->insegnamenti->segnaNonPresentiEPulisci($dip, $aa, $visti);
        $this->insegnamenti->ricalcolaCfuIntegrati();

        return count($visti);
    }

    /**
     * Persone da salvare: prima la rubrica della struttura, poi i docenti del dipartimento che nella rubrica non ci sono.
     *
     * @param list<array<string, mixed>> $persone
     * @param array<string, array<string, mixed>> $doc
     * @return array<string, array<string, mixed>>
     */
    private function righePersone(array $persone, array $doc): array
    {
        $righe = [];
        foreach ($persone as $p) {
            $id = trim((string) ($p['ID'] ?? ''));
            if ($id === '' || strlen($id) > 80) {
                continue;
            }
            $ruoli = (array) ($p['Roles'] ?? []);
            usort($ruoli, static fn (array $a, array $b): int => (int) ($b['Priority'] ?? 0) <=> (int) ($a['Priority'] ?? 0));
            $r0 = $ruoli[0] ?? [];
            $righe[$id] = ['nominativo' => (string) ($p['Name'] ?? ''), 'email' => (array) ($p['Email'] ?? []), 'tel' => (array) ($p['TelOffice'] ?? []),
                'ufficio' => implode(', ', (array) ($p['OfficeReference'] ?? [])), 'ruolo_cod' => (string) ($r0['Role'] ?? ''),
                'ruolo' => (string) ($r0['RoleDescription'] ?? ''), 'struttura_cod' => (string) ($r0['StructureCod'] ?? ''),
                'struttura' => trim((string) ($r0['Structure'] ?? '')), 'origine' => 'rubrica'];
        }
        foreach ($doc as $id => $d) {
            $id = (string) $id;
            if (isset($righe[$id]) || strlen($id) > 80) {
                continue;
            }
            $righe[$id] = ['nominativo' => (string) ($d['TeacherName'] ?? ''), 'email' => (array) ($d['Email'] ?? []), 'tel' => [], 'ufficio' => '',
                'ruolo_cod' => (string) ($d['TeacherRole'] ?? ''), 'ruolo' => (string) ($d['TeacherRoleDescription'] ?? ''),
                'struttura_cod' => (string) ($d['TeacherDepartmentCod'] ?? ''), 'struttura' => trim((string) ($d['TeacherDepartmentName'] ?? '')), 'origine' => 'docenti'];
        }

        return $righe;
    }

    /**
     * Corsi di studio: i più recenti visibili; quelli vecchi (anno di 3+ anni prima del più recente) o doppioni di un corso più nuovo nascosti.
     *
     * @param list<array<string, mixed>> $corsi
     */
    private function salvaCorsi(string $cod, array $corsi): int
    {
        usort($corsi, static fn (array $a, array $b): int => (int) ($b['AcademicYear'] ?? 0) <=> (int) ($a['AcademicYear'] ?? 0));
        $annoMax = (int) ($corsi[0]['AcademicYear'] ?? 0);
        // Solo i corsi della prima struttura (il proprio dipartimento) sono proposti subito; quelli delle strutture
        // aggiunte dopo arrivano nascosti e si attivano da "Corsi di studio"
        $propri = $this->strutture->prima() === $cod;
        $gia = [];
        $codici = [];
        $n = 0;
        foreach ($corsi as $c) {
            $cc = trim((string) ($c['CdSCod'] ?? ''));
            if ($cc === '' || strlen($cc) > 20) {
                continue;
            }
            $nomeC = mb_substr(Testi::maiuscoleCorso((string) ($c['CdSName'] ?? '')), 0, 255);
            $tipo = (string) ($c['CourseType'] ?? '');
            $anno = (int) ($c['AcademicYear'] ?? 0) ?: null;
            $chiave = mb_strtolower($nomeC) . '|' . $tipo;
            $vis = ($propri && !isset($gia[$chiave]) && ($anno ?? 0) >= $annoMax - 2) ? 1 : 0;
            $gia[$chiave] = true;
            $this->corsi->salvaDaSincronizzazione([
                'codice' => $cc, 'nome' => $nomeC, 'tipo' => $tipo, 'tipo_descrizione' => (string) ($c['CourseTypeDescription'] ?? ''),
                'classe' => mb_substr(trim((string) ($c['CourseClassName'] ?? '')), 0, 255), 'anno' => $anno,
                'lingua' => mb_substr(implode(', ', (array) ($c['CdSLanguage'] ?? [])), 0, 100), 'durata' => (int) ($c['CdSDuration'] ?? 0) ?: null,
                'dipartimento_cod' => $cod, 'visibile' => $vis, 'regdid_id' => (int) ($c['RegDidId'] ?? 0) ?: null,
            ]);
            $codici[] = $cc;
            $n++;
        }
        $this->corsi->segnaNonPresenti($cod, $codici);

        return $n;
    }
}
