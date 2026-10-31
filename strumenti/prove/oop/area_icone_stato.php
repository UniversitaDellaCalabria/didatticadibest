<?php
// Prove della riga di stato sotto il nome delle icone dell'area personale (area_icona): visibile, ripulita, solo quando c'è una novità.
// Chiamato da esegui.php: stesse funzioni (prova, sezione).
sezione("Area personale: riga di stato sotto le icone");

require_once __DIR__ . '/../../../inc/area/_tessera.php';

$ic = static function (...$a): string { ob_start(); area_icona(...$a); return (string) ob_get_clean(); };
$html = $ic('fa-calendar-check', '#0d6efd', 'Eventi e attestati', 'miei_eventi.php', 1, 'Spiegazione lunga', 'Posto da confermare');
prova(str_contains($html, '<span class="ui-icona-stato">Posto da confermare</span>') && str_contains($html, 'title="Spiegazione lunga"') && str_contains($html, 'ha-novita'), "area_icona: la riga di stato sta sotto il nome, il suggerimento lungo resta");
prova(!str_contains($ic('fa-user', '#000', 'Profilo', 'profilo.php'), 'ui-icona-stato'), "area_icona: senza stato (come prima) non c'è nessuna riga");
prova(str_contains($ic('fa-user', '#000', 'X', 'x.php', 1, '', '<b>2</b> & altro'), '&lt;b&gt;2&lt;/b&gt; &amp; altro'), "area_icona: la riga di stato è ripulita");
prova(strpos($html, 'ui-icona-testo') < strpos($html, 'ui-icona-stato') && strpos($html, 'ui-icona-stato') < strpos($html, 'ui-badge-rosso'), "area_icona: ordine nome, stato, numero in rosso");
