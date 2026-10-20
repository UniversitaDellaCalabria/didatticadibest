<?php
// Prove dei tirocini curriculari: aziende dal report del TSP (solo la «x»), indirizzi a mano o in file, pubblicazione solo se completa e confermata,
// laboratori interni dalle API di Ateneo (simulate). Chiamato da esegui.php (variabili $conn, $q e funzioni prova, sezione).
sezione("Tirocini: aziende convenzionate (TSP) e laboratori interni (API di Ateneo)");

use App\Core\App as AppTi;
use App\Tirocini\LettoreFoglio;
use App\Tirocini\Province;
use App\Tirocini\ServizioAziende as AziendeTi;

$q("DELETE FROM tirocini_aziende WHERE cod_tsp LIKE 'T9%'");
$q("DELETE FROM tirocini_laboratori WHERE id >= 990000");
$az_ti = AppTi::per($conn)->get(AziendeTi::class);

// Province e regioni
prova(Province::sigla('cs') === 'CS' && Province::sigla('Cosenza') === 'CS' && Province::sigla('Xx') === '' && Province::regione('CS') === 'Calabria' && Province::regione('MI') === 'Lombardia' && count(Province::tutte()) >= 107, "province: sigla o nome → sigla, regione ricavata dalla provincia");

// Report del TSP: il file reale ha le stringhe nella cella (inlineStr) e le colonne del report
$report = [
    ['codice azienda' => 'T9001', 'descrizione' => '3B Srl', 'codice fiscale' => '', 'partita iva' => '03794710784', 'esiste convenzione attiva' => 'x', 'settore ateco' => 'INGEGNERIA CIVILE'],
    ['codice azienda' => 'T9002', 'descrizione' => 'ASD Rugby Rende', 'codice fiscale' => '', 'partita iva' => '01522420783', 'esiste convenzione attiva' => 'X', 'settore ateco' => ''],
    ['codice azienda' => 'T9003', 'descrizione' => 'Antonio Giulio Cosentino', 'codice fiscale' => '', 'partita iva' => '02319970790', 'esiste convenzione attiva' => 'x', 'settore ateco' => ''],
    ['codice azienda' => 'T9004', 'descrizione' => 'Senza convenzione Srl', 'codice fiscale' => '', 'partita iva' => '1', 'esiste convenzione attiva' => '', 'settore ateco' => ''],
    ['codice azienda' => '', 'descrizione' => 'Senza codice', 'esiste convenzione attiva' => 'x'],
];
$r1 = $az_ti->importa($report);
prova($r1['nuove'] === 3 && $r1['scartate'] === 1 && $r1['aggiornate'] === 0, "importazione: entrano solo le aziende con la «x» (3), la riga senza codice è scartata");
prova($db_ti = (int)$conn->query("SELECT COUNT(*) c FROM tirocini_aziende WHERE cod_tsp = 'T9004'")->fetch_assoc()['c'] === 0, "chi non ha la «x» non entra");
$a1 = $conn->query("SELECT * FROM tirocini_aziende WHERE cod_tsp = 'T9001'")->fetch_assoc();
prova($a1['settore'] === 'Ingegneria civile', "il settore scritto in maiuscolo si legge in minuscolo con l'iniziale");
$a3 = $conn->query("SELECT * FROM tirocini_aziende WHERE cod_tsp = 'T9003'")->fetch_assoc();
prova((int)$a1['pubblica'] === 1 && (int)$a3['pubblica'] === 0 && AziendeTi::sembraPersona('Mario Rossi', '') && !AziendeTi::sembraPersona('Rossi Costruzioni Srl', '') && AziendeTi::sembraPersona('Ditta X', 'RSSMRA80A01H501U'), "ditte individuali e professionisti partono non pubblici");
$r1b = $az_ti->importa($report);
prova($r1b['nuove'] === 0 && $r1b['aggiornate'] === 3, "ricaricando lo stesso file non si duplica nulla");

// Pubblicazione: serve il comune
$nomi_pub = fn() => array_column($az_ti->pubbliche(), 'nome');
prova(!in_array('3B Srl', $nomi_pub(), true), "senza indirizzo non si pubblica");
$az_ti->salvaScheda((int)$a1['id'], 'Via Roma 1', '87100', 'Cosenza', 'CS', 'www.3bgroup.it', true, 'Ingegneria civile');
$p = array_values(array_filter($az_ti->pubbliche(), fn($x) => $x['nome'] === '3B Srl'))[0] ?? null;
prova($p && $p['provincia'] === 'CS' && $p['regione'] === 'Calabria' && $p['sito'] === 'https://www.3bgroup.it', "con l'indirizzo si pubblica; regione dalla provincia, sito con https");
prova($az_ti->salvaScheda((int)$a1['id'], 'Via Roma 1', '87100', 'Cosenza', 'ZZ', '', true, '') !== null, "provincia non valida: rifiutata");

// Indirizzo trovato sul web: si pubblica solo dopo la conferma
$a2 = $conn->query("SELECT * FROM tirocini_aziende WHERE cod_tsp = 'T9002'")->fetch_assoc();
$az_ti->salvaIndirizzo((int)$a2['id'], 'Via dello Sport 3', '87036', 'Rende', 'CS', AziendeTi::ORIGINE_WEB, false);
prova(!in_array('ASD Rugby Rende', $nomi_pub(), true) && $az_ti->conteggi()['da_confermare'] >= 1, "indirizzo trovato sul web: non si pubblica finché non è confermato");
$az_ti->confermaTrovati((int)$a2['id']);
prova(in_array('ASD Rugby Rende', $nomi_pub(), true), "dopo la conferma si pubblica");
$az_ti->importa([['codice azienda' => 'T9002', 'esiste convenzione attiva' => '']]);
prova(!in_array('ASD Rugby Rende', $nomi_pub(), true) && (string)$conn->query("SELECT comune FROM tirocini_aziende WHERE cod_tsp = 'T9002'")->fetch_assoc()['comune'] === 'Rende', "persa la convenzione: esce dall'elenco ma conserva l'indirizzo");

// File con gli indirizzi (secondo caricamento): stessa colonna «Codice azienda»
$r2 = $az_ti->importa([['codice azienda' => 'T9003', 'indirizzo' => 'Via Alimena 5', 'cap' => '87100', 'comune' => 'Cosenza', 'provincia' => 'Cosenza']]);
$a3b = $conn->query("SELECT * FROM tirocini_aziende WHERE cod_tsp = 'T9003'")->fetch_assoc();
prova($r2['indirizzi'] === 1 && $a3b['provincia'] === 'CS' && $a3b['regione'] === 'Calabria' && (int)$a3b['pubblica'] === 0 && !in_array('Antonio Giulio Cosentino', $nomi_pub(), true), "file con gli indirizzi: salvati, ma la ditta individuale resta non pubblica");

// Sito web nel file: si scrive dove manca e non sostituisce uno già presente; non tocca l'indirizzo
$r3 = $az_ti->importa([['codice azienda' => 'T9001', 'sito web' => 'www.altro-sito.it'], ['codice azienda' => 'T9002', 'sito web' => 'https://rugbyrende.example.it', 'comune' => 'Rende', 'provincia' => 'CS', 'origine' => 'web']]);
$s1 = $conn->query("SELECT sito, indirizzo FROM tirocini_aziende WHERE cod_tsp = 'T9001'")->fetch_assoc();
$s2 = $conn->query("SELECT sito FROM tirocini_aziende WHERE cod_tsp = 'T9002'")->fetch_assoc();
prova($r3['siti'] === 1 && $s1['sito'] === 'https://www.3bgroup.it' && $s1['indirizzo'] === 'Via Roma 1' && $s2['sito'] === 'https://rugbyrende.example.it', "sito web nel file: scritto dove manca, uno già presente non si sostituisce, l'indirizzo non si tocca");

// Lettura di un vero .xlsx con le stringhe nella cella (come il report del TSP) e di un CSV
if (class_exists(ZipArchive::class)) {
    $x = \App\Infrastructure\Documenti\Excel::crea(['Foglio' => ['intestazioni' => ['Codice azienda', 'Descrizione', 'Esiste convenzione attiva'], 'righe' => [['T9005', 'Prova Excel Srl', 'x'], ['T9006', 'Altra', '']]]]);
    $righe_x = LettoreFoglio::daPercorso($x, 'a.xlsx');
    @unlink($x);
    prova(is_array($righe_x) && count($righe_x) === 2 && $righe_x[0]['codice azienda'] === 'T9005' && $righe_x[0]['esiste convenzione attiva'] === 'x', "lettura del file .xlsx: intestazioni e righe");
}
$csv = tempnam(sys_get_temp_dir(), 'ti');
file_put_contents($csv, "Codice azienda;Descrizione;Indirizzo\nT9007;Caff\xE8 Srl;Via X 1\n");
$righe_c = LettoreFoglio::daPercorso($csv, 'a.csv');
@unlink($csv);
prova(is_array($righe_c) && $righe_c[0]['descrizione'] === 'Caffè Srl' && $righe_c[0]['indirizzo'] === 'Via X 1', "lettura del CSV (separatore ; e accenti)");
prova(is_string(LettoreFoglio::righe(['error' => UPLOAD_ERR_NO_FILE])), "senza file: messaggio d'errore");

// Laboratori: API simulata
$finto = new class implements \App\Anagrafi\ClientApiAteneo {
    public array $labs = [];
    public function get(string $percorso, array $query = [], int $timeout = 25): ?array { return null; }
    public function tutte(string $percorso, array $query = []): ?array { return $this->labs; }
    public function scarica(string $url, int $timeout = 10): ?string { return null; }
};
$finto->labs = [
    ['LaboratoryId' => 990001, 'LaboratoryName' => 'Laboratorio di Prova', 'LaboratoryAcronym' => 'None', 'Area' => 'Scientifico', 'DepartmentName' => 'Dipartimento di Prova,  DIBEST', 'Interdepartmental' => 'NO',
     'ScientificDirector' => 'BRUNO Leonardo', 'LaboratoryScopes' => [['ScopeDescription' => 'Ricerca'], ['ScopeDescription' => 'Didattica']], 'LaboratoryTeachingScope' => 'None',
     'LaboratoryResearchScope' => '<p>Analisi&nbsp;di <b>campioni</b></p><p>ambientali</p>', 'Visible' => true, 'Dimension' => '120'],
    ['LaboratoryId' => 990002, 'LaboratoryName' => 'Altro', 'DepartmentName' => 'Dipartimento di Prova', 'Interdepartmental' => 'SI', 'LaboratoryScopes' => []],
];
$str_ti = AppTi::per($conn)->get(\App\Anagrafi\StruttureRepository::class);
$q("DELETE FROM anagrafe_strutture WHERE codice = 'TI9'");
$str_ti->aggiungi('TI9', 'Struttura di prova');
$lab_ti = new \App\Tirocini\ServizioLaboratori(\App\Core\Database::per($conn), $finto, $str_ti);
$es = $lab_ti->sincronizza('TI9');
$l1 = $conn->query("SELECT * FROM tirocini_laboratori WHERE id = 990001")->fetch_assoc();
prova($es['TI9']['ok'] && (int)$conn->query("SELECT COUNT(*) c FROM tirocini_laboratori WHERE dip_cod = 'TI9'")->fetch_assoc()['c'] === 2, "laboratori: scaricati per la struttura scelta");
prova($l1['sigla'] === '' && $l1['direttore'] === 'Leonardo Bruno' && $l1['ambiti'] === 'Ricerca, Didattica' && $l1['descrizione'] === 'Analisi di campioni ambientali' && $l1['dipartimento'] === 'Dipartimento di Prova', "laboratori: sigla «None» vuota, responsabile «Nome Cognome», testo senza HTML, dipartimento senza la sigla in coda");
$lab_ti->impostaPubblica(990001, false);
prova(!in_array(990001, array_map('intval', array_column($lab_ti->pubblici(), 'id')), true) && in_array(990002, array_map('intval', array_column($lab_ti->pubblici(), 'id')), true), "un laboratorio si può nascondere dall'elenco pubblico");
$finto->labs = [$finto->labs[1]];
$lab_ti->sincronizza('TI9');
prova((int)$conn->query("SELECT COUNT(*) c FROM tirocini_laboratori WHERE id = 990001")->fetch_assoc()['c'] === 0, "un laboratorio che l'Ateneo non pubblica più sparisce");
$guasto = new class implements \App\Anagrafi\ClientApiAteneo {
    public function get(string $percorso, array $query = [], int $timeout = 25): ?array { return null; }
    public function tutte(string $percorso, array $query = []): ?array { return null; }
    public function scarica(string $url, int $timeout = 10): ?string { return null; }
};
$es2 = (new \App\Tirocini\ServizioLaboratori(\App\Core\Database::per($conn), $guasto, $str_ti))->sincronizza('TI9');
prova(!$es2['TI9']['ok'] && (int)$conn->query("SELECT COUNT(*) c FROM tirocini_laboratori WHERE id = 990002")->fetch_assoc()['c'] === 1, "API non raggiungibili: i laboratori di prima restano");

$q("DELETE FROM tirocini_aziende WHERE cod_tsp LIKE 'T9%'");
$q("DELETE FROM tirocini_laboratori WHERE id >= 990000");
$q("DELETE FROM anagrafe_strutture WHERE codice = 'TI9'");
