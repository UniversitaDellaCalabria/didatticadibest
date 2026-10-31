<?php
// Prove di «Prossima cosa da fare» (ProssimaCosa): quale compito proporre dopo averne chiuso uno.
// Chiamato da esegui.php: stesse variabili ($conn) e funzioni (prova, sezione).
sezione("Navigazione: prossima cosa da fare");

use App\Portale\ProssimaCosa;

require_once __DIR__ . '/../../../inc/ui.php';

$riga = static fn (string $testo, string $link, int $giorni = 0, ?string $scadenza = null): array => ['testo' => $testo, 'dettaglio' => 'd', 'link' => $link, 'giorni' => $giorni] + ($scadenza !== null ? ['scadenza' => $scadenza] : []);
$gr = static fn (string $titolo, array $righe): array => ['titolo' => $titolo, 'righe' => $righe];

prova(ProssimaCosa::scegli([], 'firma_pratica.php?t=a') === null, "ProssimaCosa: senza compiti niente");
prova(ProssimaCosa::scegli([$gr('Firme', [$riga('A', 'firma_pratica.php?t=a')])], '/firma_pratica.php?t=a&r=123') === null, "ProssimaCosa: il compito della pagina in cui sei non si ripropone");
$p = ProssimaCosa::scegli([$gr('Firme', [$riga('A', 'firma_pratica.php?t=a', 2), $riga('B', 'firma_pratica.php?t=b', 9)]), $gr('Conferme', [$riga('C', 'conferma_attestato.php?t=c', 1)])], 'firma_pratica.php?t=a');
prova($p !== null && $p['testo'] === 'B' && $p['link'] === 'firma_pratica.php?t=b' && $p['altre'] === 1 && $p['gruppo'] === 'Firme', "ProssimaCosa: salta la pagina corrente e sceglie quello che aspetta da più giorni, con il conto delle altre");
$p = ProssimaCosa::scegli([$gr('Firme', [$riga('Vecchia', 'x.php', 30)]), $gr('Posti', [$riga('Scade', 'miei_eventi.php?conferma_posto=1', 0, '2026-11-02')])], 'area_personale.php');
prova($p !== null && $p['testo'] === 'Scade', "ProssimaCosa: la scadenza vicina viene prima dell'attesa");
prova(ProssimaCosa::scegli([$gr('F', [$riga('Reg', 'registro_tutorato.php?id=5')])], 'registro_tutorato.php?id=6')['testo'] === 'Reg', "ProssimaCosa: stessa pagina ma altra pratica, si propone");

$GLOBALS['_flash_mostrato'] = 'danger';
prova(ui_prossima_cosa() === '', "ui_prossima_cosa(): dopo un errore non compare");
$GLOBALS['_flash_mostrato'] = 'success';
$user_info = null;
prova(ui_prossima_cosa() === '', "ui_prossima_cosa(): senza accesso non compare");
unset($GLOBALS['_flash_mostrato']);
foreach (['firma_pratica', 'firma_incarico', 'firma_verbale', 'firma_convenzione', 'firma_convenzione_tesi', 'conferma_attestato', 'registro_tutorato'] as $pg) {
    prova(str_contains((string) file_get_contents(__DIR__ . "/../../../$pg.php"), 'ui_prossima_cosa()'), "la pagina $pg propone la prossima cosa da fare");
}

// 4.5 Link che non valgono più: la pagina spiega e porta a «Da fare per te»
$lnv = ui_link_non_valido('Documento <non> disponibile', 'Il link non è valido.');
prova(str_contains($lnv, 'Documento &lt;non&gt; disponibile') && str_contains($lnv, 'href="area_personale.php#da-fare"') && str_contains($lnv, 'Da fare per te'), "ui_link_non_valido(): spiega, protegge il testo e porta a «Da fare per te»");
foreach (['firma_pratica', 'firma_incarico', 'firma_verbale', 'firma_convenzione', 'firma_convenzione_tesi', 'conferma_attestato'] as $pg) {
    prova(str_contains((string) file_get_contents(__DIR__ . "/../../../$pg.php"), 'ui_link_non_valido('), "la pagina $pg, con un link non valido, porta all'area personale");
}
prova(str_contains((string) file_get_contents(__DIR__ . '/../../../middleware.php'), 'saml_login.php?redirect=" . urlencode($_SERVER[\'REQUEST_URI\'])'), "i link con il token chiedono l'accesso e poi tornano alla stessa pagina (middleware)");
