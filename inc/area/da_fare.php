<?php // Riquadro «da_fare» dell'area personale: lo include area_personale.php, quindi usa le variabili della pagina ($conn, $user_info, $u_id…). ?>
    <?php
    // «Da fare per te»: conferme degli attestati, firme, registri del tutorato, schede FSL, elenchi degli studenti, prenotazioni da approvare, verbali, pratiche da lavorare
    $da_fare_gr = \App\Core\App::per($conn)->get(\App\Portale\DaFarePersona::class)->gruppi($user_info ?? null);
    if ($da_fare_gr): $da_fare_n = \App\Portale\DaFarePersona::totale($da_fare_gr); ?>
        <div class="col-12"><div class="card border-0 shadow-sm" id="da-fare" style="border-radius: 12px; border-left: 6px solid #b45309 !important;">
            <div class="card-body">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <h2 class="h5 fw-bold mb-0"><i class="fa fa-list-check me-2" style="color:#b45309;" aria-hidden="true"></i>Da fare per te</h2>
                    <span class="badge rounded-pill text-bg-warning fs-6"><?php echo $da_fare_n; ?></span>
                </div>
                <p class="small text-secondary mb-3">Le cose che aspettano te nel portale, con il link per farle. Una volta a settimana ti arriva anche per email.</p>
                <div class="row g-3">
                <?php foreach ($da_fare_gr as $g_df): ?>
                    <div class="col-lg-6"><div class="h-100 rounded-3 p-3" style="background:#f8fafc;border-left:4px solid <?php echo htmlspecialchars($g_df['colore']); ?>;">
                        <div class="fw-bold small mb-1"><i class="fa <?php echo htmlspecialchars($g_df['icona']); ?> me-1" style="color:<?php echo htmlspecialchars($g_df['colore']); ?>;" aria-hidden="true"></i><?php echo htmlspecialchars($g_df['titolo']); ?> <span class="badge text-bg-secondary"><?php echo (int)$g_df['n']; ?></span></div>
                        <?php foreach (array_slice($g_df['righe'], 0, 4) as $r_df): ?>
                            <a class="d-block text-decoration-none text-dark py-1 border-top" href="<?php echo htmlspecialchars($r_df['link']); ?>">
                                <span class="small fw-semibold"><?php echo htmlspecialchars($r_df['testo']); ?></span><?php if ($r_df['giorni'] >= \App\Portale\DaFarePersona::GIORNI_FERMA): ?> <span class="badge text-bg-danger"><?php echo (int)$r_df['giorni']; ?> giorni</span><?php endif; ?>
                                <span class="d-block small text-secondary"><?php echo htmlspecialchars($r_df['dettaglio']); ?></span>
                            </a>
                        <?php endforeach; if ((int)$g_df['n'] > 4): ?><div class="small text-secondary pt-1 border-top">… e altre <?php echo (int)$g_df['n'] - 4; ?>.</div><?php endif; ?>
                    </div></div>
                <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
