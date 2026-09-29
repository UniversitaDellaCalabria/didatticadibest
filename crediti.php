<?php
// crediti.php - Credits del portale: diritti sui contenuti, realizzazione, software open source utilizzato e contatti.
// Nome di chi ha realizzato il portale, assistenza e dati del dipartimento arrivano da Testata (configurazione_portale).
require_once 'config.php';
require_once 'functions.php';

$cfg_cr = function_exists('get_configurazione_portale') ? get_configurazione_portale($conn) : [];
$cr_portale    = !empty($cfg_cr['nome_portale']) ? $cfg_cr['nome_portale'] : 'Eventi DiBEST';
$cr_realizzato = !empty($cfg_cr['footer_realizzato_da']) ? $cfg_cr['footer_realizzato_da'] : 'Realizzato per il Dipartimento da Emanuele Dodaro';
$cr_assistenza = !empty($cfg_cr['footer_assistenza']) ? $cfg_cr['footer_assistenza'] : 'emanuele.dodaro@unical.it';
$cr_dip        = !empty($cfg_cr['footer_nome_dipartimento']) ? $cfg_cr['footer_nome_dipartimento'] : 'DiBEST - Dipartimento di Biologia, Ecologia e Scienze della Terra (UNICAL)';
$cr_contatti   = !empty($cfg_cr['footer_contatti']) ? $cfg_cr['footer_contatti'] : '';
$cr_email_ass  = filter_var(trim($cr_assistenza), FILTER_VALIDATE_EMAIL) ? trim($cr_assistenza) : '';

// Software di terze parti incluso nel portale (servito dai server dell'Ateneo), con la sua licenza
$software = [
    ['Bootstrap Italia', 'https://italia.github.io/bootstrap-italia/', 'BSD-3-Clause', 'Interfaccia secondo le linee guida di design dei siti della PA'],
    ['Bootstrap', 'https://getbootstrap.com/', 'MIT', 'Struttura grafica del pannello di gestione'],
    ['Font Awesome Free', 'https://fontawesome.com/', 'CC BY 4.0 / SIL OFL 1.1 / MIT', 'Icone'],
    ['Titillium Web, Lora, Dancing Script', 'https://fonts.google.com/', 'SIL Open Font License 1.1', 'Caratteri tipografici'],
    ['jQuery', 'https://jquery.com/', 'MIT', 'Supporto agli script del pannello'],
    ['DataTables', 'https://datatables.net/', 'MIT', 'Tabelle con ricerca e ordinamento'],
    ['Select2', 'https://select2.org/', 'MIT', 'Menu a tendina con ricerca'],
    ['SortableJS', 'https://sortablejs.github.io/Sortable/', 'MIT', 'Ordinamento con trascinamento'],
    ['Chart.js', 'https://www.chartjs.org/', 'MIT', 'Grafici delle statistiche'],
    ['FullCalendar', 'https://fullcalendar.io/', 'MIT', 'Calendario delle attività'],
    ['TinyMCE', 'https://www.tiny.cloud/', 'MIT', 'Editor dei testi'],
    ['qrcode-generator', 'https://github.com/kazuhikoarase/qrcode-generator', 'MIT', 'Codici QR di ricevute, badge e attestati'],
    ['html5-qrcode', 'https://github.com/mebjas/html5-qrcode', 'Apache 2.0', 'Lettura dei QR per il check-in'],
    ['html2canvas, jsPDF, JSZip', 'https://github.com/parallax/jsPDF', 'MIT', 'Attestati in PDF e archivio ZIP'],
    ['SimpleSAMLphp', 'https://simplesamlphp.org/', 'LGPL 2.1', 'Accesso con SPID, CIE e credenziali di Ateneo'],
];

$page_cfg['titolo'] = "Credits";
require_once 'header.php';
?>
<style>
.cr h2 { font-size: 1.3rem; font-weight: 700; margin-top: 2rem; margin-bottom: .75rem; }
.cr p, .cr li { line-height: 1.65; }
.cr-lato { border-left: 3px solid #990000; padding-left: 1rem; }
</style>
<div class="container my-4 cr" style="max-width: 1100px;">
    <nav aria-label="Percorso" class="mb-3 small"><a href="index.php">Home</a> <span class="text-secondary mx-1">/</span> <span class="text-secondary">Credits</span></nav>
    <div class="row g-5">
        <div class="col-lg-8">
            <h1 class="fw-bold mb-3">Credits</h1>

            <h2 class="mt-0">Copyright</h2>
            <p>Dove non diversamente specificato, i contenuti del portale <strong><?php echo htmlspecialchars($cr_portale); ?></strong> (testi, immagini e locandine) sono di proprietà dell'Università della Calabria. Loghi e marchi appartengono ai rispettivi titolari e sono tutelati da proprietà esclusiva.</p>
            <p>I contenuti delle singole attività sono curati dai gestori e dai referenti indicati nelle pagine. L'Università della Calabria si riserva il diritto di modificare le informazioni in qualsiasi momento e senza preavviso, ferma restando la massima cura nel verificarne la correttezza.</p>
            <p>Tutti i diritti sul portale sono riservati. Per le condizioni d'uso generali valgono le <a href="https://www.unical.it/note-legali/" target="_blank" rel="noopener">note legali dell'Ateneo</a>.</p>

            <h2>Il portale</h2>
            <p>Sistema di prenotazione e gestione degli eventi, dei laboratori e dei progetti del <?php echo htmlspecialchars($cr_dip); ?>.</p>
            <p><strong><?php echo htmlspecialchars($cr_realizzato); ?></strong>. Il portale è sviluppato in PHP e MySQL, è ospitato sui server dell'Ateneo e usa l'accesso di Ateneo (SPID, CIE e credenziali Unical).</p>

            <h2>Software open source</h2>
            <p>Il portale utilizza queste librerie libere, che ringraziamo. Sono servite dai server dell'Ateneo: aprendo le pagine il browser non si collega a servizi di terzi.</p>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead class="table-light"><tr><th>Software</th><th>A cosa serve</th><th>Licenza</th></tr></thead>
                    <tbody>
                    <?php foreach ($software as [$nome_sw, $url_sw, $lic_sw, $uso_sw]): ?>
                        <tr>
                            <td><a href="<?php echo htmlspecialchars($url_sw); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($nome_sw); ?></a></td>
                            <td><?php echo htmlspecialchars($uso_sw); ?></td>
                            <td class="small text-nowrap"><?php echo htmlspecialchars($lic_sw); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <aside class="col-lg-4">
            <div class="cr-lato">
                <h2 class="h6 fw-bold mt-lg-5"><i class="fa fa-circle-info me-1" aria-hidden="true"></i>Portale</h2>
                <?php if ($cr_email_ass !== ''): ?>
                    <p class="mb-2"><i class="fa fa-envelope me-2 text-secondary" aria-hidden="true"></i>Assistenza: <a href="mailto:<?php echo htmlspecialchars($cr_email_ass); ?>"><?php echo htmlspecialchars($cr_email_ass); ?></a></p>
                <?php else: ?>
                    <p class="mb-2"><i class="fa fa-envelope me-2 text-secondary" aria-hidden="true"></i>Assistenza: <?php echo htmlspecialchars($cr_assistenza); ?></p>
                <?php endif; ?>
                <p class="mb-2 small"><?php echo htmlspecialchars($cr_dip); ?></p>
                <?php if ($cr_contatti !== ''): ?><p class="mb-2 small text-secondary"><?php echo htmlspecialchars($cr_contatti); ?></p><?php endif; ?>
                <p class="mb-0 small"><a href="privacy.php">Privacy e cookie</a></p>
            </div>
        </aside>
    </div>
</div>
<?php require_once 'footer.php'; ?>
