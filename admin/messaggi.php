<?php
// admin/messaggi.php - Casella unica dei messaggi: prenotazioni di tutte le aree e risposte delle scuole sulla convenzione FSL, con il modulo di provenienza
require_once 'admin_header.php';

$casella = \App\Core\App::get(\App\Iscritti\CasellaMessaggi::class);
$vede_convenzioni = $puo_fsl_convenzioni;
$vede_assistenza = $puo_assistenza; // le chat di assistenza si aprono e si risolvono nella pagina Assistenza
$aree_casella = $casella->aree((int)$u_id_curr, $is_full_admin);

// Controllo Permessi: serve gestire gli iscritti di almeno un'area, lavorare le convenzioni FSL oppure rispondere alle chat di assistenza
if (!$aree_casella && !$vede_convenzioni && !$vede_assistenza) {
    echo "<div class='alert alert-danger fw-bold shadow-sm m-4'><i class='fa fa-ban me-2'></i> Accesso negato.</div>";
    require_once 'admin_footer.php';
    exit;
}

function admin_redirect($url) {
    echo "<script>window.location.replace('$url');</script>";
    exit;
}

$messaggi_srv = \App\Core\App::get(\App\Iscritti\ServizioMessaggi::class);
$msg_conv_rep = \App\Core\App::get(\App\Fsl\MessaggiConvenzioneRepository::class);
$operatore = new \App\Iscritti\Operatore((int)($_SESSION['utente_id'] ?? 0), (string)($_SESSION['utente_email'] ?? ''), (string)($_SERVER['REMOTE_ADDR'] ?? 'Sconosciuto'));
$filtro_modulo_in = (string)($_GET['modulo'] ?? '');
$solo_da_leggere = isset($_GET['da_leggere']);
$vedi_archiviate = isset($_GET['archiviate']);
$ritorno_msg = 'messaggi.php?p_id=' . (int)$filtro_p . ($filtro_modulo_in !== '' ? '&modulo=' . urlencode($filtro_modulo_in) : '') . ($solo_da_leggere ? '&da_leggere=1' : '') . ($vedi_archiviate ? '&archiviate=1' : '');

// L'utente può gestire le conversazioni di questa prenotazione? Il filtro è quello dell'area dell'attività, non dell'area aperta nel pannello
$pren_gestibile = function (int $pr_id) use ($conn, $casella, $u_id_curr, $is_full_admin): bool {
    $p = \App\Core\App::per($conn)->get(\App\Iscritti\IscrittiRepository::class)->prenotazioneConTurnoEvento($pr_id);
    $area = $p ? \App\Core\App::get(\App\Portale\AreeRepository::class)->perId((int)$p['pagina_id']) : null;
    $rbac = $area ? $casella->filtroArea((int)$u_id_curr, $is_full_admin, $area) : null;

    return $rbac !== null && pren_autorizzata($conn, $pr_id, (int)$p['pagina_id'], $rbac);
};

// 1. INVIO RISPOSTA DALLA INBOX E MARCATURA COME LETTO
if (isset($_POST['invia_risposta_inbox'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $pr_id = (int)$_POST['prenotazione_id'];
    if (!$pren_gestibile($pr_id)) nega_accesso();
    // destinatario e titolo dal DB, non dal form; testo ripulito dai tag non sicuri
    if ($messaggi_srv->rispondiDaInbox($pr_id, (string)$_POST['corpo_messaggio'], $operatore)) {
        flash_set("✅ Risposta inviata con successo.");
    }
    admin_redirect($ritorno_msg);
}

// 1b. RISPOSTA A UNA SCUOLA SULLA CONVENZIONE FSL
if (isset($_POST['invia_risposta_conv'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    if (!$vede_convenzioni) nega_accesso();
    $id_cv = (int)$_POST['compilazione_id'];
    $autore_cv = trim((string)($_SESSION['utente_nome'] ?? '')) ?: (string)($_SESSION['utente_email'] ?? 'Ufficio');
    $err_cv = \App\Core\App::per($conn)->get(\App\Fsl\ServizioMessaggiConvenzione::class)->scriviAllaScuola($id_cv, (string)($_POST['corpo_messaggio'] ?? ''), $autore_cv);
    if (!$err_cv) {
        $msg_conv_rep->segnaLetti($id_cv, 'ufficio');
        registra_log_audit($conn, "Messaggio alla scuola sulla convenzione FSL", ["Compilazione" => $id_cv, "Testo" => mb_substr((string)($_POST['corpo_messaggio'] ?? ''), 0, 200)]);
    }
    flash_set($err_cv ?? "✅ Messaggio mandato: la scuola lo riceve per email e può rispondere dalla sua pagina.", $err_cv ? 'danger' : 'success');
    admin_redirect($ritorno_msg);
}

// 1c. ARCHIVIA (o rimette in casella) UNA CONVERSAZIONE: non si cancella nulla, e se l'altra parte riscrive torna da sola in casella
if (isset($_POST['archivia_conv']) || isset($_POST['ripristina_conv'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $archivia_cv = isset($_POST['archivia_conv']);
    [$tipo_ar, $id_ar] = array_pad(explode(':', (string)($_POST['archivia_conv'] ?? $_POST['ripristina_conv']), 2), 2, '0');
    $id_ar = (int)$id_ar;
    if ($tipo_ar === 'c') {
        if (!$vede_convenzioni) nega_accesso();
        $msg_conv_rep->archivia($id_ar, $archivia_cv);
    } else {
        if (!$pren_gestibile($id_ar)) nega_accesso();
        $messaggi_srv->archivia($id_ar, $archivia_cv);
    }
    flash_set($archivia_cv ? '✅ Conversazione archiviata: la ritrovi in «Archiviate» e torna in casella se arriva un nuovo messaggio.' : '✅ Conversazione rimessa in casella.');
    admin_redirect($ritorno_msg);
}

// 2. AZIONE RAPIDA: SEGNA COME LETTO SENZA RISPONDERE
if (isset($_GET['segna_letto'])) {
    $pr_id = (int)$_GET['segna_letto'];
    if (!$pren_gestibile($pr_id)) nega_accesso();
    $messaggi_srv->segnaLetti($pr_id);
    admin_redirect($ritorno_msg);
}
if (isset($_GET['segna_letto_conv'])) {
    if (!$vede_convenzioni) nega_accesso();
    $msg_conv_rep->segnaLetti((int)$_GET['segna_letto_conv'], 'ufficio');
    admin_redirect($ritorno_msg);
}

// 3. TUTTE LE CONVERSAZIONI (permessi come nelle singole aree), con il filtro scelto
$con_archivio = $casella->conversazioni((int)$u_id_curr, $is_full_admin, $vede_convenzioni, $vede_assistenza);
$n_archiviate = count(array_filter($con_archivio, fn($c) => $c['archiviata']));
$tutte_conv = array_values(array_filter($con_archivio, fn($c) => $c['archiviata'] === $vedi_archiviate));
$per_modulo = [];
foreach ($tutte_conv as $c_m) {
    $per_modulo[$c_m['modulo']] = ($per_modulo[$c_m['modulo']] ?? ['nome' => $c_m['modulo_nome'], 'tot' => 0, 'nuovi' => 0]);
    ++$per_modulo[$c_m['modulo']]['tot'];
    $per_modulo[$c_m['modulo']]['nuovi'] += $c_m['da_leggere'] > 0 ? 1 : 0;
}
$conversazioni = array_values(array_filter($tutte_conv, fn($c) => ($filtro_modulo_in === '' || $c['modulo'] === $filtro_modulo_in) && (!$solo_da_leggere || $c['da_leggere'] > 0)));
$n_nuovi_tot = count(array_filter($tutte_conv, fn($c) => $c['da_leggere'] > 0));
$url_filtro = fn(string $modulo, bool $nuovi) => 'messaggi.php?p_id=' . (int)$filtro_p . ($modulo !== '' ? '&modulo=' . urlencode($modulo) : '') . ($nuovi ? '&da_leggere=1' : '') . ($vedi_archiviate ? '&archiviate=1' : '');
$colori_mod = fn(string $m) => defined('MODULI_PORTALE') ? (MODULI_PORTALE[$m]['colore'] ?? '#475569') : '#475569';
?>

<style>#page-content-wrapper { min-width: 0; }</style>
<h4 class="fw-bold text-dark mb-3"><i class="fa fa-envelope-open-text text-danger me-2"></i> Tutti i Messaggi (Inbox)</h4>

<?php echo flash_html(); ?>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3" role="group" aria-label="Filtra i messaggi">
    <a href="<?php echo htmlspecialchars($url_filtro('', false)); ?>" class="btn btn-sm fw-bold <?php echo $filtro_modulo_in === '' && !$solo_da_leggere ? 'btn-dark' : 'btn-outline-dark'; ?>"><?php echo $vedi_archiviate ? 'Archiviate' : 'Tutti'; ?> (<?php echo count($tutte_conv); ?>)</a>
    <a href="<?php echo htmlspecialchars($url_filtro($filtro_modulo_in, true)); ?>" class="btn btn-sm fw-bold <?php echo $solo_da_leggere ? 'btn-danger' : 'btn-outline-danger'; ?>"><i class="fa fa-envelope me-1" aria-hidden="true"></i>Da leggere (<?php echo $n_nuovi_tot; ?>)</a>
    <span class="text-secondary small ms-2 d-none d-md-inline">Modulo:</span>
    <?php foreach ($per_modulo as $k_m => $m_m): ?>
        <a href="<?php echo htmlspecialchars($url_filtro($k_m, $solo_da_leggere)); ?>" class="btn btn-sm fw-bold" style="border:1px solid <?php echo $colori_mod($k_m); ?>;<?php echo $filtro_modulo_in === $k_m ? 'background:' . $colori_mod($k_m) . ';color:#fff;' : 'color:' . $colori_mod($k_m) . ';background:#fff;'; ?>"><?php echo htmlspecialchars($m_m['nome']); ?> (<?php echo (int)$m_m['tot']; ?>)<?php echo $m_m['nuovi'] ? ' · ' . (int)$m_m['nuovi'] . ' nuovi' : ''; ?></a>
    <?php endforeach; ?>
    <a href="messaggi.php?p_id=<?php echo (int)$filtro_p; ?><?php echo $vedi_archiviate ? '' : '&archiviate=1'; ?>" class="btn btn-sm fw-bold ms-md-auto btn-outline-secondary"><i class="fa <?php echo $vedi_archiviate ? 'fa-inbox' : 'fa-box-archive'; ?> me-1" aria-hidden="true"></i><?php echo $vedi_archiviate ? 'Torna alla casella' : 'Archiviate (' . $n_archiviate . ')'; ?></a>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle w-100 m-0">
                <thead class="table-dark">
                    <tr>
                        <th class="py-3 px-3">Modulo · Evento / Richiesta</th>
                        <th>Utente</th>
                        <th>Ultimo Aggiornamento</th>
                        <th>Stato Messaggi</th>
                        <th class="text-end px-3">Azione</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($conversazioni)): ?>
                        <tr><td colspan="5" class="text-center py-5 text-muted"><i class="fa fa-check-double fs-2 d-block mb-2 opacity-50"></i> <?php echo $tutte_conv ? 'Nessuna conversazione con questo filtro.' : ($vedi_archiviate ? 'Nessuna conversazione archiviata.' : 'Nessuna conversazione presente.'); ?></td></tr>
                    <?php else: ?>
                        <?php foreach($conversazioni as $conv):
                            $is_unread = $conv['da_leggere'] > 0;
                            $row_class = $is_unread ? 'table-warning font-weight-bold' : '';
                            $e_conv = $conv['tipo'] === \App\Iscritti\CasellaMessaggi::CONVENZIONE;
                            $e_as = $conv['tipo'] === \App\Iscritti\CasellaMessaggi::ASSISTENZA;
                            $id_modale = ($e_conv ? 'modChatConv' : 'modChatInbox') . $conv['id'];
                        ?>
                            <tr class="<?php echo $row_class; ?>">
                                <td class="py-3 px-3">
                                    <span class="badge fw-bold" style="background:<?php echo $colori_mod($conv['modulo']); ?>;"><?php echo htmlspecialchars($conv['modulo_nome']); ?></span>
                                    <?php if ($conv['area'] !== ''): ?><span class="small text-secondary ms-1"><?php echo htmlspecialchars($conv['area']); ?></span><?php endif; ?>
                                    <span class="d-block fw-bold text-danger my-1"><?php echo htmlspecialchars($conv['titolo']); ?></span>
                                    <?php if ($e_as): ?>
                                        <span class="badge bg-dark fw-normal">Chat di assistenza</span><?php if ($conv['rif'] !== ''): ?> <small class="text-muted"><?php echo htmlspecialchars($conv['rif']); ?></small><?php endif; ?>
                                    <?php elseif ($e_conv): ?>
                                        <span class="badge bg-dark fw-normal font-monospace">Convenzione online</span><?php if ($conv['rif'] !== ''): ?> <small class="text-muted font-monospace"><?php echo htmlspecialchars($conv['rif']); ?></small><?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge bg-dark fw-normal font-monospace">Ticket: <?php echo htmlspecialchars($conv['rif']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($conv['nome']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($conv['email']); ?></small>
                                </td>
                                <td>
                                    <span class="d-block text-nowrap"><i class="fa fa-clock text-secondary me-1"></i><?php echo date('d/m/Y', strtotime($conv['ultimo'])); ?></span><small class="text-muted ms-3"><?php echo date('H:i', strtotime($conv['ultimo'])); ?></small>
                                </td>
                                <td>
                                    <?php if ($is_unread): ?>
                                        <span class="badge bg-danger rounded-pill shadow-sm py-1 px-2">
                                            <i class="fa fa-envelope me-1"></i> <?php echo $conv['da_leggere']; ?> da leggere
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted border py-1 px-2">
                                            <i class="fa fa-envelope-open me-1"></i> Letti (Tot. <?php echo $conv['totale']; ?>)
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end px-3 text-nowrap">
                                    <?php if ($e_as): ?>
                                        <a href="assistenza.php?p_id=<?php echo (int)$filtro_p; ?>&vista=<?php echo $conv['archiviata'] ? 'chiuse' : 'tutte'; ?>&c=<?php echo (int)$conv['id']; ?>" class="btn btn-outline-dark btn-sm fw-bold shadow-sm"><i class="fa fa-headset me-1"></i> Apri la chat</a>
                                    </td></tr>
                                    <?php continue; endif; ?>
                                    <?php if ($is_unread): ?>
                                        <a href="<?php echo htmlspecialchars($ritorno_msg . '&' . ($e_conv ? 'segna_letto_conv=' : 'segna_letto=') . $conv['id']); ?>" class="btn btn-sm btn-outline-secondary me-1" title="Segna come già letto senza aprire"><i class="fa fa-check-double"></i></a>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-outline-dark btn-sm fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#<?php echo $id_modale; ?>">
                                        <i class="fa fa-book-reader me-1"></i> Leggi / Rispondi
                                    </button>
                                    <?php $chiave_ar = ($e_conv ? 'c:' : 'p:') . $conv['id']; ?>
                                    <form method="POST" action="<?php echo htmlspecialchars($ritorno_msg); ?>" class="d-inline m-0"><?php csrf_field(); ?>
                                        <?php if ($conv['archiviata']): ?>
                                            <button type="submit" name="ripristina_conv" value="<?php echo $chiave_ar; ?>" class="btn btn-outline-secondary btn-sm fw-bold" title="Rimetti la conversazione in casella"><i class="fa fa-inbox me-1" aria-hidden="true"></i>Ripristina</button>
                                        <?php else: ?>
                                            <button type="submit" name="archivia_conv" value="<?php echo $chiave_ar; ?>" class="btn btn-outline-secondary btn-sm fw-bold" title="Archivia: sparisce dalla casella ma non si cancella, e torna se arriva un nuovo messaggio"><i class="fa fa-box-archive" aria-hidden="true"></i><span class="visually-hidden">Archivia</span></button>
                                        <?php endif; ?>
                                    </form>
                                </td>
                            </tr>

                            <!-- MODALE CHAT INBOX -->
                            <div class="modal fade" id="<?php echo $id_modale; ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content shadow-lg border-0">
                                        <div class="modal-header py-3 bg-dark text-white">
                                            <h6 class="modal-title fw-bold"><i class="fa fa-comments me-2"></i> Chat con: <?php echo htmlspecialchars($conv['nome']); ?> <span class="badge ms-2" style="background:<?php echo $colori_mod($conv['modulo']); ?>;"><?php echo htmlspecialchars($conv['modulo_nome']); ?></span></h6>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body p-0 bg-light">
                                            <div class="p-4" style="max-height: 450px; overflow-y: auto; background: #f8fafc;">
                                                <?php
                                                // Estraiamo i messaggi di QUESTA conversazione (dalla prenotazione o dalla convenzione)
                                                $messaggi_chat = [];
                                                if ($e_conv) {
                                                    foreach ($msg_conv_rep->della((int)$conv['id']) as $mc) $messaggi_chat[] = ['mio' => $mc['da'] === 'ufficio', 'testo' => nl2br(htmlspecialchars((string)$mc['testo'])), 'data' => $mc['creato_il'], 'chi' => $mc['da'] === 'ufficio' ? 'Tu (Admin)' : $conv['nome']];
                                                } else {
                                                    foreach ($messaggi_srv->chat((int)$conv['id']) as $msg) $messaggi_chat[] = ['mio' => $msg['mittente_tipo'] === 'admin', 'testo' => $msg['mittente_tipo'] === 'admin' ? strip_tags($msg['messaggio'], \App\Iscritti\ServizioMessaggi::TAG_CONSENTITI) : nl2br(htmlspecialchars($msg['messaggio'])), 'data' => $msg['data_invio'], 'chi' => $msg['mittente_tipo'] === 'admin' ? 'Tu (Admin)' : $conv['nome']];
                                                }
                                                foreach ($messaggi_chat as $msg):
                                                ?>
                                                    <?php if($msg['mio']): ?>
                                                        <div class="d-flex justify-content-end mb-3">
                                                            <div style="max-width: 80%;">
                                                                <div class="small text-muted text-end mb-1" style="font-size: 0.7rem;"><?php echo htmlspecialchars($msg['chi']); ?> - <?php echo date('d/m/Y H:i', strtotime($msg['data'])); ?></div>
                                                                <div class="p-2 rounded-3 text-white shadow-sm" style="background-color: #B80000; border-bottom-right-radius: 0 !important;">
                                                                    <?php echo $msg['testo']; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="d-flex justify-content-start mb-3">
                                                            <div style="max-width: 80%;">
                                                                <div class="small text-muted mb-1" style="font-size: 0.7rem;"><?php echo htmlspecialchars($msg['chi']); ?> - <?php echo date('d/m/Y H:i', strtotime($msg['data'])); ?></div>
                                                                <div class="p-2 rounded-3 text-dark shadow-sm bg-white border" style="border-bottom-left-radius: 0 !important;">
                                                                    <?php echo $msg['testo']; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>

                                            <form method="POST" class="border-top p-3 bg-white">
                                                <input type="hidden" name="<?php echo $e_conv ? 'compilazione_id' : 'prenotazione_id'; ?>" value="<?php echo $conv['id']; ?>">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">

                                                <label class="form-label small fw-bold text-dark"><?php echo $e_conv ? 'Invia un messaggio alla scuola' : "Invia Risposta all'Utente"; ?></label>
                                                <textarea name="corpo_messaggio" class="form-control<?php echo $e_conv ? '' : ' editor-html'; ?>" rows="3" maxlength="<?php echo $e_conv ? 1500 : 20000; ?>" placeholder="Scrivi una risposta qui..." required></textarea>

                                                <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                                                    <small class="text-muted"><i class="fa fa-info-circle me-1"></i> Rispondendo, la conversazione verrà automaticamente segnata come letta.<?php if ($e_conv): ?> <a href="fsl.php?p_id=<?php echo (int)$filtro_p; ?>&tab=iter#cv<?php echo (int)$conv['id']; ?>">Apri la convenzione</a><?php endif; ?></small>
                                                    <button type="submit" name="<?php echo $e_conv ? 'invia_risposta_conv' : 'invia_risposta_inbox'; ?>" <?php echo $e_conv ? '' : 'onclick="tinymce.triggerSave();"'; ?> class="btn btn-primary fw-bold px-4 shadow-sm" style="background-color: #B80000; border: none;">
                                                        <i class="fa fa-paper-plane me-1"></i> Invia Risposta
                                                    </button>
                                                </div>
                                            </form>

                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'admin_footer.php'; ?>
