<?php
// Tessera scritta dall'amministratore (o «scheda» di sistema): titolo, testo e fino a due pulsanti. La include area_personale.php con $riq = il riquadro.
$bott_g = [];
foreach ([[$riq['etichetta_link'], $riq['link']], [$riq['etichetta_link2'], $riq['link2']]] as $k_g => [$et_g, $href_g]) {
    if ($href_g !== '' && $et_g !== '') $bott_g[] = [$et_g, $href_g, (bool)preg_match('~^https?://~i', $href_g), $k_g === 0];
}
area_tessera($riq['icona'], $riq['colore'], $riq['titolo'], $riq['testo'], $riq['link'], 0, '', $bott_g);
