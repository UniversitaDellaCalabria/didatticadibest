<?php

declare(strict_types=1);

namespace App\Tirocini;

/** Le province italiane (sigla => nome) con la loro regione: dalla provincia scelta si ricava la regione, così non si scrive due volte. */
final class Province
{
    private const REGIONI = [
        'Abruzzo' => 'AQ:L\'Aquila,CH:Chieti,PE:Pescara,TE:Teramo',
        'Basilicata' => 'MT:Matera,PZ:Potenza',
        'Calabria' => 'CZ:Catanzaro,CS:Cosenza,KR:Crotone,RC:Reggio Calabria,VV:Vibo Valentia',
        'Campania' => 'AV:Avellino,BN:Benevento,CE:Caserta,NA:Napoli,SA:Salerno',
        'Emilia-Romagna' => 'BO:Bologna,FE:Ferrara,FC:Forlì-Cesena,MO:Modena,PR:Parma,PC:Piacenza,RA:Ravenna,RE:Reggio Emilia,RN:Rimini',
        'Friuli-Venezia Giulia' => 'GO:Gorizia,PN:Pordenone,TS:Trieste,UD:Udine',
        'Lazio' => 'FR:Frosinone,LT:Latina,RI:Rieti,RM:Roma,VT:Viterbo',
        'Liguria' => 'GE:Genova,IM:Imperia,SP:La Spezia,SV:Savona',
        'Lombardia' => 'BG:Bergamo,BS:Brescia,CO:Como,CR:Cremona,LC:Lecco,LO:Lodi,MN:Mantova,MI:Milano,MB:Monza e della Brianza,PV:Pavia,SO:Sondrio,VA:Varese',
        'Marche' => 'AN:Ancona,AP:Ascoli Piceno,FM:Fermo,MC:Macerata,PU:Pesaro e Urbino',
        'Molise' => 'CB:Campobasso,IS:Isernia',
        'Piemonte' => 'AL:Alessandria,AT:Asti,BI:Biella,CN:Cuneo,NO:Novara,TO:Torino,VB:Verbano-Cusio-Ossola,VC:Vercelli',
        'Puglia' => 'BA:Bari,BT:Barletta-Andria-Trani,BR:Brindisi,FG:Foggia,LE:Lecce,TA:Taranto',
        'Sardegna' => 'CA:Cagliari,NU:Nuoro,OR:Oristano,SS:Sassari,SU:Sud Sardegna',
        'Sicilia' => 'AG:Agrigento,CL:Caltanissetta,CT:Catania,EN:Enna,ME:Messina,PA:Palermo,RG:Ragusa,SR:Siracusa,TP:Trapani',
        'Toscana' => 'AR:Arezzo,FI:Firenze,GR:Grosseto,LI:Livorno,LU:Lucca,MS:Massa-Carrara,PI:Pisa,PT:Pistoia,PO:Prato,SI:Siena',
        'Trentino-Alto Adige' => 'BZ:Bolzano,TN:Trento',
        'Umbria' => 'PG:Perugia,TR:Terni',
        'Valle d\'Aosta' => 'AO:Aosta',
        'Veneto' => 'BL:Belluno,PD:Padova,RO:Rovigo,TV:Treviso,VE:Venezia,VR:Verona,VI:Vicenza',
    ];

    /** @var array<string, array{0: string, 1: string}>|null sigla => [nome, regione] */
    private static ?array $tabella = null;

    /** @return array<string, array{0: string, 1: string}> in ordine alfabetico di nome */
    public static function tutte(): array
    {
        if (self::$tabella === null) {
            $t = [];
            foreach (self::REGIONI as $regione => $elenco) {
                foreach (explode(',', $elenco) as $voce) {
                    [$sigla, $nome] = explode(':', $voce, 2);
                    $t[$sigla] = [$nome, $regione];
                }
            }
            uasort($t, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
            self::$tabella = $t;
        }

        return self::$tabella;
    }

    /** Sigla in maiuscolo se è una provincia italiana, altrimenti ''. Accetta anche il nome intero ("Cosenza"). */
    public static function sigla(string $testo): string
    {
        $t = trim($testo);
        if ($t === '') {
            return '';
        }
        $s = strtoupper($t);
        $tutte = self::tutte();
        if (isset($tutte[$s])) {
            return $s;
        }
        foreach ($tutte as $sigla => [$nome]) {
            if (mb_strtolower($nome) === mb_strtolower($t)) {
                return (string) $sigla;
            }
        }

        return '';
    }

    public static function regione(string $sigla): string
    {
        return self::tutte()[$sigla][1] ?? '';
    }

    public static function nome(string $sigla): string
    {
        return self::tutte()[$sigla][0] ?? '';
    }
}
