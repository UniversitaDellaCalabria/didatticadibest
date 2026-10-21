<?php
// Prove dell'attività FSL fatta da una scuola solo come orientamento (prenotazioni.solo_orientamento): niente convenzione,
// elenco degli studenti e autorizzazione. Chiamato da esegui.php. Dati di prova con id 987xx.
sezione("Modulo FSL: scuola che fa l'attività solo come orientamento");

use App\Core\App as AppSo;
use App\Iscritti\Operatore;

foreach (["DELETE FROM prenotazioni WHERE id IN (98703, 98704)", "DELETE FROM turni WHERE id = 98702", "DELETE FROM progetti_dettagli WHERE evento_id = 98701",
          "DELETE FROM eventi WHERE id = 98701", "DELETE FROM pagine_eventi WHERE id = 98700"] as $sql) $q($sql);
$q("INSERT INTO pagine_eventi (id, titolo, slug, tipo_area, visibile, colore_primario) VALUES (98700, 'FSL orientamento 987', 'fsl_orientamento_987', 'fsl', 1, '#445566')");
$q("INSERT INTO eventi (id, pagina_id, titolo, tipo, descrizione_breve) VALUES (98701, 98700, 'Attività FSL 987', 'evento', 'Prova.')");
$q("INSERT INTO progetti_dettagli (evento_id, convenzione, per_scuole, attestati) VALUES (98701, 1, 1, 0)");
$q("INSERT INTO turni (id, evento_id, nome_turno, data_turno, max_posti, richiede_approvazione) VALUES (98702, 98701, 'Turno 987', '" . $giorni(20) . "', 40, 0)");
$q("INSERT INTO prenotazioni (id, turno_id, codice_prenotazione, stato, nome, cognome, email, convenzione, data_prenotazione) VALUES
    (98703, 98702, 'FS-SO1', 'confermata', 'Gian', 'Novello', 'so1@example.org', 'no', NOW()),
    (98704, 98702, 'FS-SO2', 'confermata', 'Ada', 'Rossi', 'so2@example.org', 'no', NOW())");

$doc_so = AppSo::per($conn)->get(\App\Fsl\DocumentiClasseRepository::class);
$fsl_so = AppSo::per($conn)->get(\App\Fsl\PrenotazioneFslRepository::class);
$pan_so = AppSo::per($conn)->get(\App\Fsl\PannelloRepository::class);
$ids_so = static fn (array $righe): array => array_map('intval', array_column($righe, 'id'));

prova(array_intersect([98703, 98704], $ids_so($doc_so->incomplete())) === [98703, 98704], "prima: i documenti mancano per entrambe le prenotazioni");
prova(count(array_intersect([98703, 98704], $ids_so($pan_so->iscrizioniDaStipulare()))) === 2, "prima: la convenzione risulta da stipulare per entrambe");

$srv_so = AppSo::per($conn)->get(\App\Iscritti\ServizioIscritti::class);
$srv_so->soloOrientamento(98703, true, new Operatore(0, 'prova@example.org', '127.0.0.1'));

prova(!in_array(98703, $ids_so($doc_so->incomplete()), true) && in_array(98704, $ids_so($doc_so->incomplete()), true), "solo orientamento: non si chiedono più elenco e autorizzazione (l'altra scuola sì)");
prova(!in_array(98703, $ids_so($pan_so->iscrizioniDaStipulare()), true) && in_array(98704, $ids_so($pan_so->iscrizioniDaStipulare()), true), "solo orientamento: la convenzione non è più da stipulare");
prova(!in_array(98703, $ids_so($fsl_so->daVerificare()), true), "solo orientamento: il controllo del registro delle convenzioni la salta");
prova((int) ($fsl_so->dati(98703)['fsl'] ?? 1) === 0 && (int) ($fsl_so->dati(98704)['fsl'] ?? 0) === 1, "solo orientamento: per quella prenotazione l'attività non è FSL");
prova(($doc_so->perCodice('FS-SO1')['fsl'] ?? 1) == 0, "solo orientamento: la pagina dei documenti non li richiede");
prova($conn->query("SELECT convenzione FROM prenotazioni WHERE id = 98703")->fetch_row()[0] === null, "solo orientamento: la richiesta di convenzione già aperta si chiude");

$srv_so->soloOrientamento(98703, false, new Operatore(0, 'prova@example.org', '127.0.0.1'));
prova(in_array(98703, $ids_so($doc_so->incomplete()), true), "tolto il segno: tornano i documenti da consegnare");

foreach (["DELETE FROM prenotazioni WHERE id IN (98703, 98704)", "DELETE FROM turni WHERE id = 98702", "DELETE FROM progetti_dettagli WHERE evento_id = 98701",
          "DELETE FROM eventi WHERE id = 98701", "DELETE FROM pagine_eventi WHERE id = 98700"] as $sql) $q($sql);
