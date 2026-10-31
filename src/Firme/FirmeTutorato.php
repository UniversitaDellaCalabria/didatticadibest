<?php

declare(strict_types=1);

namespace App\Firme;

use App\Tutorato\ArchivioIncarichi;
use App\Tutorato\IncaricoRepository;
use App\Tutorato\ServizioIncarichi;
use App\Tutorato\ServizioRegistroTutorato;

/** Lettere di incarico del tutorato (firma del docente responsabile, poi del Direttore) e dichiarazioni di fine attività (firma del docente). */
final class FirmeTutorato implements FonteFirme
{
    public function __construct(private IncaricoRepository $incarichi, private ServizioIncarichi $lettere, private ArchivioIncarichi $archivio, private ServizioRegistroTutorato $registro)
    {
    }

    public function tipo(): string
    {
        return 'tutorato';
    }

    public function etichetta(): string
    {
        return 'Lettere di tutorato';
    }

    public function documenti(array $utente): array
    {
        $out = [];
        foreach ($this->incarichi->inFirma() as $i) {
            $tutor = trim((string) $i['cognome'] . ' ' . (string) $i['nome']);
            if ((string) $i['stato'] === 'confermata' && (string) $i['token_docente'] !== '' && $this->lettere->firmatario($i, 'docente', $utente)) {
                $out[] = $this->documento($i, 'lettera', 'Lettera di incarico · tutor ' . $tutor, 'firma del docente responsabile', (string) $i['token_docente'], $this->archivio->pdfCorrente($i));
            } elseif ((string) $i['stato'] === 'firmata_docente' && (string) $i['token_direttore'] !== '' && $this->lettere->firmatario($i, 'direttore', $utente)) {
                $out[] = $this->documento($i, 'direttore', 'Lettera di incarico · tutor ' . $tutor, 'firmata dal docente, aspetta il Direttore', (string) $i['token_direttore'], $this->archivio->pdfCorrente($i));
            }
            if ((string) $i['fine_stato'] === 'da_firmare' && (string) $i['token_fine'] !== '' && $this->lettere->firmatario($i, 'docente', $utente)) {
                $out[] = $this->documento($i, 'fine', 'Fine attività · tutor ' . $tutor, 'dichiarazione di fine attività', (string) $i['token_fine'], $this->archivio->fineCorrente($i));
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $i */
    private function documento(array $i, string $parte, string $titolo, string $dettaglio, string $token, ?string $percorso): DocumentoDaFirmare
    {
        $id = (int) $i['id'];
        $registra = $parte === 'fine'
            ? fn (string $pdf): ?string => $this->registro->registraFirmaFine($id, $pdf, 'PDF firmato caricato')
            : fn (string $pdf): ?string => $this->lettere->registraFirma($id, $parte === 'direttore' ? 'direttore' : 'docente', $pdf, 'PDF firmato caricato');

        return new DocumentoDaFirmare(
            $this->tipo(),
            (int) $i['id'],
            $titolo,
            'incarico ' . $i['codice'] . ' · ' . $dettaglio,
            max(0, (int) ($i['gg'] ?? 0)),
            'firma_incarico.php?t=' . urlencode($token),
            $percorso,
            DocumentoDaFirmare::nomeSicuro($this->tipo() . '-' . $parte, (int) $i['id'], (string) $i['codice']),
            $registra
        );
    }
}
