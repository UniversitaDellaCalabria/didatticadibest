<?php
// Prove della disponibilità dei docenti per tesi e tirocini (App\Disponibilita): regole del semaforo, archivio, scelta dei corsi dall'anagrafe, riquadro del docente.
// Chiamato da esegui.php. Dati di prova con id 997xx.
sezione("Disponibilità dei docenti per tesi e tirocini");

use App\Disponibilita\DisponibilitaRepository;
use App\Disponibilita\Semaforo;

$ora_dp = new DateTimeImmutable('2026-10-28 12:00:00');
$sem_dp = fn(array $r) => Semaforo::di($r + ['accetta' => 1, 'posti' => 5, 'occupati' => 0, 'aggiornato_il' => '2026-10-27 09:00:00'], $ora_dp);
prova($sem_dp([])['stato'] === 'verde' && $sem_dp([])['liberi'] === 5 && $sem_dp([])['etichetta'] === 'Disponibile', "semaforo: con posti liberi è verde");
prova($sem_dp(['occupati' => 4])['stato'] === 'arancione' && $sem_dp(['occupati' => 4])['dettaglio'] === '1 posto libero su 5', "semaforo: un solo posto libero è arancione");
prova($sem_dp(['occupati' => 5])['stato'] === 'rosso' && $sem_dp(['occupati' => 9])['liberi'] === 0, "semaforo: al completo è rosso (anche con più occupati dei posti)");
prova($sem_dp(['accetta' => 0])['stato'] === 'grigio' && $sem_dp(['accetta' => 0])['etichetta'] === 'Non accetta per ora', "semaforo: chi non accetta è grigio");
$vecchia_dp = $sem_dp(['aggiornato_il' => '2026-06-01 09:00:00']);
prova($vecchia_dp['stato'] === 'grigio' && $vecchia_dp['scaduta'] && $vecchia_dp['etichetta'] === 'Da riconfermare', "semaforo: non aggiornata da più di 90 giorni è grigia, mai verde");
prova($sem_dp(['aggiornato_il' => '2026-07-30 09:00:00'])['stato'] === 'verde' && $sem_dp(['aggiornato_il' => null])['stato'] === 'grigio', "semaforo: 90 giorni giusti ancora validi, senza data è da riconfermare");
prova(Semaforo::quandoTesto(0) === 'oggi' && Semaforo::quandoTesto(1) === 'ieri' && Semaforo::quandoTesto(12) === '12 giorni fa' && Semaforo::quandoTesto(95) === '3 mesi fa', "semaforo: il «quando» è leggibile");

$rep_dp = \App\Core\App::per($conn)->get(DisponibilitaRepository::class);
$q("DELETE FROM disponibilita_docenti WHERE utente_id BETWEEN 99701 AND 99709");
$q("DELETE FROM utenti WHERE id BETWEEN 99701 AND 99709");
$q("DELETE FROM corsi_studio WHERE codice IN ('ZZ99701', 'ZZ99702')");
$q("INSERT INTO utenti (id, nome, cognome, email, ruolo_id, ruoli_secondari) VALUES (99701, 'Maria', 'Rossi', 'rossi997@example.org', 5, ''), (99702, 'Luca', 'Bianchi', 'bianchi997@example.org', 5, '')");
$q("INSERT INTO corsi_studio (codice, nome, tipo, tipo_descrizione, visibile, presente) VALUES ('ZZ99701', 'Scienze naturali 997', 'L', 'Laurea', 1, 1), ('ZZ99702', 'Biologia 997', 'LM', 'Laurea magistrale', 1, 1)");
prova($rep_dp->salva(99701, 'tesi', ['accetta' => 1, 'posti' => 0], '2026-10-28 10:00:00') !== null, "archivio: se accetti devi indicare i posti");
prova($rep_dp->salva(99701, 'altro', ['accetta' => 1, 'posti' => 2], '2026-10-28 10:00:00') !== null, "archivio: un tipo sconosciuto non si salva");
$err_dp = $rep_dp->salva(99701, 'tesi', ['accetta' => 1, 'posti' => 5, 'occupati' => 3, 'corsi' => ['ZZ99701', 'ZZ99702', 'NONESISTE', 'ZZ99701'], 'temi' => 'ecologia, <b>botanica</b>',
    'attivita' => 'campionamenti in campo', 'obiettivi' => 'imparare a campionare', 'sede' => 'Lab 3', 'note' => 'scrivimi'], '2026-10-28 10:00:00');
$riga_dp = $rep_dp->perUtente(99701, 'tesi');
prova($err_dp === null && $riga_dp && (int)$riga_dp['posti'] === 5 && (int)$riga_dp['occupati'] === 3 && $riga_dp['corsi'] === 'ZZ99701,ZZ99702' && $riga_dp['temi'] === 'ecologia, botanica', "archivio: salva posti, corsi dell'anagrafe (senza doppioni né codici inventati) e testi ripuliti dal codice");
$rep_dp->salva(99701, 'tesi', ['accetta' => 1, 'posti' => 4, 'occupati' => 4, 'corsi' => ['ZZ99702']], '2026-10-28 11:00:00');
prova((int)$conn->query("SELECT COUNT(*) FROM disponibilita_docenti WHERE utente_id = 99701 AND tipo = 'tesi'")->fetch_row()[0] === 1 && (int)$rep_dp->perUtente(99701, 'tesi')['posti'] === 4, "archivio: salvare di nuovo aggiorna la stessa riga");
$rep_dp->salva(99701, 'tirocinio', ['accetta' => 0, 'posti' => 0], '2026-10-28 11:00:00');
$rep_dp->salva(99702, 'tesi', ['accetta' => 1, 'posti' => 2, 'occupati' => 0], '2026-10-28 11:00:00');
$el_dp = $rep_dp->elenco('tesi');
$nomi_dp = array_column($el_dp, 'cognome');
prova($nomi_dp === ['Bianchi', 'Rossi'] && $el_dp[1]['corsi_lista'] === ['ZZ99702' => 'Biologia 997'] && $el_dp[0]['corsi_lista'] === [], "elenco: solo chi ha messo la disponibilità per quel tipo, con i nomi dei corsi (nessun corso = tutti)");
prova(count($rep_dp->elenco('tirocinio')) === 1 && $rep_dp->elenco('tirocinio')[0]['cognome'] === 'Rossi', "elenco: tesi e tirocini sono separati");
$rep_dp->elimina(99701, 'tesi');
prova($rep_dp->perUtente(99701, 'tesi') === null && count($rep_dp->elenco('tesi')) === 1, "elimina: il docente esce dalla pagina pubblica");

use App\Portale\Area\CatalogoRiquadri;
use App\Portale\Area\Profili;
$cat_dp = CatalogoRiquadri::sistema()['disponibilita'] ?? null;
prova($cat_dp && $cat_dp['profili'] === [Profili::DOCENTE, Profili::AMMINISTRATORE] && is_file(__DIR__ . '/../../../inc/area/disponibilita.php'), "riquadro: «La mia disponibilità» per i docenti e l'amministratore, con il suo file");
foreach (['disponibilita_tesi.php', 'disponibilita_tirocini.php', 'la_mia_disponibilita.php'] as $f_dp) prova(is_file(__DIR__ . '/../../../' . $f_dp), "esiste la pagina $f_dp");
$menu_dp = array_merge(...array_values(\App\Core\App::per($conn)->get(\App\Portale\CatalogoPagineMenu::class)->catalogo()));
prova(in_array(['Disponibilità dei docenti per la tesi', 'disponibilita_tesi.php'], $menu_dp, true) && in_array(['Disponibilità dei docenti per i tirocini', 'disponibilita_tirocini.php'], $menu_dp, true), "menu: le due pagine sono nel catalogo delle pagine del sito");
$q("DELETE FROM disponibilita_docenti WHERE utente_id BETWEEN 99701 AND 99709");
$q("DELETE FROM utenti WHERE id BETWEEN 99701 AND 99709");
$q("DELETE FROM corsi_studio WHERE codice IN ('ZZ99701', 'ZZ99702')");
