<?php
// percorso_tesi.php - Tesi e laurea a passi: dalla scelta del relatore alla domanda di laurea, con i pulsanti per le pagine del portale.
require_once 'config.php';
require_once 'functions.php';
require_once 'inc/percorso_vista.php';

$page_cfg['titolo'] = 'Tesi e laurea: i passi';
require_once 'header.php';
percorso_vista([
    'titolo' => 'Tesi e laurea: i passi',
    'intro' => 'Dalla scelta dell\'argomento alla discussione: cosa fare, in che ordine e dove.',
    'argomento' => 'tesi',
    'passi' => [
        ['Scegli l\'argomento e il relatore', 'Parla con un docente del tuo corso di studi: ti aiuta a definire l\'argomento e accetta di seguirti come relatore. Puoi chiedere un appuntamento nel suo orario di ricevimento.', [['Cerca un docente e prenota il ricevimento', 'docenti.php', false]], ''],
        ['Invia la richiesta di tesi', 'La richiesta è una pratica online: dalla Modulistica scegli il modulo, indica relatore e argomento (e, se la tesi si svolge all\'esterno, l\'azienda o l\'ente) e invia.', [['Modulistica', 'modulistica.php', false], ['Le mie pratiche', 'pratiche.php', false]], 'Serve l\'accesso con le credenziali Unical.'],
        ['Il relatore conferma', 'Il relatore riceve un link personale per confermare la sua disponibilità; poi la pratica prosegue con l\'ufficio e il Direttore. Segui lo stato da «Le mie pratiche».', [['Le mie pratiche', 'pratiche.php', false]], ''],
        ['Se fai la tesi in azienda o in un ente', 'Serve una convenzione con il Dipartimento. Se l\'azienda è già convenzionata la trovi nell\'elenco; altrimenti la convenzione si richiede online e l\'azienda compila i dati con un link personale, senza registrazione.', [['Aziende già convenzionate', 'aziende_convenzionate_tesi.php', false], ['Richiedi una convenzione', 'richiedi_convenzione_tesi.php', false]], ''],
        ['Domanda di laurea e scadenze', 'Per la domanda di laurea, le scadenze e la consegna dell\'elaborato rivolgiti alla segreteria didattica o guarda le domande frequenti.', [['Chi contattare', 'contatti.php', false], ['Domande frequenti sulla tesi', 'domande_frequenti.php?argomento=tesi', false]], ''],
    ],
]);
require_once 'footer.php';
