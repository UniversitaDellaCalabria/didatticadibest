<?php

declare(strict_types=1);

namespace App\Infrastructure\Pdf;

/**
 * I messaggi che spiegano perché un PDF firmato è stato rifiutato: la causa in italiano semplice e che cosa fare. Una sola fonte, così scuole, aziende
 * e docenti leggono le stesse parole. Testo semplice (chi lo mostra lo ripulisce); la guida «Scuole» sta in guida.php?r=scuola.
 */
final class MessaggiFirma
{
    public const GUIDA_SCUOLE = 'guida.php?r=scuola';

    public const VUOTO = 'vuoto';
    public const GRANDE = 'grande';
    public const NON_PDF = 'non_pdf';
    public const P7M = 'p7m';
    public const SENZA_FIRMA = 'senza_firma';
    public const NON_INTEGRA = 'non_integra';
    public const MODIFICATA = 'modificata';

    private const TESTI = [
        self::VUOTO => 'Il file è vuoto. Cosa fare: scegli di nuovo il PDF firmato e riprova.',
        self::GRANDE => 'Il file è troppo grande: il limite è %d MB. Cosa fare: riduci le immagini del documento prima della firma, poi firma e carica di nuovo.',
        self::NON_PDF => 'Il file non è un PDF. Cosa fare: carica il PDF firmato (il file termina con .pdf), non un documento Word o un\'immagine.',
        self::P7M => 'Il file non è un PDF: è una firma in formato CAdES (.p7m), che il portale non accetta. Cosa fare: firma di nuovo il PDF scegliendo il formato PAdES (nel programma di firma l\'opzione «PDF firmato») e carica il file .pdf che ne esce.',
        self::SENZA_FIRMA => 'Nel PDF non trovo una firma digitale PAdES: il file non risulta firmato. Cosa fare: fai firmare il PDF dal Dirigente in formato PAdES e carica il file così come esce dalla firma.',
        self::NON_INTEGRA => 'La firma digitale del PDF non è integra: il file è danneggiato o la firma si è interrotta. Cosa fare: ripeti la firma sul PDF originale e carica di nuovo il file.',
        self::MODIFICATA => 'Il PDF è stato modificato dopo la firma (salvato di nuovo, stampato in PDF o ritoccato), quindi la firma non copre più tutto il file. Cosa fare: ripeti la firma e carica il file così come è uscito dalla firma, senza riaprirlo.',
    ];

    public static function per(string $causa, int $limiteMb = 10): string
    {
        return sprintf(self::TESTI[$causa] ?? self::TESTI[self::NON_PDF], $limiteMb);
    }

    /** Il contenuto non è un PDF: se somiglia a una firma CAdES (.p7m, DER o PEM) lo dice, altrimenti il messaggio generico. */
    public static function nonPdf(string $contenuto): string
    {
        $p7m = ($contenuto !== '' && $contenuto[0] === "\x30" && strlen($contenuto) > 4 && ($contenuto[1] === "\x82" || $contenuto[1] === "\x80" || $contenuto[1] === "\x83"))
            || str_contains(substr($contenuto, 0, 200), '-----BEGIN PKCS7') || str_contains(substr($contenuto, 0, 200), '-----BEGIN CMS');

        return self::per($p7m ? self::P7M : self::NON_PDF);
    }
}
