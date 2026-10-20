<?php
// email.php - Testi delle email del portale (Gestione del portale → Testi delle email): oggetto, testo e pulsante di ogni email che il sistema invia,
// modificabili senza toccare il codice. Si vede quando parte e a chi arriva, si scrive con i segnaposto ({{tutor}}, {{bando}}…) che il portale riempie da solo,
// si guarda l'anteprima con dati d'esempio, ci si manda una prova e si può tornare al testo originale. Le modifiche si salvano in email_modelli e restano nel registro attività.
// Chi può modificare: gli amministratori tutte le email; chi gestisce un modulo (per ora il tutorato) quelle del proprio modulo.
require_once 'admin_header.php';

use App\Email\CatalogoEmail;
use App\Email\ModelliEmail;

if (!$is_full_admin && !$puo_tutorato && !$puo_didattica_tutto && !$puo_fsl_convenzioni && !$puo_conv_tesi) nega_accesso();
function admin_redirect($url) { echo "<script>window.location.replace(" . json_encode($url) . ");</script>"; exit; }
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$app_e = \App\Core\App::per($conn);
$modelli = $app_e->get(ModelliEmail::class);
$autore = trim(($utente_admin['nome'] ?? '') . ' ' . ($utente_admin['cognome'] ?? ''));
$url = 'email.php?p_id=' . (int)$filtro_p;

// Chi può modificare quale gruppo: l'amministratore tutti; chi lavora in Didattica i gruppi «Didattica · …», chi gestisce il tutorato quelli «Tutorato · …», chi segue le convenzioni quelli «Convenzioni FSL · …» e «Convenzioni di tesi · …»
$puo_gruppo = fn(string $g): bool => $is_full_admin || ($puo_didattica_tutto && str_starts_with($g, 'Didattica')) || ($puo_tutorato && str_starts_with($g, 'Tutorato'))
    || ($puo_fsl_convenzioni && str_starts_with($g, 'Convenzioni FSL')) || ($puo_conv_tesi && str_starts_with($g, 'Convenzioni di tesi'));
$visibili = array_filter($modelli->catalogo(), fn($m) => $puo_gruppo((string)$m['gruppo']));
// ?gruppo=Didattica (dal pulsante dei moduli) mostra solo i gruppi che iniziano così
$solo_gruppo = trim((string)($_GET['gruppo'] ?? ''));
$lista_visibile = $solo_gruppo === '' ? $visibili : array_filter($visibili, fn($m) => str_starts_with((string)$m['gruppo'], $solo_gruppo));

$chiave = (string)($_GET['k'] ?? $_POST['chiave'] ?? '');
$m = $chiave !== '' ? ($visibili[$chiave] ?? null) : null;
if ($chiave !== '' && !$m) nega_accesso();
$html = $m && ($m['formato'] ?? 'testo') === 'html';       // testo scritto con l'editor, segnaposto {NOME}
$facoltativa = $m && !empty($m['facoltativa']);       // senza testo (o senza la spunta) l'email non parte
$anteprima = null;
$errori = [];
$oggetto = $corpo = $bottone = '';

if ($m) {
    $att = $modelli->modello($chiave);
    $oggetto = (string)$att['oggetto'];
    $corpo = (string)$att['corpo'];
    $bottone = (string)($att['bottone'] ?? '');
    $attiva = (bool)$att['attiva'];
}

if ($m && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $oggetto = (string)($_POST['oggetto'] ?? '');
    $corpo = str_replace("\r\n", "\n", (string)($_POST['corpo'] ?? ''));
    $bottone = (string)($_POST['bottone'] ?? '');
    $attiva = !$facoltativa || isset($_POST['attiva']);
    if (isset($_POST['ripristina'])) {
        $modelli->ripristina($chiave);
        registra_log_audit($conn, 'Testo di un\'email ripristinato', ['Email' => $m['nome'], 'Chiave' => $chiave]);
        flash_set('Testo originale ripristinato.', 'success');
        admin_redirect($url . '&k=' . urlencode($chiave));
    }
    $errori = $modelli->controlla($chiave, $oggetto, $corpo, $bottone);
    if ($facoltativa && $attiva && trim($corpo) === '') $errori[] = 'Scrivi il testo, oppure togli la spunta «Invia questa email».';
    if (!$errori && isset($_POST['salva'])) {
        $errori = $modelli->salva($chiave, $oggetto, $corpo, $bottone, $autore, $attiva);
        if (!$errori) {
            registra_log_audit($conn, 'Testo di un\'email modificato', ['Email' => $m['nome'], 'Chiave' => $chiave, 'Oggetto' => $oggetto]);
            flash_set('Testo salvato: da ora le email partono così.', 'success');
            admin_redirect($url . '&k=' . urlencode($chiave));
        }
    } elseif (!$errori && (isset($_POST['anteprima']) || isset($_POST['prova']))) {
        if ($html) {
            $ph = $modelli->componiHtml(trim($oggetto), ModelliEmail::ripulisciHtml($corpo), $modelli->datiDEsempio($chiave));
            $anteprima = ['oggetto' => $ph['oggetto'], 'corpo' => $ph['corpo'], 'bottone' => null];
        } else {
            $anteprima = $modelli->componi(trim($oggetto), $corpo, $m['bottone'] === null ? null : $bottone, $modelli->datiDEsempio($chiave));
        }
        if (isset($_POST['prova'])) {
            $a = trim((string)($utente_admin['email'] ?? ''));
            $link = $app_e->get(\App\Core\Sito::class)->urlBase() . '/';
            $col = \App\Email\InvioEmail::coloreDi($m);
            $html = $anteprima['corpo'] . ($anteprima['bottone'] !== null ? "<p style='margin-top:18px;'><a href='" . $h($link) . "' style='background:" . ($col ?? '#B80000') . ";color:#fff;padding:10px 18px;text-decoration:none;border-radius:6px;font-weight:bold;'>" . $h($anteprima['bottone']) . "</a></p>" : '');
            if ($a === '') flash_set('Il tuo profilo non ha un indirizzo email: non posso inviare la prova.', 'danger');
            elseif ($app_e->get(\App\Infrastructure\Mail\Mailer::class)->invia($a, '[PROVA] ' . $anteprima['oggetto'], $html, $col)) flash_set('Prova inviata a ' . $a . ' (con dati d\'esempio, il pulsante porta alla home).', 'success');
            else flash_set('La prova non è partita: controlla le impostazioni di posta in Sistema.', 'danger');
            registra_log_audit($conn, 'Prova di un\'email', ['Email' => $m['nome'], 'A' => $a]);
            admin_redirect($url . '&k=' . urlencode($chiave));
        }
    }
}

$lista_modificate = [];
foreach ($lista_visibile as $k => $x) {
    $mm = $modelli->modello($k);
    $lista_modificate[$k] = $mm;
}
?>
<?php echo pn_testata('Testi delle email', 'fa-envelope-open-text', 'Qui cambi oggetto e testo delle email che il portale invia, senza toccare il codice. Per ogni email vedi quando parte e a chi arriva; i dati (nome, bando, date…) si inseriscono con i segnaposto tra doppie graffe. Puoi guardare l\'anteprima, farti inviare una prova e tornare al testo originale.'); ?>

<?php if (!$m): ?>
    <?php if ($solo_gruppo !== ''): ?><p class="small text-secondary mb-2">Stai vedendo solo le email «<?php echo $h($solo_gruppo); ?>…». <a href="<?php echo $h($url); ?>">Mostra tutte</a></p><?php endif; ?>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body py-2">
        <label for="cerca-email" class="visually-hidden">Cerca un'email</label>
        <input type="search" id="cerca-email" class="form-control" placeholder="Cerca un'email: scrivi una parola del nome, di chi la riceve o di quando parte…" autocomplete="off">
    </div></div>
    <?php
    // Una scheda per modulo (la prima parte del gruppo, prima di « · »); dentro, le sezioni come intestazioni
    $per_modulo = [];
    foreach ($lista_modificate as $k => $x) {
        [$mod_x] = explode(' · ', (string)$x['gruppo'], 2);
        $per_modulo[$mod_x][(string)$x['gruppo']][$k] = $x;
    }
    $scheda_get = trim((string)($_GET['scheda'] ?? ''));
    $scelta = isset($per_modulo[$scheda_get]) ? $scheda_get : (array_key_first($per_modulo) ?? '');
    $slug = fn(string $t): string => 'sc-' . trim((string)preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($t)), '-');
    ?>
    <?php if ($per_modulo): ?>
    <ul class="nav nav-tabs mb-3 flex-nowrap overflow-auto" id="schede-email" role="tablist" aria-label="Moduli">
        <?php foreach ($per_modulo as $mod_n => $sezioni):
            $tot_m = 0; $mod_m = 0;
            foreach ($sezioni as $righe_s) foreach ($righe_s as $x) { $tot_m++; if ($x['modificato']) $mod_m++; } ?>
            <li class="nav-item" role="presentation">
                <button type="button" class="nav-link text-nowrap<?php echo $mod_n === $scelta ? ' active' : ''; ?>" id="tab-<?php echo $h($slug($mod_n)); ?>" data-bs-toggle="tab" data-bs-target="#<?php echo $h($slug($mod_n)); ?>" data-modulo="<?php echo $h($mod_n); ?>" role="tab" aria-controls="<?php echo $h($slug($mod_n)); ?>" aria-selected="<?php echo $mod_n === $scelta ? 'true' : 'false'; ?>">
                    <?php echo $h($mod_n); ?> <span class="badge bg-light text-secondary border ms-1"><?php echo $tot_m; ?></span><?php if ($mod_m): ?><span class="badge bg-warning text-dark ms-1" title="Email con il testo modificato"><?php echo $mod_m; ?></span><?php endif; ?>
                </button>
            </li>
        <?php endforeach; ?>
    </ul>
    <p id="nota-ricerca" class="small text-secondary mb-2" style="display:none;"><i class="fa fa-magnifying-glass me-1" aria-hidden="true"></i>Risultati della ricerca in tutti i moduli.</p>
    <div class="tab-content" id="contenuto-email">
        <?php foreach ($per_modulo as $mod_n => $sezioni): ?>
            <div class="tab-pane fade<?php echo $mod_n === $scelta ? ' show active' : ''; ?>" id="<?php echo $h($slug($mod_n)); ?>" role="tabpanel" aria-labelledby="tab-<?php echo $h($slug($mod_n)); ?>" tabindex="0">
                <?php foreach ($sezioni as $gruppo => $mm):
                    $titolo_s = str_contains($gruppo, ' · ') ? explode(' · ', $gruppo, 2)[1] : $gruppo; ?>
                    <div class="card border-0 shadow-sm mb-3 gruppo-email">
                        <div class="card-header bg-white fw-bold"><span class="modulo-in-ricerca text-secondary fw-normal" style="display:none;"><?php echo $h($mod_n); ?> · </span><?php echo $h($titolo_s); ?> <span class="badge bg-light text-secondary border ms-1"><?php echo count($mm); ?></span></div>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($mm as $k => $x): $vai = $url . '&k=' . urlencode($k) . '&scheda=' . urlencode($mod_n) . ($solo_gruppo !== '' ? '&gruppo=' . urlencode($solo_gruppo) : ''); ?>
                                <li class="list-group-item riga-email" data-testo="<?php echo $h(mb_strtolower($x['nome'] . ' ' . $x['quando'] . ' ' . $x['destinatario'] . ' ' . $x['oggetto'] . ' ' . $gruppo)); ?>">
                                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                        <div style="min-width:0;flex:1 1 360px;">
                                            <a class="fw-bold text-decoration-none" href="<?php echo $h($vai); ?>"><?php echo $h($x['nome']); ?></a>
                                            <?php echo $x['modificato'] ? '<span class="badge bg-warning text-dark ms-1">modificata</span>' : '<span class="badge bg-light text-secondary border ms-1">originale</span>'; ?><?php echo !$x['attiva'] ? '<span class="badge bg-secondary ms-1">non attiva: non parte</span>' : ''; ?>
                                            <div class="small text-secondary"><i class="fa fa-clock me-1" aria-hidden="true"></i><?php echo $h($x['quando']); ?></div>
                                            <div class="small text-secondary"><i class="fa fa-user me-1" aria-hidden="true"></i><?php echo $h($x['destinatario']); ?></div>
                                            <div class="small mt-1"><span class="text-secondary">Oggetto:</span> <?php echo $h($x['oggetto']); ?></div>
                                            <?php if ($x['modificato'] && $x['aggiornato_il']): ?><div class="small text-secondary">Ultima modifica: <?php echo $h(date('d/m/Y H:i', strtotime($x['aggiornato_il']))); ?><?php echo $x['aggiornato_da'] !== '' ? ' · ' . $h($x['aggiornato_da']) : ''; ?></div><?php endif; ?>
                                        </div>
                                        <a class="btn btn-sm btn-outline-primary" href="<?php echo $h($vai); ?>"><i class="fa fa-pen me-1" aria-hidden="true"></i>Modifica</a>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?><p class="text-secondary">Nessuna email disponibile per te in questa sezione.</p><?php endif; ?>
    <p id="nessuna-email" class="text-secondary small" style="display:none;">Nessuna email corrisponde alla ricerca.</p>
    <style>#contenuto-email.cerca-attiva > .tab-pane { display: block !important; opacity: 1 !important; }</style>
    <script>
    (function () {
        var campo = document.getElementById('cerca-email'), cont = document.getElementById('contenuto-email'), nav = document.getElementById('schede-email');
        if (!campo || !cont) return;
        campo.addEventListener('input', function () {
            var q = campo.value.trim().toLowerCase(), visibili = 0, cerca = q !== '';
            cont.classList.toggle('cerca-attiva', cerca);
            if (nav) nav.style.display = cerca ? 'none' : '';
            document.getElementById('nota-ricerca').style.display = cerca ? '' : 'none';
            document.querySelectorAll('.modulo-in-ricerca').forEach(function (m) { m.style.display = cerca ? '' : 'none'; });
            document.querySelectorAll('.riga-email').forEach(function (r) {
                var ok = !cerca || r.getAttribute('data-testo').indexOf(q) !== -1;
                r.style.display = ok ? '' : 'none';
                if (ok) visibili++;
            });
            document.querySelectorAll('.gruppo-email').forEach(function (g) {
                g.style.display = g.querySelector('.riga-email:not([style*="none"])') ? '' : 'none';
            });
            document.getElementById('nessuna-email').style.display = visibili ? 'none' : '';
        });
        // la scheda aperta resta nell'indirizzo: tornando dall'editor si ritrova dov'era
        document.querySelectorAll('#schede-email [data-bs-toggle="tab"]').forEach(function (b) {
            b.addEventListener('shown.bs.tab', function () {
                try {
                    var u = new URL(window.location.href);
                    u.searchParams.set('scheda', b.getAttribute('data-modulo'));
                    window.history.replaceState(null, '', u.toString());
                } catch (e) {}
            });
        });
    })();
    </script>
<?php else: ?>
    <p class="mb-2"><a href="<?php echo $h($url . ($solo_gruppo !== '' ? '&gruppo=' . urlencode($solo_gruppo) : '') . '&scheda=' . urlencode(explode(' · ', (string)$m['gruppo'], 2)[0])); ?>" class="text-decoration-none"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Tutte le email</a></p>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <h2 class="h5 fw-bold mb-1"><?php echo $h($m['nome']); ?></h2>
        <div class="small text-secondary"><span class="badge bg-light text-secondary border"><?php echo $h($m['gruppo']); ?></span></div>
        <div class="small mt-2"><i class="fa fa-clock me-1 text-secondary" aria-hidden="true"></i><strong>Quando parte:</strong> <?php echo $h($m['quando']); ?></div>
        <div class="small"><i class="fa fa-user me-1 text-secondary" aria-hidden="true"></i><strong>A chi arriva:</strong> <?php echo $h($m['destinatario']); ?></div>
        <?php if ($att['modificato'] && $att['aggiornato_il']): ?><div class="small text-secondary mt-1"><i class="fa fa-pen me-1" aria-hidden="true"></i>Modificata il <?php echo $h(date('d/m/Y H:i', strtotime($att['aggiornato_il']))); ?><?php echo $att['aggiornato_da'] !== '' ? ' da ' . $h($att['aggiornato_da']) : ''; ?></div><?php endif; ?>
    </div></div>

    <?php foreach ($errori as $e): ?><div class="alert alert-danger py-2"><?php echo $h($e); ?></div><?php endforeach; ?>

    <div class="row g-3">
        <div class="col-lg-8">
            <form method="POST" class="card border-0 shadow-sm" id="form-email"<?php echo $html ? ' onsubmit="if (window.tinymce) tinymce.triggerSave();"' : ''; ?>><div class="card-body">
                <?php csrf_field(); ?>
                <input type="hidden" name="chiave" value="<?php echo $h($chiave); ?>">
                <?php if ($facoltativa): ?>
                    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="attiva" id="attiva" value="1"<?php echo !empty($attiva) ? ' checked' : ''; ?>><label class="form-check-label fw-bold" for="attiva">Invia questa email</label><div class="form-text">Senza la spunta (o con il testo vuoto) l'email non parte. Il testo di partenza è solo un suggerimento.</div></div>
                <?php endif; ?>
                <div class="mb-3">
                    <label for="oggetto" class="form-label fw-bold">Oggetto</label>
                    <input type="text" class="form-control" id="oggetto" name="oggetto" maxlength="<?php echo ModelliEmail::OGGETTO_MAX; ?>" value="<?php echo $h($oggetto); ?>" required>
                </div>
                <div class="mb-3">
                    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-1">
                        <label for="corpo" class="form-label fw-bold mb-0">Testo</label>
                        <?php if (!$html): ?><span class="btn-group btn-group-sm" role="group" aria-label="Formattazione">
                            <button type="button" class="btn btn-outline-secondary" data-inserisci="****" data-dentro="2" title="Grassetto"><i class="fa fa-bold" aria-hidden="true"></i><span class="visually-hidden">Grassetto</span></button>
                            <button type="button" class="btn btn-outline-secondary" data-riga="- " title="Elenco puntato"><i class="fa fa-list-ul" aria-hidden="true"></i><span class="visually-hidden">Elenco puntato</span></button>
                            <button type="button" class="btn btn-outline-secondary" data-riga="> " title="Citazione evidenziata"><i class="fa fa-quote-left" aria-hidden="true"></i><span class="visually-hidden">Citazione</span></button>
                        </span><?php endif; ?>
                    </div>
                    <textarea class="form-control<?php echo $html ? ' editor-html' : ''; ?>" id="corpo" name="corpo" rows="14"<?php echo $html ? '' : ' maxlength="' . ModelliEmail::CORPO_MAX . '" required'; ?> style="font-family:inherit;"><?php echo $h($corpo); ?></textarea>
                    <?php if ($html): ?><div class="form-text">Testo scritto con l'editor. I dati si inseriscono con i segnaposto a graffa singola, per esempio <code>{NOME}</code> (clicca un segnaposto qui a destra).</div><?php else: ?><div class="form-text">Una riga vuota separa i paragrafi. <code>**così**</code> = grassetto; righe che iniziano con <code>- </code> = elenco; con <code>&gt; </code> = citazione evidenziata. Il testo non è HTML: i simboli restano come li scrivi.</div><?php endif; ?>
                </div>
                <?php if ($m['bottone'] !== null): ?>
                    <div class="mb-3">
                        <label for="bottone" class="form-label fw-bold">Testo del pulsante</label>
                        <input type="text" class="form-control" id="bottone" name="bottone" maxlength="<?php echo ModelliEmail::BOTTONE_MAX; ?>" value="<?php echo $h($bottone); ?>" required>
                        <div class="form-text">Il link del pulsante lo mette il portale (è personale e non si cambia da qui).</div>
                    </div>
                <?php endif; ?>
                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-primary" name="salva" value="1"><i class="fa fa-floppy-disk me-1" aria-hidden="true"></i>Salva</button>
                    <button class="btn btn-outline-primary" name="anteprima" value="1"><i class="fa fa-eye me-1" aria-hidden="true"></i>Anteprima</button>
                    <button class="btn btn-outline-primary" name="prova" value="1"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Inviami una prova</button>
                    <?php if ($att['modificato']): ?><button class="btn btn-outline-danger ms-auto" name="ripristina" value="1" formnovalidate data-confirm="Tornare al testo originale? La tua modifica andrà persa."><i class="fa fa-rotate-left me-1" aria-hidden="true"></i>Ripristina l'originale</button><?php endif; ?>
                </div>
            </div></form>

            <?php if ($anteprima): ?>
                <div class="card border-0 shadow-sm mt-3" id="anteprima"><div class="card-header bg-white fw-bold"><i class="fa fa-eye me-1" aria-hidden="true"></i>Anteprima (con dati d'esempio, non è stata salvata né inviata)</div>
                    <div class="card-body">
                        <div class="small text-secondary">Oggetto</div>
                        <div class="fw-bold mb-3"><?php echo $h($anteprima['oggetto']); ?></div>
                        <div class="border rounded p-3 bg-white" style="max-width:640px;">
                            <?php echo $anteprima['corpo']; ?>
                            <?php if ($anteprima['bottone'] !== null): ?><p style="margin-top:18px;"><span style="background:<?php echo $h(\App\Email\InvioEmail::coloreDi($m) ?? '#B80000'); ?>;color:#fff;padding:10px 18px;border-radius:6px;font-weight:bold;display:inline-block;"><?php echo $h($anteprima['bottone']); ?></span></p><p style="font-size:12px;color:#64748b;">Il link è personale: non inoltrarlo.</p><?php endif; ?>
                        </div>
                    </div>
                </div>
                <script>document.getElementById('anteprima').scrollIntoView({behavior: 'smooth', block: 'start'});</script>
            <?php endif; ?>

            <details class="card border-0 shadow-sm mt-3"><summary class="card-header bg-white fw-bold" style="cursor:pointer;">Testo originale (quello del codice)</summary>
                <div class="card-body small">
                    <div class="text-secondary">Oggetto</div><div class="mb-2"><?php echo $h($m['oggetto']); ?></div>
                    <div class="text-secondary">Testo</div><pre class="mb-2" style="white-space:pre-wrap;font-family:inherit;"><?php echo $h($m['corpo']); ?></pre>
                    <?php if ($m['bottone'] !== null): ?><div class="text-secondary">Pulsante</div><div><?php echo $h($m['bottone']); ?></div><?php endif; ?>
                </div>
            </details>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm"><div class="card-header bg-white fw-bold">Segnaposto di questa email</div>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($m['segnaposto'] as $nome => $info): ?>
                        <li class="list-group-item">
                            <button type="button" class="btn btn-sm btn-outline-secondary font-monospace py-0" data-segnaposto="<?php echo $html ? $h($nome) : '{{' . $h($nome) . '}}'; ?>" title="Inserisci nel testo"><?php echo $html ? $h($nome) : '{{' . $h($nome) . '}}'; ?></button>
                            <div class="mt-1"><?php echo $h($info['d']); ?></div>
                            <?php if ($html || $nome[0] !== '!'): ?><div class="text-secondary">Esempio: <?php echo $h($info['esempio'] !== '' ? $info['esempio'] : '(vuoto)'); ?></div><?php else: ?><div class="text-secondary">Pezzo preparato dal portale: va scritto da solo su una riga.</div><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="small text-secondary mt-2">Clicca un segnaposto per inserirlo dove si trova il cursore (oggetto, testo o pulsante).</div>
        </div>
    </div>
    <script>
    (function () {
        var attivo = document.getElementById('corpo');
        ['oggetto', 'corpo', 'bottone'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.addEventListener('focus', function () { attivo = el; });
        });
        function inserisci(testo, dentro) {
            var ed = window.tinymce && tinymce.get('corpo');
            if (ed && attivo && attivo.id !== 'oggetto' && attivo.id !== 'bottone') { ed.insertContent(testo); return; }
            var el = attivo, a = el.selectionStart, b = el.selectionEnd, v = el.value;
            var sel = v.substring(a, b);
            if (dentro && sel) testo = testo.substring(0, dentro) + sel + testo.substring(dentro);
            el.value = v.substring(0, a) + testo + v.substring(b);
            var pos = a + (dentro && !sel ? dentro : testo.length);
            el.focus(); el.setSelectionRange(pos, pos);
        }
        document.querySelectorAll('[data-segnaposto]').forEach(function (b) {
            b.addEventListener('click', function () { inserisci(b.getAttribute('data-segnaposto')); });
        });
        document.querySelectorAll('[data-inserisci]').forEach(function (b) {
            b.addEventListener('click', function () { attivo = document.getElementById('corpo'); inserisci(b.getAttribute('data-inserisci'), parseInt(b.getAttribute('data-dentro') || '0', 10)); });
        });
        document.querySelectorAll('[data-riga]').forEach(function (b) {
            b.addEventListener('click', function () {
                var el = document.getElementById('corpo'), a = el.selectionStart, v = el.value, ini = v.lastIndexOf('\n', a - 1) + 1;
                el.value = v.substring(0, ini) + b.getAttribute('data-riga') + v.substring(ini);
                el.focus();
            });
        });
    })();
    </script>
<?php endif; ?>
<?php require_once 'admin_footer.php'; ?>
