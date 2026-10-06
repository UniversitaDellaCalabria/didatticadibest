<?php

declare(strict_types=1);

namespace App\Iscrizioni\Vista;

/** Messaggi (HTML) che l'Area personale mostra una volta sola dopo un'azione sulle prenotazioni. */
final class MessaggiAreaPersonale
{
    private const OK_BORDO = 'border-0 border-start border-5 border-success';

    public static function messaggioInviato(): string
    {
        return "<div class='alert alert-success fw-bold text-center my-3 shadow-sm'><i class='fa fa-paper-plane me-1'></i> Messaggio inviato con successo alla segreteria.</div>";
    }

    public static function nonAutorizzata(): string
    {
        return "<div class='alert alert-danger fw-bold text-center my-3 shadow-sm'><i class='fa fa-times-circle me-1'></i> Operazione non autorizzata.</div>";
    }

    public static function cambioTurnoScaduto(string $terminiFino): string
    {
        return "<div class='alert alert-warning fw-bold text-center my-3 shadow-sm'><i class='fa fa-lock me-1'></i> Non è più possibile cambiare turno: il termine era il " . date('d/m/Y \a\l\l\e H:i', (int) strtotime($terminiFino)) . '. Per necessità scrivi alla segreteria con il pulsante Assistenza.</div>';
    }

    public static function modificaNonSalvata(string $errore): string
    {
        return "<div class='alert alert-danger fw-bold text-center my-3 shadow-sm'><i class='fa fa-users me-1'></i> Modifica non salvata: " . htmlspecialchars($errore) . '</div>';
    }

    public static function turnoEsaurito(): string
    {
        return "<div class='alert alert-danger fw-bold text-center my-3 shadow-sm'><i class='fa fa-times-circle me-1'></i> Il turno selezionato è esaurito.</div>";
    }

    public static function erroreAggiornamentoRiprova(): string
    {
        return "<div class='alert alert-danger fw-bold text-center my-3 shadow-sm'><i class='fa fa-times-circle me-1'></i> Errore durante l'aggiornamento. Riprova.</div>";
    }

    public static function modificaSalvata(bool $inListaAttesa): string
    {
        $extra = $inListaAttesa ? " Sei stato inserito in Lista d'Attesa per il nuovo orario." : ' Turno aggiornato con successo!';

        return "<div class='alert alert-success fw-bold text-center my-3 shadow-sm " . self::OK_BORDO . "'><i class='fa fa-check-circle me-1'></i> Modifica salvata." . $extra . '</div>';
    }

    public static function prenotazioneAggiornata(): string
    {
        return "<div class='alert alert-success fw-bold text-center my-3 shadow-sm " . self::OK_BORDO . "'><i class='fa fa-check-circle me-1'></i> Prenotazione aggiornata con successo!</div>";
    }

    public static function annullamentoScaduto(string $terminiFino): string
    {
        return "<div class='alert alert-warning fw-bold text-center my-3 shadow-sm'><i class='fa fa-lock me-1'></i> Non è più possibile annullare questa prenotazione: il termine era il " . date('d/m/Y \a\l\l\e H:i', (int) strtotime($terminiFino)) . '. Per necessità scrivi alla segreteria con il pulsante Assistenza.</div>';
    }

    public static function annullata(): string
    {
        return "<div class='alert alert-success fw-bold text-center my-4 shadow-sm " . self::OK_BORDO . "'><i class='fa fa-check-circle me-2'></i> Prenotazione annullata correttamente.</div>";
    }

    public static function annullamentoNegato(): string
    {
        return "<div class='alert alert-danger fw-bold text-center my-4 shadow-sm'><i class='fa fa-times-circle me-2'></i> Errore o autorizzazione negata per l'annullamento.</div>";
    }

    public static function offertaScaduta(): string
    {
        return "<div class='alert alert-warning fw-bold text-center my-3 shadow-sm'><i class='fa fa-clock me-1'></i> Il tempo per confermare il posto è scaduto e il posto è stato riassegnato.</div>";
    }

    public static function offertaNonValida(): string
    {
        return "<div class='alert alert-warning fw-bold text-center my-3 shadow-sm'><i class='fa fa-clock me-1'></i> Questa offerta di posto non è più valida.</div>";
    }

    public static function erroreSalvataggio(): string
    {
        return "<div class='alert alert-danger fw-bold text-center my-3 shadow-sm'><i class='fa fa-times-circle me-1'></i> Errore durante il salvataggio. Riprova.</div>";
    }

    /** Posto accettato da una scuola senza convenzione: la prenotazione si conferma quando la convenzione arriva. */
    public static function postoAccettatoInAttesaConvenzione(string $istruzioniHtml): string
    {
        return "<div class='alert alert-warning text-start my-3 shadow-sm small'><div class='fw-bold mb-1'><i class='fa fa-file-signature me-1'></i> Posto accettato: la prenotazione sarà confermata all'arrivo della convenzione.</div>"
             . $istruzioniHtml . '</div>';
    }

    public static function postoConfermato(): string
    {
        return "<div class='alert alert-success fw-bold text-center my-3 shadow-sm " . self::OK_BORDO . "'><i class='fa fa-check-circle me-1'></i> Posto confermato! Ti abbiamo inviato la ricevuta via email.</div>";
    }

    public static function rinunciaAlPosto(): string
    {
        return "<div class='alert alert-info fw-bold text-center my-3 shadow-sm'><i class='fa fa-info-circle me-1'></i> Hai rinunciato al posto. Grazie per averlo lasciato ad altri.</div>";
    }

    public static function postoGiaConfermato(): string
    {
        return "<div class='alert alert-success fw-bold text-center my-3 shadow-sm'><i class='fa fa-check-circle me-1'></i> Questo posto è già confermato.</div>";
    }

    public static function offertaNonDisponibile(): string
    {
        return "<div class='alert alert-warning fw-bold text-center my-3 shadow-sm'><i class='fa fa-exclamation-triangle me-1'></i> Offerta di posto non valida o non più disponibile.</div>";
    }
}
