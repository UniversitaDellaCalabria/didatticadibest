<?php
// Tessera «benvenuto» del cruscotto dell'area personale: la include area_personale.php. Al primo accesso, a chi non rientra in nessun profilo (esterno), chiede chi è
// e porta al percorso giusto: scuola (Formazione Scuola Lavoro), azienda o ente (convenzione di tesi), studente di un altro Ateneo (eventi e attività aperte).
area_tessera('fa-hand-peace', '#0369a1', 'Benvenuto: chi sei?', 'Scegli come vuoi usare il portale: la tua area personale si adatta.', 'sono_una_scuola.php', 0, '', [
    ['Sono una scuola', 'sono_una_scuola.php', false, true],
    ['Sono un\'azienda o un ente', 'richiedi_convenzione_tesi.php', false, false],
    ['Sono uno studente di un altro Ateneo', 'index.php', false, false],
]);
