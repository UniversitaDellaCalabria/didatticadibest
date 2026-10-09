<?php
// Prove dell'opzione «più edizioni» dei progetti (progetti_dettagli.piu_edizioni): la stessa scuola può prenotare una sola edizione
// (come prima) oppure più edizioni, una prenotazione per ogni edizione. Chiamato da esegui.php: stesse variabili ($conn, $q, $giorni, $EMAIL)
// e funzioni (prova, sezione). Dati di prova con id 987xx.
sezione("Modulo FSL: progetti con più edizioni prenotabili dalla stessa scuola");

use App\Core\App as AppPe;
use App\Fsl\RichiestaProgramma;
use App\Fsl\ServizioProgrammaFsl;

foreach (["DELETE FROM prenotazioni WHERE turno_id BETWEEN 98711 AND 98713", "DELETE FROM convenzioni_compilate WHERE email LIKE '%@pe987.example.org'", "DELETE FROM turni WHERE id BETWEEN 98711 AND 98713",
          "DELETE FROM progetti_dettagli WHERE evento_id = 98701", "DELETE FROM eventi WHERE id = 98701", "DELETE FROM campi_form WHERE pagina_id = 98700", "DELETE FROM pagine_eventi WHERE id = 98700"] as $sql) $q($sql);
$q("INSERT INTO pagine_eventi (id, titolo, slug, tipo_area, visibile, colore_primario) VALUES (98700, 'FSL più edizioni 987', 'fsl_piu_edizioni_987', 'fsl', 1, '#445566')");
$q("INSERT INTO campi_form (pagina_id, evento_id, nome_campo, etichetta, tipo_campo, obbligatorio, ordine) VALUES (98700, NULL, 'scuola', 'Scuola', 'scuola', 0, 1), (98700, NULL, 'numero_partecipanti', 'Numero di partecipanti', 'number', 1, 2)");
$q("INSERT INTO eventi (id, pagina_id, titolo, tipo, descrizione_breve) VALUES (98701, 98700, 'Progetto più edizioni 987', 'progetto', 'Prova.')");
$q("INSERT INTO progetti_dettagli (evento_id, convenzione, per_scuole, attestati, data_inizio, data_fine, ore_totali, min_studenti, max_studenti, piu_edizioni) VALUES (98701, 1, 1, 0, '" . $giorni(40) . "', '" . $giorni(70) . "', 20, 5, 30, 0)");
$q("INSERT INTO turni (id, evento_id, nome_turno, max_posti, richiede_approvazione, abilita_lista_attesa) VALUES (98711, 98701, 'Edizione A987', 1, 0, 0), (98712, 98701, 'Edizione B987', 1, 0, 0), (98713, 98701, 'Edizione C987', 1, 0, 0)");

$prg_pe = AppPe::per($conn)->get(ServizioProgrammaFsl::class);
$_SESSION = [];
$conferma_pe = function (int $turno, string $email) use ($prg_pe) {
    static $n = 0;
    $_SESSION['captcha_pren']['pe' . ++$n] = ['r' => 7, 't' => time() - 10];
    $prg_pe->aggiungi($turno, ['custom_numero_partecipanti' => '12'], false, 5, []);

    return $prg_pe->conferma(new RichiestaProgramma('Anna', 'Docente', $email, null, 5, [], [
        'accetta_privacy' => 'on', 'email_conferma' => $email, 'sito_web' => '', 'custom_scuola' => 'Liceo Più Edizioni 987', 'convenzione' => 'si', 'captcha_id' => 'pe' . $n, 'captcha_risposta' => '7',
    ], null, '10.98.7.' . random_int(1, 250), 'https://dibest2.unical.it/eventi'));
};
$stato_pe = fn(int $turno) => $conn->query("SELECT stato FROM prenotazioni WHERE turno_id = $turno AND email = 'anna987@pe987.example.org' ORDER BY id DESC LIMIT 1")->fetch_assoc()['stato'] ?? null;

// Opzione spenta (predefinita): una sola edizione per scuola
$e = $conferma_pe(98711, 'anna987@pe987.example.org');
prova(!$e->errori && $e->prenotato() && $stato_pe(98711) !== null, "una scuola prenota la prima edizione");
$e = $conferma_pe(98712, 'anna987@pe987.example.org');
$esito2 = $e->voci[0]['esito'] ?? '';
prova($esito2 === 'errore' && str_contains((string)($e->voci[0]['messaggio'] ?? ''), 'un\'altra edizione') && $stato_pe(98712) === null, "opzione spenta: la seconda edizione dello stesso progetto è rifiutata", json_encode($e->voci));
$prg_pe->svuota();

// Opzione accesa: più edizioni, una prenotazione per ciascuna
$q("UPDATE progetti_dettagli SET piu_edizioni = 1 WHERE evento_id = 98701");
$e = $conferma_pe(98712, 'anna987@pe987.example.org');
prova(!$e->errori && ($e->voci[0]['esito'] ?? '') !== 'errore' && $stato_pe(98712) !== null, "opzione accesa: la stessa scuola prenota anche la seconda edizione", json_encode($e->voci));
$e = $conferma_pe(98713, 'anna987@pe987.example.org');
prova($stato_pe(98713) !== null && (int)$conn->query("SELECT COUNT(*) n FROM prenotazioni WHERE email = 'anna987@pe987.example.org' AND turno_id BETWEEN 98711 AND 98713")->fetch_assoc()['n'] === 3, "opzione accesa: tre edizioni, tre prenotazioni");
$e = $conferma_pe(98711, 'anna987@pe987.example.org');
prova(($e->voci[0]['esito'] ?? '') === 'errore' && (int)$conn->query("SELECT COUNT(*) n FROM prenotazioni WHERE email = 'anna987@pe987.example.org' AND turno_id = 98711")->fetch_assoc()['n'] === 1, "opzione accesa: la stessa edizione non si prenota due volte", json_encode($e->voci));
$prg_pe->svuota();

// L'opzione si legge dal progetto e dal repository
$rep_pe = AppPe::per($conn)->get(\App\Iscrizioni\PrenotazioneRepository::class);
prova($rep_pe->piuEdizioniConsentite(98701) && !$rep_pe->piuEdizioniConsentite(98799), "piuEdizioniConsentite(): vera per il progetto con l'opzione, falsa altrimenti");
AppPe::per($conn)->get(\App\Eventi\ProgettoRepository::class)->impostaPiuEdizioni(98701, 0);
prova(!$rep_pe->piuEdizioniConsentite(98701) && (int)($conn->query("SELECT piu_edizioni FROM progetti_dettagli WHERE evento_id = 98701")->fetch_assoc()['piu_edizioni']) === 0, "impostaPiuEdizioni(): si spegne dal progetto");

foreach (["DELETE FROM prenotazioni WHERE turno_id BETWEEN 98711 AND 98713", "DELETE FROM convenzioni_compilate WHERE email LIKE '%@pe987.example.org'", "DELETE FROM turni WHERE id BETWEEN 98711 AND 98713",
          "DELETE FROM progetti_dettagli WHERE evento_id = 98701", "DELETE FROM eventi WHERE id = 98701", "DELETE FROM campi_form WHERE pagina_id = 98700", "DELETE FROM pagine_eventi WHERE id = 98700"] as $sql) $q($sql);
