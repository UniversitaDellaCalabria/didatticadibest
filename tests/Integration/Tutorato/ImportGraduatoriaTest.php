<?php

declare(strict_types=1);

namespace Tests\Integration\Tutorato;

use App\Core\Schema\Definizioni;
use App\Tutorato\AbbinamentoInsegnamenti;
use App\Tutorato\ImportGraduatoria;
use App\Tutorato\ServizioImportBando;
use ZipArchive;

/** Import dei vincitori dal Word del decreto di approvazione atti: lettura della tabella, abbinamento all'anagrafe, docente titolare e lettere in bozza. */
final class ImportGraduatoriaTest extends TutoratoBase
{
    protected function tabelle(): array
    {
        $t = Definizioni::tabelle();

        return array_merge(parent::tabelle(), ['personale_ateneo' => $t['personale_ateneo'], 'personale_modifiche' => $t['personale_modifiche'], 'insegnamenti' => $t['insegnamenti']]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->esegui('DELETE FROM personale_ateneo');
        $this->db->esegui("INSERT INTO personale_ateneo (id, cognome, nome, email, ruolo, struttura, gruppo, docente, attivo) VALUES
            ('m.rossi', 'Rossi', 'Mario', 'MARIO.ROSSI@unical.it', 'Professore', 'DiBEST', 'docenti', 1, 1),
            ('a.verdi', 'Verdi', 'Anna', 'anna.verdi@unical.it', 'Professoressa', 'DiBEST', 'docenti', 1, 1),
            ('l.bianchi', 'Bianchi', 'Luca', 'luca.bianchi@unical.it', 'Professore', 'DiBEST', 'docenti', 1, 1),
            ('l.bianchi2', 'Bianchi', 'Luca', 'luca.bianchi2@unical.it', 'Ricercatore', 'DiBEST', 'docenti', 1, 1)");
        $this->db->esegui("INSERT INTO insegnamenti (id, codice, nome, cds_cod, cds_nome, anno_corso, anno_accademico, docente, docente_id, partizione, presente) VALUES
            (1, '270006', 'Chimica generale e inorganica', 'S01', 'Scienze e tecnologie biologiche', 1, 2025, 'ROSSI MARIO', 'x1', '', 1),
            (2, '270007', 'Chimica generale e inorganica', 'S02', 'Scienze geologiche', 1, 2025, 'VERDI ANNA', 'x2', '', 1),
            (3, '270010', 'Chimica organica', 'S01', 'Scienze e tecnologie biologiche', 2, 2025, 'BIANCHI LUCA', 'x3', '', 1),
            (4, '270020', 'Zoologia', 'S01', 'Scienze e tecnologie biologiche', 2, 2025, 'SCONOSCIUTO PAOLO', 'x4', '', 1),
            (5, '270030', 'Matematica', 'S01', 'Scienze e tecnologie biologiche', 1, 2025, 'ROSSI MARIO', 'x5', 'Modulo A', 1),
            (6, '260001', 'Chimica organica', 'S01', 'Scienze e tecnologie biologiche', 2, 2024, 'ROSSI MARIO', 'x6', '', 1)");
        $this->bando();
        $this->db->esegui("UPDATE tutorato_bandi SET anno_accademico = '2025/2026' WHERE id = 1");
    }

    /** Un Word minimo con le stesse parti del decreto: testo con i decreti, ART. 1 (da ignorare) e ART. 2 con le tabelle dei vincitori. */
    private function docx(): string
    {
        $p = static fn (string $t): string => '<w:p><w:r><w:t xml:space="preserve">' . htmlspecialchars($t, ENT_XML1) . '</w:t></w:r></w:p>';
        $c = static fn (string $t, string $merge = ''): string => '<w:tc>' . ($merge !== '' ? '<w:tcPr><w:vMerge' . ($merge === 'restart' ? ' w:val="restart"' : '') . '/></w:tcPr>' : '') . '<w:p><w:r><w:t>' . htmlspecialchars($t, ENT_XML1) . '</w:t></w:r></w:p></w:tc>';
        $riga = static fn (array $celle): string => '<w:tr>' . implode('', $celle) . '</w:tr>';
        $intest = $riga(array_map($c, ['COGNOME', 'NOME', 'CORSO DI LAUREA', 'INSEGNAMENTO', 'N. ORE', 'PERIODO', "COMPENSO ASSEGNO (AL NETTO DEGLI ONERI A CARICO DELL'ENTE"]));
        $graduatoria = '<w:tbl>' . $riga(array_map($c, ['INSEGNAMENTO', 'COGNOME', 'NOME', 'Punti', 'IDONEITÀ'])) . $riga(array_map($c, ['Chimica', 'Pinco', 'Pallino', '10', 'SI'])) . '</w:tbl>';
        $t1 = '<w:tbl>' . $intest
            . $riga([$c('Rossi'), $c('Giulia'), $c('Scienze e Tecnologie Biologiche'), $c('Chimica Generale ed Inorganica'), $c('30'), $c('Dalla data di accettazione dell’incarico fino al 31/07/2026', 'restart'), $c('604,84')])
            . $riga([$c('Neri'), $c('Marco'), $c('Scienze Geologiche'), $c('Chimica Generale ed Inorganica'), $c('15'), $c('', 'continue'), $c('302,42')])
            . $riga([$c(''), $c(''), $c(''), $c(''), $c(''), $c('', 'continue'), $c('')]) . '</w:tbl>';
        $t2 = '<w:tbl>' . $intest . $riga([$c('Greco'), $c('Sara'), $c('Scienze e Tecnologie Biologiche'), $c('Zoologia'), $c('10'), $c('Fino al 28/02/2027', 'restart'), $c('193,55')]) . '</w:tbl>';
        $corpo = $p('Approvazione atti della selezione di cui al Decreto Direttoriale n. 367 del 29/10/2025, per l’attribuzione di assegni')
            . $p('VISTO il Decreto Direttoriale n. 406 del 19/11/2025 con cui si è provveduto a nominare la Commissione giudicatrice')
            . $p('ART. 1 - Sono approvati gli atti') . $p('N. 2 assegni_ PROFILO “TUTOR DIDATTICO” - AMBITO DISCIPLINARE CHIMICA') . $graduatoria
            . $p('ART. 2 - In conseguenza delle suddette graduatorie, sono dichiarate vincitori e vincitrici')
            . $p('N. 2 assegni_ PROFILO “TUTOR DIDATTICO” - AMBITO DISCIPLINARE CHIMICA') . $t1
            . $p('N. 1 assegni_ PROFILO “TUTOR DIDATTICO” - AMBITO DISCIPLINARE BIOLOGIA/SCIENZE NATURALI') . $t2;
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $corpo . '</w:body></w:document>';
        $tmp = tempnam(sys_get_temp_dir(), 'dx');
        $z = new ZipArchive();
        $z->open($tmp, ZipArchive::OVERWRITE);
        $z->addFromString('word/document.xml', $xml);
        $z->close();
        $b = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $b;
    }

    public function testLeggeSoloLaTabellaDeiVincitoriConPeriodoUnitoEDecreti(): void
    {
        $r = (new ImportGraduatoria())->leggi($this->docx());

        $this->assertNull($r['errore']);
        $this->assertSame(['bando' => '367/2025', 'bando_data' => '2025-10-29', 'commissione' => '406/2025', 'commissione_data' => '2025-11-19'], $r['decreti']);
        $this->assertCount(3, $r['righe'], 'la graduatoria di merito dell\'ART. 1 e la riga vuota non contano');
        $this->assertSame(['CHIMICA', 'Rossi', 'Giulia', 'Scienze e Tecnologie Biologiche', 'Chimica Generale ed Inorganica', 30.0, 604.84, '2026-07-31'],
            [$r['righe'][0]['ambito'], $r['righe'][0]['cognome'], $r['righe'][0]['nome'], $r['righe'][0]['corso'], $r['righe'][0]['insegnamento'], $r['righe'][0]['ore'], $r['righe'][0]['compenso_decreto'], $r['righe'][0]['data_fine']]);
        $this->assertSame($r['righe'][0]['periodo'], $r['righe'][1]['periodo'], 'il periodo scritto una volta vale per tutta la tabella');
        $this->assertSame('2026-07-31', $r['righe'][1]['data_fine']);
        $this->assertSame(['BIOLOGIA/SCIENZE NATURALI', '2027-02-28'], [$r['righe'][2]['ambito'], $r['righe'][2]['data_fine']]);
    }

    public function testFileNonValidi(): void
    {
        $g = new ImportGraduatoria();
        $this->assertStringContainsString('.docx', (string) $g->leggi('')['errore']);
        $this->assertStringContainsString('.docx', (string) $g->leggi('%PDF-1.4')['errore']);
        $this->assertStringContainsString('Non trovo la tabella', (string) $g->leggi($this->docxSenzaTabella())['errore']);
        $this->assertSame(['2026-07-31', null], [ImportGraduatoria::dataFine('fino al 31/07/2026'), ImportGraduatoria::dataFine('senza data')]);
    }

    private function docxSenzaTabella(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dx');
        $z = new ZipArchive();
        $z->open($tmp, ZipArchive::OVERWRITE);
        $z->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>niente</w:t></w:r></w:p></w:body></w:document>');
        $z->close();
        $b = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $b;
    }

    public function testAbbinamentoAdInsegnamentoCodiceCorsoEDocente(): void
    {
        $a = $this->servizio(AbbinamentoInsegnamenti::class);

        $c = $a->candidati(2025, 'Scienze e Tecnologie Biologiche', 'Chimica Generale ed Inorganica');
        $this->assertSame([1], array_slice(array_column($c, 'id'), 0, 1), 'stesso insegnamento e stesso corso: il migliore');
        $this->assertSame(['270006', 'Scienze e tecnologie biologiche', 'ROSSI MARIO'], [$c[0]['codice'], $c[0]['cds_nome'], $c[0]['docente']]);
        $c2 = $a->candidati(2025, 'Scienze Geologiche', 'Chimica Generale ed Inorganica');
        $this->assertSame(2, $c2[0]['id'], 'a parità di nome decide il corso di laurea');
        $this->assertSame(3, $a->candidati(2025, 'Biologia', 'Chimica Organica')[0]['id'], 'solo gli insegnamenti di quell\'anno (il 2024 non compare)');
        $this->assertSame([], $a->candidati(2025, 'Biologia', 'Astrofisica delle galassie'));
        $this->assertSame([], $a->candidati(2025, 'x', '   '));
        $this->assertSame('Matematica (Modulo A)', $a->candidati(2025, 'Scienze e Tecnologie Biologiche', 'Matematica')[0]['nome'], 'con la partizione');
        $this->assertSame(2025, AbbinamentoInsegnamenti::annoDa('2025/2026'));
        $this->assertSame(0, AbbinamentoInsegnamenti::annoDa('boh'));
        $this->assertNotEmpty($a->candidati(2030, 'Scienze e Tecnologie Biologiche', 'Zoologia'), 'anno senza dati: si usa il più recente');

        $this->assertSame(['persona_id' => 'm.rossi', 'nome' => 'Mario', 'cognome' => 'Rossi', 'email' => 'mario.rossi@unical.it'], $a->docente('ROSSI MARIO'));
        $this->assertSame('a.verdi', $a->docente('Anna Verdi')['persona_id'], 'il nome in qualunque ordine');
        $this->assertNull($a->docente('BIANCHI LUCA'), 'due omonimi: non si sceglie da soli');
        $this->assertNull($a->docente('SCONOSCIUTO PAOLO'));
        $this->assertNull($a->docente(''));
    }

    public function testAnteprimaConCandidatiDocenteECompensoDiverso(): void
    {
        $r = $this->servizio(ServizioImportBando::class)->anteprima(1, $this->docx());

        $this->assertNull($r['errore']);
        $this->assertCount(3, $r['righe']);
        [$a, $b, $c] = $r['righe'];
        $this->assertSame(1, $a['insegnamento_id']);
        $this->assertSame('m.rossi', $a['docente']['persona_id'], 'docente titolare proposto dall\'anagrafe');
        $this->assertSame(2, $b['insegnamento_id']);
        $this->assertSame('a.verdi', $b['docente']['persona_id']);
        $this->assertFalse($a['compenso_diverso']);
        $this->assertSame(604.84, $a['compenso_calcolato']);
        $this->assertSame(4, $c['insegnamento_id']);
        $this->assertNull($c['docente'], 'docente non presente in anagrafe: lo sceglie il referente');
        $this->assertSame('SCONOSCIUTO PAOLO', $c['docente_testo']);
        $this->assertTrue($c['compenso_diverso'], 'il decreto scrive 193,55 ma 10 ore × 25 € ÷ 1,24 sono 201,61');
        $this->assertSame(201.61, $c['compenso_calcolato']);
        $this->assertSame('Bando non trovato.', $this->servizio(ServizioImportBando::class)->anteprima(99, $this->docx())['errore']);
    }

    public function testImportaCreaLeLettereInBozzaEPoiSaltaIDoppioni(): void
    {
        $sv = $this->servizio(ServizioImportBando::class);
        $righe = $sv->anteprima(1, $this->docx())['righe'];
        $post = [];
        foreach ($righe as $n => $r) {
            $post[$n] = ['includi' => '1', 'cognome' => $r['cognome'], 'nome' => $r['nome'], 'genere' => $n === 0 ? 'F' : 'M', 'codice_fiscale' => $n === 0 ? 'rssglu00a41d086x' : '', 'email' => $n === 0 ? 'Giulia@x.it' : 'non-email',
                'ambito' => $r['ambito'], 'corso' => $r['corso'], 'insegnamento' => $r['insegnamento'], 'insegnamento_id' => $r['insegnamento_id'], 'ore' => (string) $r['ore'], 'periodo' => $r['periodo'],
                'data_fine' => $r['data_fine'], 'compenso' => $n === 2 ? '193,55' : '', 'docente_persona_id' => $r['docente']['persona_id'] ?? '', 'docente_nome' => $r['docente']['nome'] ?? 'Paolo',
                'docente_cognome' => $r['docente']['cognome'] ?? 'Sconosciuto', 'docente_email' => $r['docente']['email'] ?? 'paolo@x.it'];
        }
        $post[1]['includi'] = '';

        $e = $sv->importa(1, $post);

        $this->assertSame([2, 0, []], [$e['creati'], $e['saltati'], $e['errori']]);
        $a = $this->lettera($e['ids'][0]);
        $this->assertSame(['bozza', 'Rossi', 'Giulia', 'F', 'RSSGLU00A41D086X', 'giulia@x.it'], [$a['stato'], $a['cognome'], $a['nome'], $a['genere'], $a['codice_fiscale'], $a['email']]);
        $this->assertSame(['30.0', '604.84', 'Dalla data di accettazione dell’incarico fino al 31/07/2026'], [$a['ore'], $a['compenso'], $a['periodo']], 'compenso del decreto o, se manca, ore × 25 ÷ 1,24');
        $this->assertSame([1, '270006', 'Chimica generale e inorganica', 'Scienze e tecnologie biologiche', 'CHIMICA', '2026-07-31'],
            [(int) $a['insegnamento_id'], $a['insegnamento_codice'], $a['insegnamento_docente'], $a['corso_laurea'], $a['ambito'], $a['data_fine']]);
        $this->assertStringContainsString('Chimica generale e inorganica (cod. 270006) – Corso di laurea in Scienze e tecnologie biologiche', (string) $a['attivita']);
        $this->assertSame(['m.rossi', 'Mario', 'Rossi', 'mario.rossi@unical.it'], [$a['docente_persona_id'], $a['docente_nome'], $a['docente_cognome'], $a['docente_email']]);
        $b = $this->lettera($e['ids'][1]);
        $this->assertSame(['', '193.55', ''], [$b['email'], $b['compenso'], $b['codice_fiscale']], 'email non valida: vuota, da completare; compenso scelto dal referente');
        $this->assertCount(2, $this->servizio(\App\Tutorato\ServizioIncarichi::class)->delBando(1));
        $this->assertNotEmpty($this->servizio(\App\Tutorato\ServizioIncarichi::class)->eventi($e['ids'][0]));

        $e2 = $sv->importa(1, $post);
        $this->assertSame([0, 2], [$e2['creati'], $e2['saltati']], 'importare due volte non duplica le lettere');
        $err = $sv->importa(1, [0 => ['includi' => '1', 'ore' => '', 'insegnamento' => 'X'], 1 => ['includi' => '1', 'ore' => '5', 'insegnamento' => '']]);
        $this->assertSame([0, ['Riga 1: indica le ore.', 'Riga 2: manca l\'insegnamento.']], [$err['creati'], $err['errori']]);
        $this->assertSame('Bando non trovato.', $sv->importa(99, $post)['errori'][0]);
    }

    public function testUnaLetteraImportataSiCompletaEPoiSiInvia(): void
    {
        $sv = $this->servizio(ServizioImportBando::class);
        $r = $sv->anteprima(1, $this->docx())['righe'][0];
        $e = $sv->importa(1, [0 => ['includi' => '1', 'cognome' => 'Rossi', 'nome' => 'Giulia', 'insegnamento_id' => $r['insegnamento_id'], 'ore' => '30', 'periodo' => $r['periodo'], 'ambito' => 'CHIMICA',
            'docente_persona_id' => 'm.rossi', 'docente_nome' => 'Mario', 'docente_cognome' => 'Rossi', 'docente_email' => 'mario.rossi@unical.it']]);
        $id = $e['ids'][0];
        $s = $this->servizio(\App\Tutorato\ServizioIncarichi::class);

        $this->assertStringContainsString('codice fiscale', (string) $s->invia($id), 'una bozza senza codice fiscale non parte');
        $l = $this->lettera($id);
        [, $err] = $s->salva(array_merge($l, ['id' => $id, 'codice_fiscale' => 'RSSGLU00A41D086X', 'email' => 'giulia@x.it', 'data_nascita' => '2000-01-01', 'luogo_nascita' => 'Cosenza', 'comune_residenza' => 'Rende', 'indirizzo' => 'Via Roma', 'civico' => '1',
            'compenso' => '604,84', 'ore' => '30']));
        $this->assertNull($err);
        $this->assertSame('Chimica generale e inorganica', $this->lettera($id)['insegnamento_docente'], 'i dati dell\'import restano dopo la modifica');
    }
}
