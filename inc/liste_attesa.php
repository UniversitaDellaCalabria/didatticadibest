<?php
// inc/liste_attesa.php - Liste d'attesa: promozione dei posti liberati e scadenze (anche senza cron).
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

// =======================================================================
// MOTORE INTELLIGENTE LISTE D'ATTESA (NUOVO MODULO)
// =======================================================================
if (!function_exists('promuovi_lista_attesa')) {
    function promuovi_lista_attesa($conn, $turno_id) {
        $res_t = $conn->query("SELECT max_posti, data_turno, orario_inizio FROM turni WHERE id = " . (int)$turno_id . " LIMIT 1");
        if (!$res_t || $res_t->num_rows == 0) return;
        $turno = $res_t->fetch_assoc();
        
        // REGOLA: Se mancano meno di 24 ore all'evento, NON promuoviamo più nessuno.
        // (i turni senza data non hanno scadenza)
        if (!empty($turno['data_turno'])) {
            $inizio_evento = $turno['data_turno'] . ' ' . ($turno['orario_inizio'] ?: '00:00:00');
            if (strtotime($inizio_evento) <= strtotime('+24 hours')) return;
        }

        // Quanti posti liberi ci sono?
        $turno_id = (int)$turno_id;
        $posti_liberi = (int)$turno['max_posti'] - getPostiOccupati($conn, $turno_id);
        
        // Ciclo sicuro per promuovere utenti finché c'è spazio
        while ($posti_liberi > 0) {
            $res_promo = $conn->query("SELECT p.*, e.titolo as evento_titolo FROM prenotazioni p JOIN turni t ON p.turno_id = t.id JOIN eventi e ON t.evento_id = e.id WHERE p.turno_id = $turno_id AND p.stato = 'in_attesa' ORDER BY p.data_prenotazione ASC, p.id ASC LIMIT 1");
            
            if ($res_promo && $u_promo = $res_promo->fetch_assoc()) {
                if ($posti_liberi >= $u_promo['num_posti']) {
                    $id_promo = $u_promo['id'];
                    $scadenza = date('Y-m-d H:i:s', strtotime('+24 hours')); // +24 Ore esatte
                    
                    // Cambia stato e imposta timer
                    $update_ok = $conn->query("UPDATE prenotazioni SET stato = 'richiesta_conferma', scadenza_conferma = '$scadenza' WHERE id = $id_promo");
                    
                    if ($update_ok && $conn->affected_rows > 0) {
                        // Prepara e invia l'email
                        $link_conferma = url_base_sito() . "/area_personale.php?conferma_posto=" . $id_promo;
                        
                        $obj_tpl = "Azione Richiesta: Si è liberato un posto per " . $u_promo['evento_titolo'];
                        $body_tpl = "<p>Ottime notizie <strong>" . htmlspecialchars($u_promo['nome']) . "</strong>!</p>
                                     <p>Si è appena liberato un posto per l'evento <strong>" . htmlspecialchars($u_promo['evento_titolo']) . "</strong>.</p>
                                     <div style='background-color:#fff3cd; color:#856404; padding:15px; border-left:5px solid #ffeeba; margin:20px 0;'>
                                       <strong>ATTENZIONE:</strong> Hai esattamente <strong>24 ore</strong> di tempo per confermare la tua presenza. Se non confermi entro il " . date('d/m/Y H:i', strtotime($scadenza)) . ", il posto verrà riassegnato allo studente successivo.
                                     </div>
                                     <p><a href='$link_conferma' style='background-color:#198754; color:white; padding:12px 25px; text-decoration:none; border-radius:6px; font-weight:bold; display:inline-block;'>CONFERMA IL MIO POSTO</a></p>";
                        
                        inviaNotificaEmail($u_promo['email'], $obj_tpl, $body_tpl, $conn, colore_area_turno($conn, $turno_id));
                        
                        $posti_liberi -= $u_promo['num_posti']; // Sottrae i posti e continua il ciclo
                    } else {
                        break; // Se fallisce il database, ferma tutto in sicurezza
                    }
                } else {
                    break; // Il primo in lista d'attesa chiede più posti di quelli disponibili, ci fermiamo
                }
            } else {
                break; // Nessun altro in coda
            }
        }
    }
}

if (!function_exists('check_automazioni_sistema')) {
    function check_automazioni_sistema($conn) {
        $now = date('Y-m-d H:i:s');
        
        // 1. SCADENZA 24H: Annulla chi non ha confermato il link in tempo
        $sql_scadute = "SELECT id, turno_id FROM prenotazioni WHERE stato = 'richiesta_conferma' AND scadenza_conferma < '$now'";
        $res_scadute = $conn->query($sql_scadute);
        if ($res_scadute && $res_scadute->num_rows > 0) {
            while($row = $res_scadute->fetch_assoc()) {
                $pr_id = $row['id'];
                $t_id = $row['turno_id'];
                // Mettilo fuori gioco
                $conn->query("UPDATE prenotazioni SET stato = 'scaduta' WHERE id = $pr_id");
                // Pesca subito il prossimo fortunato per questo turno
                promuovi_lista_attesa($conn, $t_id);
            }
        }
        
        // 2. TAGLIOLA A -24H DALL'EVENTO: Chiudi e annulla definitivamente tutte le liste d'attesa pendenti
        $limite_chiusura = date('Y-m-d H:i:s', strtotime('+24 hours'));
        $sql_chiusura = "SELECT id FROM turni WHERE CONCAT(data_turno, ' ', orario_inizio) <= '$limite_chiusura' AND CONCAT(data_turno, ' ', orario_inizio) > '$now'";
        $res_chiusura = $conn->query($sql_chiusura);
        if ($res_chiusura && $res_chiusura->num_rows > 0) {
            while($t = $res_chiusura->fetch_assoc()) {
                $t_id = $t['id'];
                $conn->query("UPDATE prenotazioni SET stato = 'scaduta' WHERE turno_id = $t_id AND stato IN ('in_attesa', 'richiesta_conferma')");
            }
        }
    }
}

// ESECUZIONE SILENTE (Sostituto del Cron Job)
// Esegue il controllo solo una volta ogni 5 minuti
if (!isset($_SESSION['last_cron_run']) || (time() - $_SESSION['last_cron_run']) > 300) {
    check_automazioni_sistema($conn);
    $_SESSION['last_cron_run'] = time();
}
