<?php
// Tessera «assistenza» del cruscotto dell'area personale: la include area_personale.php, quindi usa le variabili della pagina ($conn, $user_info, $u_id…).
// Assistenza: una chat nuova ogni volta; le conversazioni precedenti restano nello storico. Sparisce se il servizio è spento (lo storico resta leggibile)
try { $assist_on = \App\Core\App::get(\App\Assistenza\OrariAssistenza::class)->attiva(); } catch (\Throwable $e_as) { $assist_on = false; }
try { $assist_storico = \App\Core\App::get(\App\Assistenza\ConversazioniRepository::class)->storicoDi((int)$_SESSION['utente_id'], 30); } catch (\Throwable $e_as) { $assist_storico = []; }
if ($assist_on || $assist_storico) {
    $da_leggere_as = 0;
    foreach ($assist_storico as $c_as) if ($c_as['stato'] !== 'chiusa') $da_leggere_as += (int)$c_as['non_letti'];
    area_tessera('fa-headset', '#B30000', 'Assistenza',
        $assist_on ? 'Scrivi a Didacticus o a una persona dell\'ufficio: tirocini, certificati, pratiche, tesi, eventi' : 'Il servizio è sospeso: puoi rileggere le tue conversazioni',
        $assist_on ? 'assistenza.php' : 'assistenza_storico.php', $da_leggere_as, 'da leggere',
        ($assist_on && $assist_storico) ? [['Le tue conversazioni', 'assistenza_storico.php']] : []);
}
