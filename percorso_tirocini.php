<?php
// percorso_tirocini.php - Il tirocinio curriculare a passi: requisiti, ricerca dell'azienda o del laboratorio, avvio e crediti.
require_once 'config.php';
require_once 'functions.php';
require_once 'inc/percorso_vista.php';

$page_cfg['titolo'] = 'Tirocinio: i passi';
require_once 'header.php';
percorso_vista([
    'titolo' => 'Tirocinio curriculare: i passi',
    'intro' => 'Come trovare dove fare il tirocinio, avviarlo e farti riconoscere i crediti.',
    'argomento' => 'tirocini',
    'passi' => [
        ['Controlla i requisiti del tuo corso', 'Quanti crediti (CFU), in quali periodi e con quale tutor dipendono dal tuo corso di studi: li trovi nell\'ordinamento e nella pagina del corso.', [['I corsi di studio', 'corsi.php', false]], ''],
        ['Cerca dove farlo', 'Nella pagina «Tirocini» trovi le aziende e gli enti con convenzione attiva (puoi cercare per regione, provincia, comune e settore) e i laboratori interni dell\'Ateneo.', [['Cerca aziende e laboratori', 'tirocini.php', false]], 'Serve l\'accesso con le credenziali Unical.'],
        ['Se l\'azienda non è nell\'elenco', 'Scrivi all\'Ufficio Tirocini indicando l\'azienda: verificano se è già convenzionata o come attivare la convenzione.', [['Chi contattare', 'contatti.php', false]], ''],
        ['Avvia il tirocinio', 'Concordato con l\'azienda e con il tutor, l\'avvio si fa sulla piattaforma di Ateneo con il progetto formativo. Per dubbi sul tuo caso parla con un operatore.', [['Domande frequenti sui tirocini', 'domande_frequenti.php?argomento=tirocini', false], ['Parla con l\'assistenza', 'assistenza.php', false]], ''],
        ['Fine tirocinio e crediti', 'Al termine si chiude il tirocinio e si registrano i crediti: per la procedura e i documenti rivolgiti all\'Ufficio Tirocini.', [['Chi contattare', 'contatti.php', false]], ''],
    ],
]);
require_once 'footer.php';
