<?php
// self_checkin.php - Motore di Auto-Registrazione (Versione Autenticata)
require_once 'header.php'; 

// Sicurezza: lo studente deve arrivare qui già loggato!
if (empty($_SESSION['utente_id'])) {
    echo "<script>window.location.replace('saml_login.php');</script>";
    exit;
}


$t_id = (int)($_GET['t'] ?? 0);
$token = trim($_GET['k'] ?? '');
$u_id = (int)$_SESSION['utente_id'];
// La logica (finestra di check-in, iscrizione confermata, presenza già registrata) sta in src/Iscrizioni/ServizioCheckin
$r_ck = \App\Core\App::get(\App\Iscrizioni\ServizioCheckin::class)->autoRegistrazione($t_id, $token, $u_id);
$esito = $r_ck['esito']; $msg = $r_ck['messaggio']; $colore = $r_ck['colore']; $icona = $r_ck['icona'];
$turno = $r_ck['turno'];
?>

<div class="row justify-content-center mt-4 mb-5">
    <div class="col-md-8 col-lg-6">
        <div class="card shadow-lg border-0 rounded-4 text-center overflow-hidden">
            <div class="bg-<?php echo $colore; ?> py-4">
                <i class="fa <?php echo $icona; ?> mb-2 text-white" style="font-size: 4rem;"></i>
                <h2 class="fw-bold m-0 text-white"><?php echo $esito === 'success' ? 'Operazione Riuscita' : 'Attenzione'; ?></h2>
            </div>
            <div class="card-body p-4 bg-white">
                <?php if(!empty($turno['evento_titolo'])): ?>
                    <h4 class="fw-bold text-dark mb-3 border-bottom pb-3"><?php echo htmlspecialchars($turno['evento_titolo']); ?></h4>
                <?php endif; ?>
                <p class="fs-5 text-dark mb-4"><?php echo $msg; ?></p>
                <div class="d-grid gap-2 mt-4">
                    <a href="area_personale.php" class="btn btn-outline-<?php echo $colore; ?> btn-lg fw-bold rounded-3">
                        <i class="fa fa-id-card me-2"></i> Torna alla tua Area
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php 
if (file_exists('footer.php')) { require_once 'footer.php'; } 
else { echo '</main></body></html>'; } 
?>
