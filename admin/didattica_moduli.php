<?php
// didattica_moduli.php - Moduli e documenti: documenti da scaricare e moduli online (campi, logica, iter, verbale), modelli pronti.
// Scheda del pannello Didattica: si apre da didattica.php?tab=moduli (stesso indirizzo di sempre), mai direttamente.
// $fase = 'azioni': gestisce i POST della scheda (ognuno risponde con un rimando e termina); $fase = 'vista': la pagina.
if (!defined('DIDATTICA_PANNELLO')) { http_response_code(403); exit('Accesso negato.'); }

if ($fase === 'azioni') {
    // ── Moduli: salvataggio ed eliminazione ──
    if (isset($_POST['salva_modulo'])) {
        $id = (int)($_POST['modulo_id'] ?? 0);
        $esito = \App\Core\App::per($conn)->get(\App\Didattica\ServizioModuli::class)->salva($_POST, $_FILES);
        if ($esito['errore'] === 'titolo') { flash_set("Il titolo è obbligatorio.", 'danger'); admin_redirect("$base&tab=moduli&" . ($id ? "modifica=$id" : "nuovo=1")); }
        if ($esito['file_rifiutato']) flash_set("Il file non è stato caricato: usa PDF, Word, LibreOffice o Excel.", 'warning');
        registra_log_audit($conn, "Modulo della didattica salvato", ["Titolo" => $esito['titolo'], "Tipo" => $esito['tipo']]);
        if (!isset($_SESSION['_flash'])) flash_set("Modulo \"{$esito['titolo']}\" salvato.");
        admin_redirect("$base&tab=moduli");
    }
    if (isset($_POST['duplica_modulo'])) {
        $nid = \App\Core\App::per($conn)->get(\App\Didattica\ServizioModuli::class)->duplica((int)$_POST['duplica_modulo']);
        if ($nid) registra_log_audit($conn, "Modulo della didattica copiato", ["Da" => (int)$_POST['duplica_modulo'], "Copia" => $nid]);
        flash_set($nid ? "Copia creata (non pubblicata): ritocca il titolo e quello che cambia." : "Modulo non trovato.", $nid ? 'success' : 'danger');
        admin_redirect("$base&tab=moduli" . ($nid ? "&modifica=$nid" : ''));
    }
    if (isset($_POST['elimina_modulo'])) {
        $id = (int)$_POST['elimina_modulo'];
        $n = \App\Core\App::per($conn)->get(\App\Didattica\ServizioModuli::class)->elimina($id);
        if ($n > 0) {
            flash_set("Il modulo ha $n pratiche: non l'ho eliminato ma nascosto agli studenti.", 'warning');
        } else {
            registra_log_audit($conn, "Modulo della didattica eliminato", ["ID" => $id]);
            flash_set("Modulo eliminato.", 'warning');
        }
        admin_redirect("$base&tab=moduli");
    }

    return;
}
?>
<?php // ── MODULI E DOCUMENTI ──
    $mod_m = !empty($_GET['modifica']) ? modulo_didattica($conn, (int)$_GET['modifica']) : null;
    $mostra_form = $mod_m || !empty($_GET['nuovo']);
    $f = $mod_m ?: ['id' => 0, 'titolo' => '', 'categoria' => '', 'descrizione' => '', 'tipo' => 'documento', 'file_path' => null, 'link' => '', 'campi_json' => null, 'verbale_json' => null, 'destinatari' => 'tutti', 'email_ufficio' => '', 'attivo' => 1, 'ordine' => 0, 'aperto_dal' => null, 'aperto_al' => null, 'giorni_promemoria' => 7, 'iter_json' => null];
    $campi_f = json_decode((string)$f['campi_json'], true) ?: [];
    $v_f = verbale_modulo($f + ['titolo' => '']); $v_raw = json_decode((string)($f['verbale_json'] ?? ''), true) ?: [];
    // Modelli pronti: riempiono titolo, categoria, campi e parte del verbale (poi si modifica tutto)
    $modelli = [
        'tesi' => ['Domanda di lavoro finale (tesi)', 'Lauree', 'Assegnazione dell\'argomento del lavoro finale con relatore ed eventuale correlatore.',
            [['Corso di studio', 'corso_studio', '', 1, 0], ['Anno accademico', 'anno_accademico', '', 1, 0], ['Titolo provvisorio del lavoro finale', 'text', '', 1, 0], ['Insegnamento di riferimento', 'insegnamento', '', 0, 0], ['Relatore', 'docente', '', 1, 0], ['Correlatore', 'docente', '', 0, 0]],
            ['sezione' => 'Domande lavoro finale', 'stile' => 'elenco', 'colonne' => 'COGNOME, NOME, MATRICOLA, RELATORE, CORRELATORE', 'raggruppa' => 'Corso di studio', 'chiusura' => 'Il Consiglio approva.', 'iter' => ['referente_cdl', 'carriere']]],
        'passaggio' => ['Passaggio di corso, trasferimento o rinuncia/decadenza', 'Carriera', 'Richiesta di passaggio di corso, trasferimento in entrata o iscrizione dopo rinuncia o decadenza, con gli esami da convalidare.',
            [['Tipo di richiesta', 'select', 'passaggio di corso di studio, trasferimento in entrata, iscrizione per rinuncia e decadenza', 1, 0], ['Corso di provenienza', 'text', '', 1, 0], ['Ateneo di provenienza', 'text', '', 1, 0],
             ['Corso di destinazione', 'corso_studio', '', 1, 0], ['Anno accademico', 'anno_accademico', '', 1, 0], ['Esami sostenuti', 'tabella', 'Insegnamento sostenuto:insegnamento, CFU:cfu, Voto:voto, S.S.D.:ssd, Data:data', 1, 0],
             ['Certificato degli esami', 'file', '', 1, 0], ['Quadro delle convalide', 'tabella', 'Insegnamento convalidato, CFU, Voto, Data, S.S.D., Anno insegnamento, Tot CFU insegn., CFU convalidati, CFU da integrare', 0, 1],
             ['Anno di iscrizione deliberato', 'select', 'primo, secondo, terzo', 0, 1]],
            ['sezione' => 'Domande di passaggio, trasferimento e iscrizione', 'stile' => 'scheda',
             'testo' => 'Lo studente {STUDENTE}, matricola {MATRICOLA}, iscritto per l\'a.a. {Anno accademico} al {Corso di provenienza} presso {Ateneo di provenienza}, chiede {Tipo di richiesta} per l\'a.a. {Anno accademico} al {Corso di destinazione}. Valutati i programmi e la loro corrispondenza con gli insegnamenti erogati, il Consiglio approva la richiesta con il seguente quadro di convalide:',
             'delibera' => 'Il Consiglio delibera l\'iscrizione dello studente al {Anno di iscrizione deliberato} anno del {Corso di destinazione}, con attribuzione del piano di studi secondo il regolamento dell\'anno accademico di riferimento.', 'decisione' => 'convalide', 'iter' => ['referente_cdl', 'carriere']]],
        'convalida' => ['Convalida di esami / riconoscimento crediti', 'Carriera', 'Richiesta di convalida di esami sostenuti in altri corsi o atenei (anche parziale).',
            [['Corso di studio', 'corso_studio', '', 1, 0], ['Anno accademico', 'anno_accademico', '', 1, 0],
             ['Dove hai sostenuto gli esami', 'radio', 'in questo Ateneo, in un altro Ateneo', 1, 0],
             ['Ateneo', 'text', '', 1, 0, ['campo' => 'Dove hai sostenuto gli esami', 'op' => 'uguale', 'valore' => 'in un altro Ateneo']],
             ['Esami da convalidare', 'tabella', 'Insegnamento sostenuto:insegnamento, CFU:cfu, Voto:voto, S.S.D.:ssd, Data:data', 1, 0, null, 'Con la lente scegli l\'insegnamento dal catalogo di Ateneo (tipo di corso, corso, anno di offerta): CFU e S.S.D. si compilano da soli. Se non lo trovi scrivilo a mano.'],
             ['Certificato degli esami o programmi', 'file', '', 0, 0],
             ['Dichiaro che gli esami indicati sono stati regolarmente sostenuti', 'dichiarazione', '', 1, 0, null, 'Dichiarazione resa ai sensi degli artt. 46 e 47 del D.P.R. 445/2000.']],
            ['sezione' => 'Convalida di esami', 'stile' => 'scheda', 'testo' => 'Lo studente {STUDENTE}, matricola {MATRICOLA}, iscritto al {Corso di studio} per l\'a.a. {Anno accademico}, chiede la convalida degli esami sostenuti {Dove hai sostenuto gli esami} {Ateneo}. Il Consiglio, valutati i programmi, delibera le seguenti convalide:',
             'delibera' => 'Il Consiglio approva.', 'decisione' => 'convalide', 'iter' => ['referente_cdl', 'carriere']]],
        'piano' => ['Piano di studi: insegnamenti in piano o fuori piano', 'Piani di studio', 'Richiesta di inserimento nel piano di studi di insegnamenti a scelta, in piano o in soprannumero (fuori piano).',
            [['Corso di studio', 'corso_studio', '', 1, 0], ['Anno accademico', 'anno_accademico', '', 1, 0], ['Anno di corso', 'select', 'primo, secondo, terzo', 1, 0],
             ['Insegnamenti richiesti', 'tabella', 'Insegnamento:insegnamento, CFU:cfu, S.S.D.:ssd, Tipo:scelta(in piano|fuori piano)', 1, 0, null, 'Scegli gli insegnamenti dal catalogo di Ateneo indicando corso e anno accademico di offerta.'],
             ['Motivazione', 'textarea', '', 0, 0]],
            ['sezione' => 'Piani di studio', 'stile' => 'scheda', 'testo' => 'Lo studente {STUDENTE}, matricola {MATRICOLA}, iscritto al {Anno di corso} anno del {Corso di studio}, chiede l\'inserimento nel piano di studi dei seguenti insegnamenti:',
             'delibera' => 'Il Consiglio approva.', 'decisione' => 'piano', 'iter' => ['referente_cdl', 'carriere']]],
        'estero' => ['Autorizzazione ad attività all\'estero', 'Mobilità internazionale', 'Richiesta di autorizzazione allo svolgimento di attività formative all\'estero (Erasmus+ e altri programmi).',
            [['Corso di studio', 'corso_studio', '', 1, 0], ['Programma o bando', 'text', '', 1, 0], ['Ente ospitante', 'text', '', 1, 0], ['Paese', 'text', '', 1, 0], ['Dal', 'date', '', 1, 0], ['Al', 'date', '', 1, 0],
             ['Attività', 'select', 'attività di studio, tirocinio, ricerca tesi, tirocinio e ricerca tesi', 1, 0], ['Learning Agreement', 'file', '', 1, 0]],
            ['sezione' => 'Autorizzazione a svolgere attività all\'estero', 'stile' => 'scheda', 'intro' => 'Sono pervenute le richieste di autorizzazione allo svolgimento di attività formative all\'estero da parte degli studenti di seguito elencati.',
             'testo' => '{STUDENTE}, matricola {MATRICOLA}, regolarmente iscritto al {Corso di studio} e vincitore del bando {Programma o bando} presso {Ente ospitante}, {Paese}, orientativamente dal {Dal} al {Al} per {Attività}.', 'delibera' => '',
             'chiusura' => 'Il Consiglio prende atto delle richieste presentate e approva preventivamente le richieste di riconoscimento come da Learning Agreement, previa verifica documentale a fine delle attività.', 'iter' => ['internazionalizzazione', 'referente_cdl', 'carriere']]],
        'rientro' => ['Riconoscimento delle attività svolte all\'estero', 'Mobilità internazionale', 'Richiesta di riconoscimento dei crediti al rientro dalla mobilità.',
            [['Corso di studio', 'corso_studio', '', 1, 0], ['Programma o bando', 'text', '', 1, 0], ['Ente ospitante', 'text', '', 1, 0], ['Periodo', 'text', '', 1, 0],
             ['Attività svolte', 'tabella', 'Attività svolta, CFU / ore, Esito', 1, 0], ['Transcript of Records o attestato', 'file', '', 1, 0],
             ['Riconoscimenti', 'tabella', 'Insegnamento riconosciuto, Anno insegnamento, Tot CFU insegn., CFU riconosciuti, Voto, CFU da integrare, Data', 0, 1]],
            ['sezione' => 'Comunicazione fine attività di studio all\'estero', 'stile' => 'scheda', 'intro' => 'Il Consiglio esamina la documentazione presentata dagli studenti rientrati dalle attività svolte all\'estero, ai fini del riconoscimento dei crediti formativi preventivamente autorizzati.',
             'testo' => '{STUDENTE}, matricola {MATRICOLA}, regolarmente iscritto al {Corso di studio} e vincitore del bando {Programma o bando} presso {Ente ospitante} ({Periodo}), chiede il riconoscimento delle attività svolte:',
             'delibera' => 'Il Consiglio prende atto della documentazione prodotta e approva il riconoscimento richiesto.', 'iter' => ['internazionalizzazione', 'referente_cdl', 'carriere']]],
    ];
    // Percorsi come nell'elenco delle pratiche del Dipartimento: protocollo → ufficio del corso (referente) → segreteria; tesi con la firma del relatore prima del protocollo
    $iter_std = ['protocollo', '@cdl', '@segreteria'];
    foreach (['passaggio', 'convalida', 'piano'] as $k_m) $modelli[$k_m][4]['iter'] = $iter_std;
    $modelli['passaggio'][4]['bollo'] = 1; $modelli['convalida'][4]['bollo'] = 1;
    $modelli['tesi'][4]['iter'] = [['t' => 'persona', 'campo' => 'Relatore'], 'protocollo', '@cdl'];
    $modelli['estero'][4]['iter'] = ['protocollo', 'internazionalizzazione', '@cdl', '@segreteria'];
    $modelli['rientro'][4]['iter'] = ['protocollo', 'operatore'];
    // Altri modelli (campi essenziali: si completano dal pannello): [titolo, categoria, descrizione, campi aggiuntivi, iter, in verbale?, bollo?, testo per il verbale]
    $base_c = [['Corso di studio', 'corso_studio', '', 1, 0], ['Anno accademico', 'anno_accademico', '', 1, 0]];
    $semplici = [
        'certificato' => ['Richiesta di certificato (iscrizione, laurea, Diploma Supplement)', 'Certificati e attestati', 'Certificati di iscrizione e di laurea e Diploma Supplement: la segreteria carica il certificato, il Direttore lo firma.',
            [['Tipo di certificato', 'select', 'certificato di iscrizione, certificato di laurea, diploma supplement', 1, 0], ['Uso del certificato', 'text', '', 0, 0], ['Certificato caricato dall\'ufficio', 'file', '', 0, 1]], ['protocollo', 'carriere', ['t' => 'direttore']], 0, 1, ''],
        'attestato' => ['Richiesta di attestato (frequenza al corso, esame sostenuto)', 'Certificati e attestati', 'Attestato di frequenza o di esame sostenuto da inviare a un\'azienda o a un ente: lo firma il Direttore e si invia con il protocollo.',
            [['Tipo di attestato', 'select', 'attestato di frequenza al corso, attestato di esame sostenuto', 1, 0], ['Insegnamento o attività', 'text', '', 1, 0], ['Azienda o ente destinatario', 'text', '', 1, 0], ['PEC dell\'azienda o dell\'ente', 'email', '', 0, 0], ['Attestato caricato dall\'ufficio', 'file', '', 0, 1]], ['protocollo', ['t' => 'direttore'], 'protocollo'], 0, 0, ''],
        'esonero' => ['Esonero dalla frequenza e inglese (English for Basic Academic Skills)', 'Carriera', 'Esonero dalla frequenza delle lezioni (anche per motivi di salute o di mobilità internazionale) e richieste sull\'esame di Inglese.',
            [['Tipo di richiesta', 'select', 'esonero dalla frequenza delle lezioni, esonero dalla frequenza per motivi di salute o mobilità internazionale, esonero dalla frequenza English for Basic Academic Skills, convalida esame di Inglese', 1, 0], ['Motivazione', 'textarea', '', 1, 0], ['Documentazione', 'file', '', 0, 0]], $iter_std, 1, 0,
            'Lo studente {STUDENTE}, matricola {MATRICOLA}, iscritto al {Corso di studio}, chiede {Tipo di richiesta}. {Motivazione}'],
        'carriera' => ['Modifica di carriera (curriculum, classe, tempo pieno/part-time, anticipo insegnamenti, fuori piano)', 'Piani di studio', 'Modifica del curriculum o della classe di laurea, passaggio tra tempo pieno e part-time, anticipo di insegnamenti, esami fuori piano.',
            [['Tipo di richiesta', 'select', 'modifica del curriculum o della classe di laurea, modifica tempo pieno o part-time, inserimento esami fuori piano, anticipo insegnamenti', 1, 0], ['Motivazione', 'textarea', '', 0, 0]], $iter_std, 1, 0,
            'Lo studente {STUDENTE}, matricola {MATRICOLA}, iscritto al {Corso di studio}, chiede {Tipo di richiesta}. {Motivazione}'],
        'titolo_estero' => ['Riconoscimento del titolo di studio conseguito all\'estero', 'Carriera', 'Riconoscimento di un titolo di studio conseguito in un altro Paese.',
            [['Titolo conseguito', 'text', '', 1, 0], ['Università e Paese', 'text', '', 1, 0], ['Documentazione', 'file', '', 1, 0]], $iter_std, 1, 0,
            'Lo studente {STUDENTE}, matricola {MATRICOLA}, chiede il riconoscimento del titolo «{Titolo conseguito}» ({Università e Paese}).'],
        'cancellazione' => ['Cancellazione errata iscrizione e semestre aggiuntivo', 'Carriera', 'Cancellazione di un\'iscrizione errata e richiesta di semestre aggiuntivo.',
            [['Tipo di richiesta', 'select', 'cancellazione errata iscrizione, semestre aggiuntivo', 1, 0], ['Motivazione', 'textarea', '', 1, 0]], $iter_std, 1, 0,
            'Lo studente {STUDENTE}, matricola {MATRICOLA}, iscritto al {Corso di studio}, chiede {Tipo di richiesta}. {Motivazione}'],
        'tesi_var' => ['Variazione del lavoro finale (tesi)', 'Lauree', 'Variazione del titolo o del relatore del lavoro finale.',
            [['Titolo attuale', 'text', '', 1, 0], ['Nuovo titolo', 'text', '', 0, 0], ['Relatore', 'docente', '', 1, 0], ['Motivazione', 'textarea', '', 0, 0]], [['t' => 'persona', 'campo' => 'Relatore'], 'protocollo', '@cdl'], 1, 0,
            'Lo studente {STUDENTE}, matricola {MATRICOLA}, chiede la variazione del lavoro finale «{Titolo attuale}». {Motivazione}'],
    ];
    foreach ($semplici as $k_m => $sm) {
        $modelli[$k_m] = [$sm[0], $sm[1], $sm[2], array_merge($base_c, $sm[3]),
            ['sezione' => $sm[0], 'stile' => 'scheda', 'testo' => $sm[7], 'delibera' => $sm[5] ? 'Il Consiglio approva.' : '', 'iter' => $sm[4], 'in_verbale' => $sm[5], 'bollo' => $sm[6]]];
    }
?>
    <?php if ($mostra_form): ?>
    <form method="POST" enctype="multipart/form-data" class="card border-0 shadow-sm mb-3" id="ddForm"><div class="card-body">
        <?php csrf_field(); ?><input type="hidden" name="modulo_id" value="<?php echo (int)$f['id']; ?>">
        <h5 class="fw-bold mb-3"><?php echo $f['id'] ? 'Modifica modulo' : 'Nuovo modulo'; ?></h5>
        <?php if (!$f['id']): ?>
        <div class="dd-preset mb-3">
            <div class="small fw-bold mb-1"><i class="fa fa-wand-magic-sparkles me-1 text-success" aria-hidden="true"></i>Parti da un modello pronto (poi modifichi tutto)</div>
            <div class="d-flex flex-wrap gap-1"><?php foreach ($modelli as $k => $mm): ?><button type="button" class="btn btn-sm btn-outline-success dd-modello" data-modello="<?php echo $k; ?>"><?php echo $h($mm[0]); ?></button><?php endforeach; ?></div>
        </div>
        <?php endif; ?>
        <nav class="pn-passi" id="ddSezioni" aria-label="Passi per creare la pratica">
            <button type="button" class="pn-passo active" data-sez="info"><span class="pn-passo-n">1</span><span>Che pratica è<small>titolo, chi la presenta, quando</small></span></button>
            <button type="button" class="pn-passo dd-online" data-sez="campi"><span class="pn-passo-n">2</span><span>Cosa compila lo studente<small>le domande del modulo</small></span></button>
            <button type="button" class="pn-passo dd-online" data-sez="iter"><span class="pn-passo-n">3</span><span>Chi la lavora<small>l'iter, le firme, i tempi</small></span></button>
            <button type="button" class="pn-passo dd-online" data-sez="uffici"><span class="pn-passo-n">4</span><span>Cosa compilano gli uffici<small>lavorazione e decisione</small></span></button>
            <button type="button" class="pn-passo dd-online" data-sez="verbale"><span class="pn-passo-n">5</span><span>Cosa va nel verbale<small>consiglio sì o no, testo</small></span></button>
            <button type="button" class="pn-passo dd-online" data-sez="email"><span class="pn-passo-n">6</span><span>Email e promemoria<small>chi viene avvisato</small></span></button>
            <button type="button" class="pn-passo dd-online" data-sez="pubblica"><span class="pn-passo-n">7</span><span>Anteprima e pubblica<small>controlla e salva</small></span></button>
        </nav>
        <div class="row g-2">
            <div data-parte="info" class="col-md-6"><label class="form-label small fw-bold" for="mTit">Titolo <span class="text-danger">*</span></label><input type="text" class="form-control" id="mTit" name="titolo" value="<?php echo $h($f['titolo']); ?>" required maxlength="200"></div>
            <div data-parte="info" class="col-md-3"><label class="form-label small fw-bold" for="mCat">Categoria</label><input type="text" class="form-control" id="mCat" name="categoria" value="<?php echo $h($f['categoria']); ?>" list="mCatList" placeholder="es. Tirocini, Piani di studio" maxlength="100">
                <datalist id="mCatList"><?php foreach ($categorie as $c): ?><option value="<?php echo $h($c); ?>"><?php endforeach; ?></datalist></div>
            <div data-parte="info" class="col-md-3"><label class="form-label small fw-bold" for="mTipo">Tipo</label><select class="form-select" id="mTipo" name="tipo">
                <option value="documento"<?php echo $f['tipo'] === 'documento' ? ' selected' : ''; ?>>Documento da scaricare</option>
                <option value="online"<?php echo $f['tipo'] === 'online' ? ' selected' : ''; ?>>Modulo online (apre una pratica)</option></select></div>
            <div data-parte="info" class="col-12"><label class="form-label small fw-bold" for="mDes">Descrizione e istruzioni</label><textarea class="form-control editor-html" id="mDes" name="descrizione" rows="4"><?php echo $h($f['descrizione']); ?></textarea></div>
            <div data-parte="info" class="col-md-6 dd-doc"><label class="form-label small fw-bold" for="mFile">File da scaricare<?php echo $f['file_path'] ? ' (carica solo per sostituirlo)' : ''; ?></label><input type="file" class="form-control" id="mFile" name="file_modulo" accept=".pdf,.doc,.docx,.odt,.xls,.xlsx,.ods,.rtf">
                <?php if ($f['file_path']): ?><div class="form-text"><a href="../<?php echo $h($f['file_path']); ?>" target="_blank" rel="noopener">File attuale</a></div><?php endif; ?></div>
            <div data-parte="info" class="col-md-6"><label class="form-label small fw-bold" for="mLink">Link esterno (facoltativo)</label><input type="url" class="form-control" id="mLink" name="link" value="<?php echo $h($f['link']); ?>" placeholder="https://www.unical.it/..."></div>
            <div data-parte="info" class="col-md-4"><label class="form-label small fw-bold" for="mDest">Chi può compilare (moduli online)</label><select class="form-select" id="mDest" name="destinatari"><?php foreach (DESTINATARI_MODULO as $k => $n): ?><option value="<?php echo $k; ?>"<?php echo $f['destinatari'] === $k ? ' selected' : ''; ?>><?php echo $h($n); ?></option><?php endforeach; ?></select></div>
            <div data-parte="email" class="col-md-5"><label class="form-label small fw-bold" for="mEm">Email che riceve le pratiche (facoltativa)</label><input type="text" class="form-control" id="mEm" name="email_ufficio" value="<?php echo $h($f['email_ufficio']); ?>" placeholder="segreteria.didattica@unical.it"><div class="form-text">Vuoto = operatori dell'Ufficio didattico con il compito «Pratiche».</div></div>
            <div data-parte="pubblica" class="col-md-1"><label class="form-label small fw-bold" for="mOrd">Ordine</label><input type="number" class="form-control" id="mOrd" name="ordine" value="<?php echo (int)$f['ordine']; ?>"></div>
            <div data-parte="pubblica" class="col-md-2 d-flex align-items-end"><label class="form-check"><input class="form-check-input" type="checkbox" name="attivo" value="1"<?php echo (int)$f['attivo'] ? ' checked' : ''; ?>> Pubblicato</label></div>
            <div data-parte="info" class="col-md-3 dd-online"><label class="form-label small fw-bold" for="mDal">Compilabile dal</label><input type="date" class="form-control" id="mDal" name="aperto_dal" value="<?php echo $h($f['aperto_dal'] ?? ''); ?>"></div>
            <div data-parte="info" class="col-md-3 dd-online"><label class="form-label small fw-bold" for="mAl">al</label><input type="date" class="form-control" id="mAl" name="aperto_al" value="<?php echo $h($f['aperto_al'] ?? ''); ?>"><div class="form-text">Vuoti = sempre aperto. Fuori dal periodo il modulo resta visibile ma non si compila.</div></div>
            <div data-parte="email" class="col-md-4 dd-online"><label class="form-label small fw-bold" for="mGg">Promemoria se la pratica è ferma da (giorni)</label><input type="number" min="0" max="90" class="form-control" id="mGg" name="giorni_promemoria" value="<?php echo (int)($f['giorni_promemoria'] ?? 7); ?>" style="max-width:120px;"><div class="form-text">Email a chi l'ha in carico (o a chi smista); 0 = nessun promemoria.</div></div>
        </div>

        <fieldset class="dd-online mt-3 border rounded p-2" data-parte="campi uffici" id="ddCampiBox">
            <legend class="form-label small fw-bold float-none w-auto px-1 mb-1" id="ddCampiLegenda">Campi del modulo online</legend>
            <p class="small text-primary mb-2" id="ddCampiNota"></p>
            <p class="small text-secondary mb-2">Nome, cognome, email e matricola si prendono dall'accesso. <strong>Dalle anagrafi</strong>: «Corso di studio», «Insegnamento del Dipartimento», «Insegnamento di Ateneo» (lo studente sceglie tipo di corso, corso, anno accademico di offerta e insegnamento, o lo scrive a mano) e «Docente». <strong>Tabella a righe</strong>: con <i class="fa fa-sliders" aria-hidden="true"></i> scegli nome e tipo di ogni colonna (es. Insegnamento dal catalogo, CFU e S.S.D. che si compilano da soli, Voto, Data). Con <i class="fa fa-sliders" aria-hidden="true"></i> imposti anche la <strong>logica</strong>: il campo compare solo se un'altra risposta ha un certo valore, oppure si compila in automatico. Spunta <strong>ufficio</strong> per i campi che compila solo l'ufficio nell'istruttoria. Opzioni separate da virgole.</p>
            <div class="dd-campo dd-testa small fw-bold text-secondary d-none d-lg-grid"><span>Domanda</span><span>Tipo</span><span>Opzioni / colonne</span><span></span><span></span><span>Aiuto (facoltativo)</span><span></span><span></span></div>
            <div id="ddCampi">
                <?php foreach ($campi_f ?: [['etichetta' => '', 'tipo' => 'text']] as $c):
                    $cond = is_array($c['cond'] ?? null) ? $c['cond'] : []; $auto = is_array($c['auto'] ?? null) ? $c['auto'] : [];
                    $opz_txt = is_array($c['opzioni'] ?? null) ? implode(', ', $c['opzioni']) : (string)($c['opzioni'] ?? '');
                    $cols_f = ($c['tipo'] ?? '') === 'tabella' ? colonne_tabella(array_values(array_filter(array_map('trim', preg_split('/[,;\n]/', $opz_txt)), 'strlen'))) : []; ?>
                <div class="dd-campo<?php echo !empty($c['ufficio']) ? ' uff' : ''; ?>">
                    <input type="text" class="form-control form-control-sm" name="c_etichetta[]" value="<?php echo $h($c['etichetta'] ?? ''); ?>" placeholder="Domanda" aria-label="Domanda">
                    <select class="form-select form-select-sm dd-tipo" name="c_tipo[]" aria-label="Tipo"><?php foreach (GRUPPI_TIPI_CAMPO as $g => $tt): ?><optgroup label="<?php echo $h($g); ?>"><?php foreach ($tt as $k): ?><option value="<?php echo $k; ?>"<?php echo ($c['tipo'] ?? '') === $k ? ' selected' : ''; ?>><?php echo $h(TIPI_CAMPO_PRATICA[$k]); ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select>
                    <input type="text" class="form-control form-control-sm dd-opz" name="c_opzioni[]" value="<?php echo $h($cols_f ? testo_colonne_tabella($cols_f) : $opz_txt); ?>" placeholder="Opzioni o colonne" aria-label="Opzioni o colonne">
                    <span><input type="hidden" name="c_obbl[]" value="<?php echo !empty($c['obbligatorio']) ? '1' : '0'; ?>"><label class="form-check small text-nowrap m-0"><input class="form-check-input dd-chk" type="checkbox"<?php echo !empty($c['obbligatorio']) ? ' checked' : ''; ?>> obbligatorio</label></span>
                    <span><input type="hidden" name="c_uff[]" value="<?php echo !empty($c['ufficio']) ? '1' : '0'; ?>"><label class="form-check small text-nowrap m-0"><input class="form-check-input dd-chk dd-uff" type="checkbox"<?php echo !empty($c['ufficio']) ? ' checked' : ''; ?>> ufficio</label></span>
                    <input type="text" class="form-control form-control-sm" name="c_aiuto[]" value="<?php echo $h($c['aiuto'] ?? ''); ?>" placeholder="Aiuto o testo (facoltativo)" aria-label="Testo di aiuto">
                    <button type="button" class="btn btn-sm <?php echo $cond || $auto ? 'btn-warning' : 'btn-outline-secondary'; ?> dd-logica-btn" aria-label="Colonne e logica del campo" title="Colonne e logica"><i class="fa fa-sliders" aria-hidden="true"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-danger dd-togli" aria-label="Togli il campo"><i class="fa fa-times" aria-hidden="true"></i></button>
                    <div class="dd-logica" hidden>
                        <div class="dd-colonne mb-2"<?php echo ($c['tipo'] ?? '') === 'tabella' ? '' : ' hidden'; ?>>
                            <div class="small fw-bold mb-1"><i class="fa fa-table-columns me-1" aria-hidden="true"></i>Colonne della tabella</div>
                            <div class="dd-col-righe"></div>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 dd-col-agg"><i class="fa fa-plus me-1" aria-hidden="true"></i>Colonna</button>
                            <span class="small text-secondary ms-2">«Insegnamento (catalogo di Ateneo)» compila da solo CFU e S.S.D. nelle colonne di quel tipo.</span>
                        </div>
                        <div class="row g-1 align-items-center small mb-1">
                            <div class="col-md-2 fw-bold"><i class="fa fa-eye me-1" aria-hidden="true"></i>Mostra solo se</div>
                            <div class="col-md-4"><select class="form-select form-select-sm dd-rif" name="c_cond_campo[]" data-val="<?php echo $h($cond['campo'] ?? ''); ?>" aria-label="Campo della condizione"><option value="">— sempre visibile —</option></select></div>
                            <div class="col-md-2"><select class="form-select form-select-sm" name="c_cond_op[]" aria-label="Condizione"><?php foreach (OPERATORI_CONDIZIONE as $k => $n): ?><option value="<?php echo $k; ?>"<?php echo ($cond['op'] ?? 'uguale') === $k ? ' selected' : ''; ?>><?php echo $h($n); ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-4"><input class="form-control form-control-sm" name="c_cond_val[]" value="<?php echo $h($cond['valore'] ?? ''); ?>" placeholder="valore (es. trasferimento in entrata)" aria-label="Valore della condizione"></div>
                        </div>
                        <div class="row g-1 align-items-center small">
                            <div class="col-md-2 fw-bold"><i class="fa fa-wand-magic-sparkles me-1" aria-hidden="true"></i>Compila se</div>
                            <div class="col-md-3"><select class="form-select form-select-sm dd-rif" name="c_auto_campo[]" data-val="<?php echo $h($auto['campo'] ?? ''); ?>" aria-label="Campo del valore automatico"><option value="">— mai —</option></select></div>
                            <div class="col-md-2"><select class="form-select form-select-sm" name="c_auto_op[]" aria-label="Condizione"><?php foreach (OPERATORI_CONDIZIONE as $k => $n): ?><option value="<?php echo $k; ?>"<?php echo ($auto['op'] ?? 'uguale') === $k ? ' selected' : ''; ?>><?php echo $h($n); ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-2"><input class="form-control form-control-sm" name="c_auto_val[]" value="<?php echo $h($auto['valore'] ?? ''); ?>" placeholder="valore" aria-label="Valore"></div>
                            <div class="col-md-3"><input class="form-control form-control-sm" name="c_auto_imposta[]" value="<?php echo $h($auto['imposta'] ?? ''); ?>" placeholder="allora questo campo vale…" aria-label="Valore automatico"></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary fw-bold" id="ddAggiungi"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi campo</button>
        </fieldset>

        <fieldset class="dd-online mt-3 border rounded p-2" data-parte="iter">
            <legend class="form-label small fw-bold float-none w-auto px-1 mb-1"><i class="fa fa-route me-1 text-primary" aria-hidden="true"></i>Iter della pratica</legend>
            <p class="small text-secondary mb-2">I passi in ordine, dopo l'invio dello studente. Un passo può essere <strong>un ufficio</strong> (protocollo, ufficio del corso di studio, segreteria…), la <strong>firma di una persona scelta dallo studente</strong> (es. il relatore: nel modulo ci vuole una domanda di tipo «Docente») o la <strong>firma del Direttore</strong>. Per ogni passo puoi indicare dopo quanti giorni ricordarlo a chi deve agire (vuoto = i giorni del passo 6). Lo studente vede a che punto è. Nessun passo = un solo passo generico.</p>
            <div id="ddIter"></div>
            <button type="button" class="btn btn-sm btn-outline-secondary fw-bold" id="ddIterAgg"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi un passo</button>
            <div class="form-text mt-1">La firma si fa con un link personale per email: la persona entra con le credenziali di Ateneo e firma in PAdES (firma remota Aruba o PDF caricato). Il Direttore è quello indicato in Formazione Scuola Lavoro → Scuole e convenzioni.</div>
        </fieldset>

        <?php $d_f = function_exists('config_domanda') ? config_domanda($f) : ['pdf' => false, 'bollo' => false, 'oggetto' => '', 'destinatario' => '', 'chiede' => '']; ?>
        <fieldset class="dd-online mt-3 border rounded p-2" data-parte="iter">
            <legend class="form-label small fw-bold float-none w-auto px-1 mb-1"><i class="fa fa-file-pdf me-1 text-danger" aria-hidden="true"></i>Domanda in PDF/A da protocollare</legend>
            <label class="form-check small mb-2"><input class="form-check-input" type="checkbox" name="d_pdf" value="1"<?php echo $d_f['pdf'] ? ' checked' : ''; ?>> All'invio il portale prepara la domanda in PDF/A-2b con il logo del Dipartimento: «Il/La sottoscritto/a … nato/a a … CHIEDE …», tabelle, dichiarazioni e metadati (chi, quando, come ha fatto l'accesso, impronta SHA-256)</label>
            <label class="form-check small mb-2"><input class="form-check-input" type="checkbox" name="d_bollo" value="1"<?php echo $d_f['bollo'] ? ' checked' : ''; ?>> Richiede la <strong>marca da bollo</strong>: chi protocolla spunta il pagamento e solo allora la pratica passa all'ufficio successivo</label>
            <div class="row g-2">
                <div class="col-md-5"><label class="form-label small fw-bold" for="dOgg">Oggetto</label><input type="text" class="form-control form-control-sm" id="dOgg" name="d_oggetto" value="<?php echo $h($d_f['oggetto'] === ($f['titolo'] ?? '') ? '' : $d_f['oggetto']); ?>" maxlength="300" placeholder="Vuoto = titolo del modulo"></div>
                <div class="col-md-7"><label class="form-label small fw-bold" for="dDest">Destinatario (in alto a destra, una riga per a capo)</label><textarea class="form-control form-control-sm" id="dDest" name="d_destinatario" rows="3" maxlength="600"><?php echo $h($d_f['destinatario']); ?></textarea></div>
                <div class="col-12"><label class="form-label small fw-bold" for="dChiede">Testo dopo «CHIEDE»</label><textarea class="form-control form-control-sm" id="dChiede" name="d_chiede" rows="2" maxlength="2000"><?php echo $h($d_f['chiede']); ?></textarea>
                    <div class="form-text">Stessi segnaposto del verbale, più <code>{ATENEO_PRECEDENTE}</code>. I dati anagrafici (luogo e data di nascita, codice fiscale, residenza, cellulare, anno accademico, corso, matricola) si riconoscono dalle domande del modulo.</div></div>
            </div>
        </fieldset>

        <fieldset class="dd-online mt-3 border rounded p-2" data-parte="verbale">
            <legend class="form-label small fw-bold float-none w-auto px-1 mb-1"><i class="fa fa-file-word me-1 text-primary" aria-hidden="true"></i>Nel verbale del Consiglio</legend>
            <input type="hidden" name="in_verbale_c" value="1">
            <label class="form-check fw-bold mb-2"><input class="form-check-input" type="checkbox" name="in_verbale" value="1" id="ddInVerbale"<?php echo (int)($f['in_verbale'] ?? 1) ? ' checked' : ''; ?>> Questa pratica passa dal verbale del Consiglio</label>
            <div class="small text-secondary mb-2" id="ddNoVerbale" hidden>Le pratiche di questo modulo non si portano in seduta: si lavorano e si concludono dall'ufficio (es. certificati e attestati). Sotto, l'impaginazione del verbale non serve.</div>
            <p class="small text-secondary mb-2">Segnaposto: <code>{STUDENTE}</code> (COGNOME NOME), <code>{NOME}</code>, <code>{COGNOME}</code>, <code>{MATRICOLA}</code>, <code>{MODULO}</code>, <code>{DATA}</code>, <code>{PROTOCOLLO}</code> e ogni domanda tra graffe, es. <code>{Corso di studio}</code>. <code>**testo**</code> = grassetto. Le tabelle a righe compaiono sotto il testo di ogni pratica.</p>
            <div class="row g-2">
                <div class="col-md-6"><label class="form-label small fw-bold" for="vSez">Titolo della sezione</label><input type="text" class="form-control form-control-sm" id="vSez" name="v_sezione" value="<?php echo $h($v_raw['sezione'] ?? ''); ?>" placeholder="Vuoto = titolo del modulo"></div>
                <div class="col-md-6"><label class="form-label small fw-bold" for="vStile">Impaginazione</label><select class="form-select form-select-sm" id="vStile" name="v_stile">
                    <option value="scheda"<?php echo $v_f['stile'] === 'scheda' ? ' selected' : ''; ?>>Un paragrafo per pratica (con tabelle e delibera)</option>
                    <option value="elenco"<?php echo $v_f['stile'] === 'elenco' ? ' selected' : ''; ?>>Una tabella con una riga per pratica (es. domande di tesi)</option></select></div>
                <div class="col-md-6"><label class="form-label small fw-bold" for="vDec">Decisioni in seduta</label><select class="form-select form-select-sm" id="vDec" name="v_decisione">
                    <?php foreach (DECISIONI_SEDUTA as $k => $n): ?><option value="<?php echo $k; ?>"<?php echo $v_f['decisione'] === $k ? ' selected' : ''; ?>><?php echo $h($n); ?></option><?php endforeach; ?></select>
                    <div class="form-text">Nella seduta, per ogni insegnamento indicato dallo studente: convalida (con l'insegnamento del Dipartimento, totale o parziale) o inserimento in piano / fuori piano. Va nel verbale come tabella.</div></div>
                <div class="col-12"><label class="form-label small fw-bold" for="vIntro">Testo introduttivo (facoltativo)</label><textarea class="form-control form-control-sm" id="vIntro" name="v_intro" rows="2"><?php echo $h($v_raw['intro'] ?? ''); ?></textarea></div>
                <div class="col-md-7 dd-v-scheda"><label class="form-label small fw-bold" for="vTesto">Testo per ogni pratica</label><textarea class="form-control form-control-sm" id="vTesto" name="v_testo" rows="4" placeholder="<?php echo $h(verbale_modulo(['titolo' => ''])['testo']); ?>"><?php echo $h($v_raw['testo'] ?? ''); ?></textarea></div>
                <div class="col-md-5 dd-v-scheda"><label class="form-label small fw-bold" for="vDel">Delibera predefinita per ogni pratica</label><textarea class="form-control form-control-sm" id="vDel" name="v_delibera" rows="4"><?php echo $h($v_raw['delibera'] ?? 'Il Consiglio approva.'); ?></textarea><div class="form-text">Si può cambiare pratica per pratica nell'istruttoria.</div></div>
                <div class="col-md-7 dd-v-elenco"><label class="form-label small fw-bold" for="vCol">Colonne della tabella</label><input type="text" class="form-control form-control-sm" id="vCol" name="v_colonne" value="<?php echo $h($v_raw['colonne'] ?? ''); ?>" placeholder="COGNOME, NOME, MATRICOLA, RELATORE"><div class="form-text">COGNOME, NOME, MATRICOLA, CODICE, PROTOCOLLO, DELIBERA o le domande del modulo. Vuoto = tutte.</div></div>
                <div class="col-md-5 dd-v-elenco"><label class="form-label small fw-bold" for="vRag">Raggruppa per la domanda</label><input type="text" class="form-control form-control-sm" id="vRag" name="v_raggruppa" value="<?php echo $h($v_raw['raggruppa'] ?? ''); ?>" placeholder="es. Corso di studio"></div>
                <div class="col-12"><label class="form-label small fw-bold" for="vChi">Testo finale della sezione (facoltativo)</label><textarea class="form-control form-control-sm" id="vChi" name="v_chiusura" rows="2" placeholder="es. Il Consiglio approva."><?php echo $h($v_raw['chiusura'] ?? ''); ?></textarea></div>
            </div>
        </fieldset>
        <fieldset class="dd-online mt-3 border rounded p-2" data-parte="email">
            <legend class="form-label small fw-bold float-none w-auto px-1 mb-1"><i class="fa fa-envelope me-1 text-primary" aria-hidden="true"></i>Email e promemoria</legend>
            <p class="small text-secondary mb-2">Le email partono da sole; qui scegli solo l'indirizzo che riceve le pratiche (sopra) e i giorni del promemoria (sopra e, per ogni passo, nell'iter).</p>
            <ul class="small mb-0">
                <li><strong>Allo studente:</strong> conferma di invio, ogni passaggio dell'iter, richiesta di integrazione, esito.</li>
                <li><strong>All'ufficio che riceve la pratica:</strong> nuova pratica, assegnazione, messaggi dello studente, integrazioni.</li>
                <li><strong>A chi deve firmare:</strong> link personale per la firma e promemoria (fino a tre) dopo i giorni del passo.</li>
                <li><strong>A chi ha la pratica ferma:</strong> promemoria dopo i giorni del passo (o del modulo); con 0 non parte nessun promemoria.</li>
            </ul>
        </fieldset>
        <fieldset class="dd-online mt-3 border rounded p-2" data-parte="pubblica">
            <legend class="form-label small fw-bold float-none w-auto px-1 mb-1"><i class="fa fa-flag-checkered me-1 text-success" aria-hidden="true"></i>Anteprima e pubblica</legend>
            <div id="ddRiepilogo" class="small"></div>
            <div class="form-text mt-2">«Salva come bozza» non la pubblica: la vedi solo tu dal pannello. «Salva e pubblica» la rende compilabile dagli studenti (nel periodo indicato al passo 1). Con «Guarda come lo vede lo studente» apri il modulo vero.</div>
        </fieldset>
        <div class="d-flex justify-content-between mt-3 dd-online" id="ddNavPassi">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="ddPrec"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Indietro</button>
            <button type="button" class="btn btn-sm btn-primary fw-bold" id="ddSucc">Avanti<i class="fa fa-arrow-right ms-1" aria-hidden="true"></i></button>
        </div>
        <div class="pn-salva">
            <button type="submit" name="salva_modulo" value="1" class="btn btn-outline-primary fw-bold" id="ddBozza"><i class="fa fa-pen-ruler me-1" aria-hidden="true"></i>Salva come bozza</button>
            <button type="submit" name="salva_modulo" value="1" class="btn btn-primary fw-bold" id="ddPubblica"><i class="fa fa-earth-europe me-1" aria-hidden="true"></i>Salva e pubblica</button>
            <a href="<?php echo $base; ?>&amp;tab=moduli" class="btn btn-outline-secondary">Annulla</a>
            <?php if ($f['id'] && $f['tipo'] === 'online'): ?><a class="btn btn-outline-secondary ms-auto" href="../modulo.php?id=<?php echo (int)$f['id']; ?>&amp;anteprima=1" target="_blank" rel="noopener"><i class="fa fa-eye me-1" aria-hidden="true"></i>Guarda come lo vede lo studente</a><?php endif; ?>
        </div>
    </div></form>
    <script>
    (function () {
        var modelli = <?php foreach ($modelli as &$mm_i) $mm_i[4]['iter'] = array_values(array_filter(array_map(fn($c) => is_array($c) ? $c : (in_array($c, ['@cdl', '@segreteria'], true) ? $c : ufficio_didattica_id($conn, $c)), $mm_i[4]['iter'] ?? []))); unset($mm_i); echo json_encode($modelli, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        var TIPI_COL = <?php echo json_encode(TIPI_COLONNA_TABELLA, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        var tipo = document.getElementById('mTipo'), box = document.getElementById('ddCampi'), stile = document.getElementById('vStile');
        function aggiorna() {
            document.querySelectorAll('.dd-online').forEach(function (x) { x.hidden = tipo.value !== 'online'; });
            document.querySelectorAll('.dd-v-scheda').forEach(function (x) { x.hidden = stile.value !== 'scheda'; });
            document.querySelectorAll('.dd-v-elenco').forEach(function (x) { x.hidden = stile.value !== 'elenco'; });
        }
        tipo.addEventListener('change', aggiorna); stile.addEventListener('change', aggiorna); aggiorna();

        // ── Un passo alla volta: Che pratica è · Cosa compila lo studente · Chi la lavora · Cosa compilano gli uffici · Cosa va nel verbale · Email · Pubblica ──
        var PASSI = ['info', 'campi', 'iter', 'uffici', 'verbale', 'email', 'pubblica'], corrente = 'info';
        var bottoni = document.querySelectorAll('#ddSezioni [data-sez]'), boxCampi = document.getElementById('ddCampi');
        function mostraParte(k) {
            corrente = k;
            document.querySelectorAll('#ddForm [data-parte]').forEach(function (x) { x.classList.toggle('pn-nascosta', x.dataset.parte.split(' ').indexOf(k) < 0); });
            bottoni.forEach(function (b) { b.classList.toggle('active', b.dataset.sez === k); });
            // La stessa lista di domande serve ai passi 2 e 4: allo studente le sue, all'ufficio le sue
            boxCampi.classList.toggle('solo-studente', k === 'campi'); boxCampi.classList.toggle('solo-ufficio', k === 'uffici');
            document.getElementById('ddCampiLegenda').textContent = k === 'uffici' ? 'Campi che compila l\'ufficio' : 'Campi che compila lo studente';
            document.getElementById('ddCampiNota').textContent = k === 'uffici' ? 'Questi campi non li vede lo studente: li compila chi lavora la pratica (e possono andare nel verbale). «Aggiungi campo» ne crea uno dell\'ufficio.' : '';
            document.querySelectorAll('#ddCampi .dd-campo').forEach(function (r) {
                var uff = r.querySelector('.dd-uff').checked;
                r.style.display = (k === 'campi' && uff) || (k === 'uffici' && !uff) ? 'none' : '';
            });
            document.getElementById('ddPrec').hidden = k === 'info';
            document.getElementById('ddSucc').hidden = k === 'pubblica';
            if (k === 'pubblica') riepilogo();
            if (k === 'iter') aggiornaCampiDocente();
        }
        bottoni.forEach(function (b) { b.addEventListener('click', function () { mostraParte(b.dataset.sez); }); });
        document.getElementById('ddPrec').addEventListener('click', function () { mostraParte(PASSI[Math.max(0, PASSI.indexOf(corrente) - 1)]); window.scrollTo({ top: document.getElementById('ddForm').offsetTop - 80, behavior: 'smooth' }); });
        document.getElementById('ddSucc').addEventListener('click', function () { mostraParte(PASSI[Math.min(PASSI.length - 1, PASSI.indexOf(corrente) + 1)]); window.scrollTo({ top: document.getElementById('ddForm').offsetTop - 80, behavior: 'smooth' }); });
        tipo.addEventListener('change', function () { if (tipo.value !== 'online') mostraParte('info'); document.getElementById('ddNavPassi').hidden = tipo.value !== 'online'; });
        document.getElementById('ddNavPassi').hidden = tipo.value !== 'online';
        // Pulsanti di salvataggio: bozza (non pubblicato) o pubblica
        document.getElementById('ddBozza').addEventListener('click', function () { document.querySelector('[name=attivo]').checked = false; if (window.tinymce) tinymce.triggerSave(); });
        document.getElementById('ddPubblica').addEventListener('click', function () { document.querySelector('[name=attivo]').checked = true; if (window.tinymce) tinymce.triggerSave(); });
        var inVerbale = document.getElementById('ddInVerbale');
        function aggiornaVerbale() { var si = inVerbale.checked; document.getElementById('ddNoVerbale').hidden = si; document.querySelectorAll('#ddForm [data-parte="verbale"] .row').forEach(function (r) { r.hidden = !si; }); }
        inVerbale.addEventListener('change', aggiornaVerbale); aggiornaVerbale();

        // ── Editor dell'iter: tipo di passo, ufficio o domanda «Docente», giorni del promemoria ──
        var UFFICI = <?php echo json_encode(array_values(array_map(fn($k, $u) => ['id' => (string)$k, 'nome' => $u['nome']], array_keys($uff_iter = array_filter(uffici_didattica($conn), fn($u) => !(int)$u['smista'])), $uff_iter)), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        var ITER0 = <?php echo json_encode(\App\Core\App::per($conn)->get(\App\Didattica\ServizioIter::class)->definizione($f['id'] ? $f : null), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        var boxIter = document.getElementById('ddIter');
        function opt(v, n, sel) { var o = document.createElement('option'); o.value = v; o.textContent = n; if (sel) o.selected = true; return o; }
        function rigaIter(d) {
            var r = document.createElement('div'); r.className = 'dd-iter-riga d-flex flex-wrap gap-2 align-items-center mb-2';
            r.innerHTML = '<span class="badge text-bg-secondary dd-it-n" style="min-width:26px;"></span>'
                + '<select class="form-select form-select-sm dd-it-tipo" name="iter_tipo[]" style="max-width:300px;" aria-label="Tipo di passo"></select>'
                + '<select class="form-select form-select-sm dd-it-uff" name="iter_uff[]" style="max-width:290px;" aria-label="Ufficio"></select>'
                + '<select class="form-select form-select-sm dd-it-campo" name="iter_campo[]" style="max-width:260px;" aria-label="Domanda con il nome del docente"></select>'
                + '<input type="text" class="form-control form-control-sm dd-it-colonna" name="iter_colonna[]" style="max-width:200px;" placeholder="Colonna del docente" aria-label="Colonna della tabella con il nome del docente">'
                + '<select class="form-select form-select-sm dd-it-pag" name="iter_pag[]" style="max-width:300px;" aria-label="Attesa del pagamento delle marche da bollo"></select>'
                + '<label class="small text-nowrap m-0">ricorda dopo <input type="number" min="0" max="90" class="form-control form-control-sm d-inline-block dd-it-gg" name="iter_gg[]" style="width:64px;" aria-label="Giorni"> giorni</label>'
                + '<span class="ms-auto d-flex gap-1"><button type="button" class="btn btn-sm btn-outline-secondary py-0 dd-it-su" aria-label="Sposta in su"><i class="fa fa-arrow-up" aria-hidden="true"></i></button><button type="button" class="btn btn-sm btn-outline-secondary py-0 dd-it-giu" aria-label="Sposta in giù"><i class="fa fa-arrow-down" aria-hidden="true"></i></button><button type="button" class="btn btn-sm btn-outline-danger py-0 dd-it-togli" aria-label="Togli il passo"><i class="fa fa-times" aria-hidden="true"></i></button></span>';
            var t = r.querySelector('.dd-it-tipo'), u = r.querySelector('.dd-it-uff'), c = r.querySelector('.dd-it-campo');
            t.appendChild(opt('ufficio', 'Un ufficio', d.t === 'ufficio')); t.appendChild(opt('persona', 'Firma di una persona indicata nel modulo', d.t === 'persona')); t.appendChild(opt('direttore', 'Firma del Direttore', d.t === 'direttore')); t.appendChild(opt('conferma', 'Conferma dei docenti indicati nel modulo (attestati)', d.t === 'conferma'));
            u.appendChild(opt('', '— scegli l\'ufficio —', false));
            UFFICI.forEach(function (x) { u.appendChild(opt(x.id, x.nome, d.t === 'ufficio' && String(d.u) === x.id)); });
            var g = document.createElement('optgroup'); g.label = 'Scelto con la pratica';
            g.appendChild(opt('@cdl', 'Ufficio del corso di studio (referente, dopo il protocollo)', d.u === '@cdl')); g.appendChild(opt('@segreteria', 'Segreteria studenti (scelta dal referente)', d.u === '@segreteria')); u.appendChild(g);
            var pg = r.querySelector('.dd-it-pag'); pg.appendChild(opt('0', 'Passo normale', !d.pagamento && !d.documento && !d.genera && !d.uscita)); pg.appendChild(opt('1', 'Attesa del pagamento delle marche da bollo', !!d.pagamento)); pg.appendChild(opt('2', 'L\'ufficio carica il documento da far firmare', !!d.documento)); pg.appendChild(opt('3', 'Il portale prepara l\'attestato: l\'ufficio lo controlla e lo manda al Direttore', !!d.genera)); pg.appendChild(opt('4', 'Protocollo in uscita: numero, data e PDF firmato e protocollato', !!d.uscita));
            c.dataset.val = d.campo || ''; r.querySelector('.dd-it-colonna').value = d.colonna || ''; r.querySelector('.dd-it-gg').value = d.gg ? d.gg : '';
            adegua(r); return r;
        }
        function adegua(r) { var t = r.querySelector('.dd-it-tipo').value; r.querySelector('.dd-it-uff').hidden = t !== 'ufficio'; r.querySelector('.dd-it-campo').hidden = t !== 'persona' && t !== 'conferma'; r.querySelector('.dd-it-colonna').hidden = t !== 'conferma'; r.querySelector('.dd-it-pag').hidden = t !== 'ufficio'; }
        function numeraIter() { boxIter.querySelectorAll('.dd-it-n').forEach(function (n, i) { n.textContent = i + 1; }); }
        function aggiornaCampiDocente() {
            var doc0 = [];
            var tab = [];
            box.querySelectorAll('.dd-campo').forEach(function (r) { var e = r.querySelector('[name="c_etichetta[]"]').value.trim(), tp = r.querySelector('.dd-tipo').value; if (e && tp === 'docente' && !r.querySelector('.dd-uff').checked) doc0.push(e); if (e && tp === 'tabella' && !r.querySelector('.dd-uff').checked) tab.push(e); });
            boxIter.querySelectorAll('.dd-it-campo').forEach(function (c) {
                var conf = c.closest('.dd-iter-riga').querySelector('.dd-it-tipo').value === 'conferma', doc = conf ? doc0.concat(tab) : doc0;
                var v = c.dataset.val !== undefined && c.dataset.val !== '' ? c.dataset.val : c.value; c.innerHTML = '';
                c.appendChild(opt('', doc.length ? '— scegli la domanda —' : '— aggiungi una domanda «Docente» al passo 2 —', false));
                doc.forEach(function (e) { c.appendChild(opt(e, e, e === v)); });
                if (v && doc.indexOf(v) < 0) c.appendChild(opt(v, v + ' (domanda non trovata)', true));
                c.value = v || ''; c.dataset.val = c.value;
            });
        }
        ITER0.forEach(function (d) { boxIter.appendChild(rigaIter(d)); }); numeraIter();
        document.getElementById('ddIterAgg').addEventListener('click', function () { boxIter.appendChild(rigaIter({ t: 'ufficio', u: '', gg: 0 })); numeraIter(); aggiornaCampiDocente(); });
        boxIter.addEventListener('change', function (e) { if (e.target.classList.contains('dd-it-tipo')) { adegua(e.target.closest('.dd-iter-riga')); aggiornaCampiDocente(); } if (e.target.classList.contains('dd-it-campo')) e.target.dataset.val = e.target.value; });
        boxIter.addEventListener('click', function (e) {
            var r = e.target.closest('.dd-iter-riga'); if (!r) return;
            if (e.target.closest('.dd-it-togli')) r.remove();
            else if (e.target.closest('.dd-it-su') && r.previousElementSibling) boxIter.insertBefore(r, r.previousElementSibling);
            else if (e.target.closest('.dd-it-giu') && r.nextElementSibling) boxIter.insertBefore(r.nextElementSibling, r);
            numeraIter();
        });
        function impostaIter(lista) {
            boxIter.innerHTML = '';
            (lista || []).forEach(function (x) {
                var d = typeof x === 'object' ? x : { t: 'ufficio', u: x, gg: 0 };
                if (!d.t) d = { t: 'ufficio', u: d.u, gg: d.gg || 0 };
                boxIter.appendChild(rigaIter({ t: d.t, u: d.u === undefined ? '' : d.u, campo: d.campo || '', gg: d.gg || 0 }));
            });
            numeraIter(); aggiornaCampiDocente();
        }

        // ── Anteprima: riepilogo di quello che si sta per salvare ──
        function riepilogo() {
            var h = function (t) { return String(t).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
            var campi = 0, uff = 0, doc = 0, avvisi = [];
            box.querySelectorAll('.dd-campo').forEach(function (r) {
                if (!r.querySelector('[name="c_etichetta[]"]').value.trim()) return;
                if (r.querySelector('.dd-uff').checked) uff++; else campi++;
                if (r.querySelector('.dd-tipo').value === 'docente') doc++;
            });
            var passi = [];
            boxIter.querySelectorAll('.dd-iter-riga').forEach(function (r) {
                var t = r.querySelector('.dd-it-tipo').value, gg = r.querySelector('.dd-it-gg').value, nome;
                if (t === 'ufficio') { var u = r.querySelector('.dd-it-uff'); nome = u.value ? u.options[u.selectedIndex].textContent : null; if (!nome) { avvisi.push('Un passo dell\'iter non ha l\'ufficio.'); nome = '(ufficio da scegliere)'; } }
                else if (t === 'persona') { nome = 'Firma: ' + (r.querySelector('.dd-it-campo').value || '(domanda da scegliere)'); if (!r.querySelector('.dd-it-campo').value) avvisi.push('Un passo di firma non indica la domanda con il nome del docente.'); }
                else nome = 'Firma del Direttore';
                passi.push(h(nome) + (gg ? ' <span class="text-secondary">(' + h(gg) + ' gg)</span>' : ''));
            });
            if (!document.getElementById('mTit').value.trim()) avvisi.push('Manca il titolo.');
            if (!campi) avvisi.push('Lo studente non ha nessuna domanda da compilare.');
            if (!passi.length) avvisi.push('Nessun passo nell\'iter: la pratica resta un solo passo generico.');
            var verb = inVerbale.checked ? 'sì, sotto «' + h(document.getElementById('vSez').value || document.getElementById('mTit').value) + '»' : 'no (si conclude dall\'ufficio)';
            document.getElementById('ddRiepilogo').innerHTML =
                '<div class="fw-bold fs-6 mb-1">' + h(document.getElementById('mTit').value || '(senza titolo)') + '</div>'
                + '<div>' + campi + ' domande per lo studente · ' + uff + ' campi dell\'ufficio</div>'
                + '<div class="mt-1"><strong>Iter:</strong> ' + (passi.length ? passi.join(' → ') : 'un solo passo') + '</div>'
                + '<div class="mt-1"><strong>Nel verbale del Consiglio:</strong> ' + verb + '</div>'
                + '<div class="mt-1"><strong>Domanda in PDF/A:</strong> ' + (document.querySelector('[name=d_pdf]').checked ? 'sì' : 'no') + (document.querySelector('[name=d_bollo]').checked ? ' · con marca da bollo' : '') + '</div>'
                + '<div class="mt-1"><strong>Promemoria:</strong> dopo ' + h(document.getElementById('mGg').value || 0) + ' giorni (salvo i giorni indicati nei singoli passi)</div>'
                + (avvisi.length ? '<div class="alert alert-warning py-2 mt-2 mb-0"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i>' + avvisi.map(h).join('<br>') + '</div>' : '<div class="text-success mt-2"><i class="fa fa-circle-check me-1" aria-hidden="true"></i>Tutto a posto: puoi salvare e pubblicare.</div>');
        }

        mostraParte('info');

        // ── Colonne delle tabelle: righe nome + tipo, scritte nelle opzioni come "Nome:tipo" ──
        function leggiColonne(testo) {
            return testo.split(/[,;\n]/).map(function (x) { return x.trim(); }).filter(Boolean).map(function (x) {
                var m = x.match(/^(.*?):\s*([a-z_]+)(?:\((.*)\))?\s*$/);
                if (m && TIPI_COL[m[2]]) return { nome: m[1].trim(), tipo: m[2], scelte: (m[3] || '').split('|').map(function (y) { return y.trim(); }).filter(Boolean).join(' | ') };
                return { nome: x, tipo: 'testo', scelte: '' };
            });
        }
        function rigaCol(c) {
            var d = document.createElement('div'); d.className = 'dd-col-riga';
            var o = Object.keys(TIPI_COL).map(function (k) { return '<option value="' + k + '"' + (k === c.tipo ? ' selected' : '') + '>' + TIPI_COL[k] + '</option>'; }).join('');
            d.innerHTML = '<input class="form-control form-control-sm dc-nome" placeholder="Nome della colonna" aria-label="Nome della colonna"><select class="form-select form-select-sm dc-tipo" aria-label="Tipo della colonna">' + o + '</select>'
                + '<input class="form-control form-control-sm dc-scelte" placeholder="Scelte separate da |" aria-label="Scelte della tendina"><button type="button" class="btn btn-sm btn-link text-danger p-0 dc-togli" aria-label="Togli la colonna"><i class="fa fa-times" aria-hidden="true"></i></button>';
            d.querySelector('.dc-nome').value = c.nome; d.querySelector('.dc-scelte').value = c.scelte || '';
            d.querySelector('.dc-scelte').hidden = c.tipo !== 'scelta';
            return d;
        }
        function disegnaColonne(r) {
            var cont = r.querySelector('.dd-col-righe'); cont.innerHTML = '';
            var cols = leggiColonne(r.querySelector('.dd-opz').value);
            if (!cols.length) cols = [{ nome: 'Insegnamento', tipo: 'insegnamento' }, { nome: 'CFU', tipo: 'cfu' }, { nome: 'Voto', tipo: 'voto' }];
            cols.forEach(function (c) { cont.appendChild(rigaCol(c)); });
            scriviColonne(r);
        }
        function scriviColonne(r) {
            var parti = [];
            r.querySelectorAll('.dd-col-riga').forEach(function (d) {
                var n = d.querySelector('.dc-nome').value.replace(/[,;:]/g, ' ').trim(), t = d.querySelector('.dc-tipo').value;
                d.querySelector('.dc-scelte').hidden = t !== 'scelta';
                if (!n) return;
                parti.push(n + (t !== 'testo' ? ':' + t + (t === 'scelta' ? '(' + d.querySelector('.dc-scelte').value.split('|').map(function (x) { return x.replace(/[,;()]/g, ' ').trim(); }).filter(Boolean).join('|') + ')' : '') : ''));
            });
            r.querySelector('.dd-opz').value = parti.join(', ');
        }
        // ── Logica: tendine con le domande degli altri campi ──
        function aggiornaRif() {
            var et = Array.prototype.map.call(box.querySelectorAll('[name="c_etichetta[]"]'), function (i) { return i.value.trim(); });
            box.querySelectorAll('.dd-campo').forEach(function (r) {
                var mia = r.querySelector('[name="c_etichetta[]"]').value.trim();
                r.querySelectorAll('.dd-rif').forEach(function (s) {
                    var v = s.dataset.val !== undefined ? s.dataset.val : s.value, primo = s.options[0].outerHTML;
                    s.innerHTML = primo + et.filter(function (x) { return x && x !== mia; }).map(function (x) { var o = document.createElement('option'); o.value = x; o.textContent = x; return o.outerHTML; }).join('');
                    if (v && !et.includes(v)) { var o = document.createElement('option'); o.value = v; o.textContent = v + ' (domanda non trovata)'; s.appendChild(o); }
                    s.value = v || ''; s.dataset.val = s.value;
                });
                var attiva = r.querySelector('[name="c_cond_campo[]"]').value || r.querySelector('[name="c_auto_campo[]"]').value;
                var b = r.querySelector('.dd-logica-btn'); b.classList.toggle('btn-warning', !!attiva); b.classList.toggle('btn-outline-secondary', !attiva);
            });
        }
        function preparaRiga(r) {
            var t = r.querySelector('.dd-tipo');
            r.querySelector('.dd-colonne').hidden = t.value !== 'tabella';
            if (t.value === 'tabella') disegnaColonne(r);
        }
        function nuovaRiga() {
            var n = box.lastElementChild.cloneNode(true);
            n.querySelectorAll('input[type=text], .dd-logica input').forEach(function (i) { i.value = ''; });
            n.querySelectorAll('input[type=hidden]').forEach(function (i) { i.value = '0'; });
            n.querySelectorAll('.dd-chk').forEach(function (i) { i.checked = false; });
            n.querySelectorAll('.dd-rif').forEach(function (s) { s.dataset.val = ''; });
            n.querySelector('.dd-col-righe').innerHTML = ''; n.querySelector('.dd-logica').hidden = true;
            n.classList.remove('uff'); n.querySelector('.dd-tipo').value = 'text';
            box.appendChild(n); preparaRiga(n); aggiornaRif(); return n;
        }
        box.querySelectorAll('.dd-campo').forEach(preparaRiga); aggiornaRif();
        document.getElementById('ddAggiungi').addEventListener('click', function () {
            var n = nuovaRiga();
            if (corrente === 'uffici') { n.querySelector('.dd-uff').checked = true; n.querySelector('[name="c_uff[]"]').value = '1'; n.classList.add('uff'); }
            n.style.display = ''; n.querySelector('input').focus();
        });
        box.addEventListener('click', function (e) {
            var b = e.target.closest('.dd-togli');
            if (b) { var r = b.closest('.dd-campo'); if (box.children.length > 1) r.remove(); else r.querySelectorAll('input[type=text]').forEach(function (i) { i.value = ''; }); aggiornaRif(); return; }
            var l = e.target.closest('.dd-logica-btn');
            if (l) { var p = l.closest('.dd-campo').querySelector('.dd-logica'); p.hidden = !p.hidden; aggiornaRif(); return; }
            var ca = e.target.closest('.dd-col-agg');
            if (ca) { var rr = ca.closest('.dd-campo'), nr = rigaCol({ nome: '', tipo: 'testo' }); rr.querySelector('.dd-col-righe').appendChild(nr); nr.querySelector('input').focus(); return; }
            var ct = e.target.closest('.dc-togli');
            if (ct) { var r2 = ct.closest('.dd-campo'); ct.closest('.dd-col-riga').remove(); scriviColonne(r2); }
        });
        box.addEventListener('input', function (e) {
            if (e.target.closest('.dd-col-riga')) scriviColonne(e.target.closest('.dd-campo'));
            if (e.target.classList.contains('dd-opz')) { var r = e.target.closest('.dd-campo'); if (r.querySelector('.dd-tipo').value === 'tabella' && !r.querySelector('.dd-logica').hidden) { var cont = r.querySelector('.dd-col-righe'); cont.innerHTML = ''; leggiColonne(e.target.value).forEach(function (c) { cont.appendChild(rigaCol(c)); }); } }
        });
        box.addEventListener('focusout', function (e) { if (e.target.name === 'c_etichetta[]') aggiornaRif(); });
        box.addEventListener('change', function (e) {
            if (e.target.classList.contains('dd-rif')) { e.target.dataset.val = e.target.value; aggiornaRif(); }
            if (e.target.closest('.dd-col-riga')) scriviColonne(e.target.closest('.dd-campo'));
            if (e.target.classList.contains('dd-tipo')) { var r = e.target.closest('.dd-campo'); preparaRiga(r); if (e.target.value === 'tabella') r.querySelector('.dd-logica').hidden = false; }
            if (!e.target.classList.contains('dd-chk')) return;
            e.target.closest('span').querySelector('input[type=hidden]').value = e.target.checked ? '1' : '0';
            if (e.target.classList.contains('dd-uff')) { e.target.closest('.dd-campo').classList.toggle('uff', e.target.checked); aggiornaCampiDocente(); mostraParte(corrente); }
        });
        document.querySelectorAll('.dd-modello').forEach(function (b) {
            b.addEventListener('click', function () {
                var m = modelli[b.dataset.modello]; if (!m) return;
                document.getElementById('mTit').value = m[0]; document.getElementById('mCat').value = m[1]; tipo.value = 'online';
                if (window.tinymce && tinymce.get('mDes')) tinymce.get('mDes').setContent('<p>' + m[2] + '</p>'); else document.getElementById('mDes').value = '<p>' + m[2] + '</p>';
                while (box.children.length > 1) box.lastElementChild.remove();
                m[3].forEach(function (c, i) {
                    var r = i === 0 ? box.firstElementChild : nuovaRiga();
                    r.querySelector('[name="c_etichetta[]"]').value = c[0]; r.querySelector('.dd-tipo').value = c[1]; r.querySelector('[name="c_opzioni[]"]').value = c[2];
                    var chk = r.querySelectorAll('.dd-chk'); chk[0].checked = !!c[3]; chk[1].checked = !!c[4];
                    r.querySelector('[name="c_obbl[]"]').value = c[3] ? '1' : '0'; r.querySelector('[name="c_uff[]"]').value = c[4] ? '1' : '0'; r.classList.toggle('uff', !!c[4]);
                    r.querySelector('[name="c_aiuto[]"]').value = c[6] || '';
                    var cs = r.querySelector('[name="c_cond_campo[]"]'); cs.dataset.val = c[5] ? c[5].campo : '';
                    r.querySelector('[name="c_cond_op[]"]').value = c[5] ? c[5].op : 'uguale'; r.querySelector('[name="c_cond_val[]"]').value = c[5] ? c[5].valore : '';
                    r.querySelector('[name="c_auto_campo[]"]').dataset.val = '';
                    r.querySelector('.dd-col-righe').innerHTML = ''; preparaRiga(r);
                });
                aggiornaRif();
                var v = m[4];
                document.getElementById('vSez').value = v.sezione || ''; stile.value = v.stile || 'scheda';
                document.getElementById('vIntro').value = v.intro || ''; document.getElementById('vTesto').value = v.testo || '';
                document.getElementById('vDel').value = v.delibera !== undefined ? v.delibera : 'Il Consiglio approva.';
                document.getElementById('vCol').value = v.colonne || ''; document.getElementById('vRag').value = v.raggruppa || ''; document.getElementById('vChi').value = v.chiusura || '';
                document.getElementById('vDec').value = v.decisione || '';
                impostaIter(v.iter || []);
                inVerbale.checked = v.in_verbale === undefined ? true : !!v.in_verbale; aggiornaVerbale();
                document.querySelector('[name=d_pdf]').checked = true; document.querySelector('[name=d_bollo]').checked = !!v.bollo;
                aggiorna(); mostraParte(corrente); document.getElementById('mTit').focus();
            });
        });
    })();
    </script>
    <?php else: ?>
        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
            <a href="<?php echo $base; ?>&amp;tab=moduli&amp;nuovo=1" class="btn btn-primary fw-bold"><i class="fa fa-plus-circle me-1" aria-hidden="true"></i>Nuovo modulo o documento</a>
            <span class="small text-secondary">Un <strong>documento</strong> si scarica; un <strong>modulo online</strong> lo compila lo studente e apre una pratica.</span>
        </div>
    <?php endif; ?>

    <?php if (!$moduli): ?>
        <div class="card border-0 shadow-sm"><?php echo pn_vuoto("Nessun modulo: crea il primo documento da scaricare o il primo modulo online (anche da un modello pronto: tesi, passaggi di corso, attività all'estero).", 'fa-file-lines'); ?></div>
    <?php endif; ?>
    <?php foreach ($categorie as $cat): ?>
        <h6 class="fw-bold text-secondary text-uppercase mt-3 mb-2" style="font-size:.75rem;letter-spacing:.05em;"><?php echo $h($cat); ?></h6>
        <div class="card border-0 shadow-sm"><ul class="list-group list-group-flush small">
        <?php foreach ($moduli as $m): if ($m['categoria'] !== $cat) continue; $cm = campi_modulo($m['campi_json']); ?>
            <li class="list-group-item d-flex flex-wrap align-items-center gap-2">
                <strong><?php echo $h($m['titolo']); ?></strong>
                <?php echo $m['tipo'] === 'online' ? pn_stato('Modulo online', 'ok', 'fa-pen-to-square') : pn_stato('Documento', 'info', 'fa-file-arrow-down'); ?>
                <span class="text-secondary"><?php echo $m['tipo'] === 'online' ? count(campi_studente($cm)) . ' campi' . (count(campi_ufficio($cm)) ? ' + ' . count(campi_ufficio($cm)) . ' dell\'ufficio' : '') . ' · ' . (int)$m['n_pratiche'] . ' pratiche' . ($m['n_aperte'] ? ' (' . (int)$m['n_aperte'] . ' aperte)' : '') : ($m['file_path'] ? 'Documento' : ($m['link'] ? 'Link' : 'Documento senza file')); ?></span>
                <?php if (!(int)$m['attivo']): ?><?php echo pn_stato('Non pubblicato', 'spento', 'fa-eye-slash'); ?><?php endif; ?>
                <span class="ms-auto d-flex gap-1">
                    <?php if ($m['tipo'] === 'online' && $m['n_pratiche']): ?><a class="btn btn-sm btn-outline-dark py-0" href="<?php echo $base; ?>&amp;tab=pratiche&amp;modulo=<?php echo (int)$m['id']; ?>&amp;stato=tutte">Pratiche</a><?php endif; ?>
                    <?php if ($m['tipo'] === 'online'): ?><a class="btn btn-sm btn-outline-secondary py-0" href="../modulo.php?id=<?php echo (int)$m['id']; ?>&amp;anteprima=1" target="_blank" rel="noopener">Anteprima</a><?php endif; ?>
                    <a class="btn btn-sm btn-outline-primary py-0" href="<?php echo $base; ?>&amp;tab=moduli&amp;modifica=<?php echo (int)$m['id']; ?>">Modifica</a>
                    <form method="POST" class="m-0"><?php csrf_field(); ?><button type="submit" name="duplica_modulo" value="<?php echo (int)$m['id']; ?>" class="btn btn-sm btn-outline-secondary py-0" title="Crea una copia non pubblicata da ritoccare"><i class="fa fa-copy me-1" aria-hidden="true"></i>Copia</button></form>
                    <form method="POST" class="m-0"><?php csrf_field(); ?><button type="submit" name="elimina_modulo" value="<?php echo (int)$m['id']; ?>" class="btn btn-sm btn-outline-danger py-0" data-confirm="Eliminare il modulo? Se ha pratiche viene solo nascosto." aria-label="Elimina"><i class="fa fa-trash" aria-hidden="true"></i></button></form>
                </span>
            </li>
        <?php endforeach; ?>
        </ul></div>
    <?php endforeach; ?>
