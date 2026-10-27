<?php
// percorso_tirocini.php - Il tirocinio curriculare a passi: requisiti, ricerca dell'azienda o del laboratorio, avvio e crediti.
require_once 'config.php';
require_once 'functions.php';
require_once 'inc/percorso_vista.php';

$page_cfg['titolo'] = 'Tirocinio: i passi';
require_once 'header.php';
percorso_vista([
    'titolo' => 'Tirocinio curriculare: i passi',
    'intro' => 'Come trovare dove fare il tirocinio, avviarlo e farti riconoscere i crediti. Le pratiche del tirocinio (progetto formativo, avvio, registro, chiusura) non si fanno in questo portale ma sulla piattaforma TSP 2.0 di Ateneo.',
    'argomento' => 'tirocini',
    'passi' => [
        ['Controlla i requisiti del tuo corso', 'Quanti crediti (CFU), in quali periodi e con quale tutor dipendono dal tuo corso di studi: li trovi nell\'ordinamento e nella pagina del corso.', [['I corsi di studio', 'corsi.php', false]], ''],
        ['Cerca dove farlo', 'Nella pagina «Tirocini» trovi le aziende e gli enti con convenzione attiva (puoi cercare per regione, provincia, comune e settore) e i laboratori interni dell\'Ateneo.', [['Cerca aziende e laboratori', 'tirocini.php', false]], 'Serve l\'accesso con le credenziali Unical.'],
        ['Se l\'azienda non è nell\'elenco', 'Scrivi all\'Ufficio Tirocini indicando l\'azienda: verificano se è già convenzionata o come attivare la convenzione.', [['Chi contattare', 'contatti.php', false]], ''],
        ['Avvia il tirocinio', 'Concordato con l\'azienda e con il tutor, l\'avvio si fa sulla piattaforma TSP 2.0 di Ateneo con il progetto formativo: accedi con le credenziali Unical e segui la procedura guidata. Per dubbi sul tuo caso parla con un operatore.', [['Vai alla piattaforma TSP 2.0', 'https://unical.tsp.esse3.cineca.it', true], ['Domande frequenti sui tirocini', 'domande_frequenti.php?argomento=tirocini', false], ['Parla con l\'assistenza', 'assistenza.php', false]], 'Il registro delle attività e la relazione finale si compilano sulla stessa piattaforma.'],
        ['Fine tirocinio e crediti', 'Al termine si chiude il tirocinio sulla piattaforma TSP 2.0 e si registrano i crediti: per la procedura e i documenti rivolgiti all\'Ufficio Tirocini.', [['Vai alla piattaforma TSP 2.0', 'https://unical.tsp.esse3.cineca.it', true], ['Chi contattare', 'contatti.php', false]], ''],
    ],
]);
require_once 'footer.php';
