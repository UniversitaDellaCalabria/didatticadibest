<?php
// inc/pdf.php - PDF generati senza librerie esterne (lettere di incarico del Tutorato da firmare in PAdES).
// Pagine A4 con logo in testa, paragrafi giustificati con **grassetto**, tabelle, riquadri e numero di pagina.
// Caratteri standard del PDF (Times, Helvetica) con codifica WinAnsi: accenti italiani, € e virgolette tipografiche.
// Il documento è "pulito" (nessuna firma): le firme PAdES si aggiungono dopo, in modo incrementale, senza toccarlo.
// Con 'pdfa' => true il documento è PDF/A-2b (conservazione a norma e protocollo): caratteri Liberation (stesse misure di
// Times e Arial) incorporati solo con le lettere usate (assets/fonts, licenza SIL OFL), metadati XMP (anche quelli propri
// del portale, dichiarati con lo schema di estensione PDF/A), profilo colore sRGB (assets/modelli/sRGB.icc) e lingua.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

// Le classi stanno in src/Infrastructure/Pdf/ (Documento, TrueType): qui restano i vecchi nomi per il codice esistente
if (!class_exists('PdfSemplice', false)) class_alias(\App\Infrastructure\Pdf\Documento::class, 'PdfSemplice');
if (!class_exists('TtfSemplice', false)) class_alias(\App\Infrastructure\Pdf\TrueType::class, 'TtfSemplice');

if (!function_exists('firme_pades_pdf')) {
    // Firme digitali presenti in un PDF: [['subfilter' => 'ETSI.CAdES.detached' | 'adbe.pkcs7.detached' …, 'nome' => …, 'data' => …, 'copre_tutto' => bool], ...]
    // Facciata di App\Infrastructure\Pdf\FirmePades.
    function firme_pades_pdf(string $pdf): array {
        return (new \App\Infrastructure\Pdf\FirmePades())->firmePades($pdf);
    }
}
