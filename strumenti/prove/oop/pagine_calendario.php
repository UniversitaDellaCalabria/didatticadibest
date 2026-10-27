<?php
// Prove via HTTP (ambiente locale acceso): calendario personale, file .ics e abbonamento, ricerca unica, interruttore del riepilogo con l'agenda nel pannello.
sezione("Calendario personale, ricerca unica e riepilogo con l'agenda: pagine");
foreach (['calendario.php'] as $pag) {
    $r = $http("/eventi/$pag", null, $jar);
    prova($r['codice'] === 200 && str_contains($r['corpo'], 'Il mio calendario') && str_contains($r['corpo'], 'calendario_ics.php?t=') && str_contains($r['corpo'], 'webcal://') && !str_contains($r['corpo'], 'Fatal error') && !str_contains($r['corpo'], 'Warning:'), "$pag da amministratore: calendario, link di abbonamento e pulsanti");
}
prova($http('/eventi/calendario.php')['codice'] === 302 && $http('/eventi/calendario_ics.php')['codice'] === 302, "calendario.php e calendario_ics.php senza accesso: rimando al login");
$r = $http('/eventi/calendario_ics.php', null, $jar);
prova($r['codice'] === 200 && str_starts_with($r['corpo'], 'BEGIN:VCALENDAR') && str_contains($r['corpo'], 'END:VCALENDAR'), "calendario_ics.php: file .ics per chi ha fatto l'accesso");
preg_match('~calendario_ics\.php\?t=([a-f0-9]{40})~', $http('/eventi/calendario.php', null, $jar)['corpo'], $m_tok);
$r = $http('/eventi/calendario_ics.php?t=' . ($m_tok[1] ?? 'x'));
prova(!empty($m_tok[1]) && $r['codice'] === 200 && str_starts_with($r['corpo'], 'BEGIN:VCALENDAR'), "calendario_ics.php?t=…: il telefono abbonato legge il calendario senza accesso");
prova($http('/eventi/calendario_ics.php?t=' . str_repeat('0', 40))['codice'] === 404 && $http('/eventi/calendario_ics.php?t=zz')['codice'] === 404, "calendario_ics.php: un link sbagliato non dà niente");
$r = $http('/eventi/area_personale.php', null, $jar);
prova(str_contains($r['corpo'], 'calendario.php') && str_contains($r['corpo'], 'Il mio calendario') && str_contains($r['corpo'], 'ui-tessera') && str_contains($r['corpo'], 'data-installa-app') && str_contains($r['corpo'], 'uiTemaBtn'), "area personale: tessera del calendario, tema scuro e pulsante per installare l'app");
$r = $http('/eventi/ricerca.php?q=tesi');
prova($r['codice'] === 200 && str_contains($r['corpo'], 'Come si fa') && str_contains($r['corpo'], 'guida.php?r=') && !str_contains($r['corpo'], 'Fatal error'), "ricerca.php?q=tesi: risultati raggruppati (guide)");
$r = $http('/eventi/ricerca.php?q=zzzqqq');
prova($r['codice'] === 200 && str_contains($r['corpo'], 'ui-vuoto') && str_contains($r['corpo'], 'Non abbiamo trovato niente'), "ricerca.php: nessun risultato con lo stato vuoto e il pulsante per Didacticus");
prova($http('/eventi/ricerca.php')['codice'] === 200 && !str_contains($http('/eventi/ricerca.php?q=%3Cscript%3E')['corpo'], '<script>alert'), "ricerca.php: senza domanda e con HTML");
$r = $http('/eventi/admin/sistema.php', null, $jar);
prova($r['codice'] === 200 && str_contains($r['corpo'], "Riepilogo settimanale con l'agenda") && str_contains($r['corpo'], 'name="riepilogo_agenda"') && !str_contains($r['corpo'], 'Fatal error'), "admin/sistema.php: l'interruttore del riepilogo con l'agenda");
prova($http('/eventi/admin/sistema.php', ['riepilogo_agenda' => '1', 'csrf_token' => 'sbagliato'], $jar)['codice'] === 403, "riepilogo con agenda: token CSRF sbagliato respinto");
$man = json_decode($http('/eventi/manifest.json')['corpo'], true);
prova(is_array($man) && $man['display'] === 'standalone' && count($man['shortcuts']) >= 4 && in_array('maskable', array_column($man['icons'], 'purpose'), true) && in_array('any', array_column($man['icons'], 'purpose'), true), "manifest: app installabile con le scorciatoie e le icone");
prova(str_contains($http('/eventi/privacy.php')['corpo'], 'beforeinstallprompt'), "app installabile: ogni pagina ha lo script per il pulsante «Installa l'app»");
