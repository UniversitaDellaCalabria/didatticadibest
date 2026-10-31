<?php
// Prove dei messaggi di rifiuto dei PDF firmati (MessaggiFirma): ogni causa dice cosa è successo e cosa fare.
// Chiamato da esegui.php: stesse funzioni (prova, sezione).
sezione("Firme: messaggi chiari quando un PDF firmato è rifiutato");

use App\Infrastructure\Pdf\MessaggiFirma;

foreach ([MessaggiFirma::VUOTO, MessaggiFirma::GRANDE, MessaggiFirma::NON_PDF, MessaggiFirma::P7M, MessaggiFirma::SENZA_FIRMA, MessaggiFirma::NON_INTEGRA, MessaggiFirma::MODIFICATA] as $causa) {
    prova(str_contains(MessaggiFirma::per($causa), 'Cosa fare:') && !str_contains(MessaggiFirma::per($causa), '%'), "MessaggiFirma ({$causa}): la causa e cosa fare");
}
prova(str_contains(MessaggiFirma::per(MessaggiFirma::GRANDE, 10), 'limite è 10 MB'), "MessaggiFirma: il limite in MB entra nel testo");
prova(str_contains(MessaggiFirma::nonPdf("\x30\x82\x05\x10abcd"), 'CAdES') && str_contains(MessaggiFirma::nonPdf("-----BEGIN PKCS7-----\nxx"), 'CAdES'), "MessaggiFirma::nonPdf(): riconosce una firma .p7m (DER o PEM)");
prova(!str_contains(MessaggiFirma::nonPdf('PK qualcosa'), 'CAdES') && str_contains(MessaggiFirma::nonPdf('PK qualcosa'), 'non è un PDF') && str_contains(MessaggiFirma::nonPdf(''), 'non è un PDF'), "MessaggiFirma::nonPdf(): un altro file (Word, immagine, vuoto) ha il messaggio generico");
prova(str_contains(MessaggiFirma::per(MessaggiFirma::P7M), 'non è un PDF'), "MessaggiFirma: il messaggio del .p7m comincia come quello degli altri non-PDF");
