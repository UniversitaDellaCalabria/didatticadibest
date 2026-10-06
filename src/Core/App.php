<?php

declare(strict_types=1);

namespace App\Core;

use App\Infrastructure\Mail\ImpaginatoreEmail;
use App\Infrastructure\Mail\Mailer;
use App\Infrastructure\Mail\MailerDaFunzione;
use App\Infrastructure\Mail\SmtpMailer;
use LogicException;
use mysqli;
use WeakMap;

/**
 * Ponte tra il codice procedurale e le classi, da usare SOLO nelle pagine e nelle funzioni di inc/ non ancora migrate
 * (es. App::get(ServizioAvvisi::class), o App::per($conn)->get(...) nelle facciate che ricevono $conn).
 * Le classi di src/ non lo usano mai: ricevono tutto nel costruttore. Sparisce con l'ultimo codice procedurale.
 *
 * Un container per connessione: quello della richiesta (avviato da functions.php sulla $conn del portale) e,
 * se una facciata riceve un'altra connessione (cron, prove automatiche), uno costruito per quella.
 */
final class App
{
    private static ?Container $container = null;

    /** @var WeakMap<mysqli, Container>|null */
    private static ?WeakMap $perConnessione = null;

    /** Prepara il container sulla connessione del portale (chiamata da functions.php, una volta per richiesta). */
    public static function avvia(mysqli $conn, ?Config $config = null): Container
    {
        $c = self::nuovoContainer($conn, $config);
        self::$perConnessione ??= new WeakMap();
        self::$perConnessione[$conn] = $c;

        return self::$container = $c;
    }

    public static function container(): Container
    {
        if (self::$container === null) {
            throw new LogicException('Container non avviato: serve la connessione al database (functions.php dopo config.php).');
        }

        return self::$container;
    }

    /** Container della connessione data (lo stesso della richiesta se è la connessione del portale). */
    public static function per(mysqli $conn): Container
    {
        self::$perConnessione ??= new WeakMap();
        if (!isset(self::$perConnessione[$conn])) {
            self::$perConnessione[$conn] = self::nuovoContainer($conn, null);
        }

        return self::$perConnessione[$conn];
    }

    /**
     * @template T of object
     * @param class-string<T> $classe
     * @return T
     */
    public static function get(string $classe): object
    {
        return self::container()->get($classe);
    }

    /** La Database di una connessione: per le facciate procedurali che ricevono ancora $conn. */
    public static function db(mysqli $conn): Database
    {
        return Database::per($conn);
    }

    /** Il client email vero (SMTP, o cartella locale nell'ambiente di prova): lo usa la facciata inviaNotificaEmail(). */
    public static function mailer(mysqli $conn): SmtpMailer
    {
        $locale = defined('AMBIENTE_LOCALE') && AMBIENTE_LOCALE ? self::radice() . '/cache/email_locali/' : null;

        return new SmtpMailer(Database::per($conn), self::per($conn)->get(ImpaginatoreEmail::class), $locale);
    }

    private static function radice(): string
    {
        return defined('RADICE_SITO') ? (string) RADICE_SITO : dirname(__DIR__, 2);
    }

    /** @return list<class-string> classi Registrazione dei moduli (src/<Modulo>/Registrazione.php), in ordine alfabetico */
    private static function registrazioni(): array
    {
        static $elenco = null;
        if ($elenco === null) {
            $elenco = [];
            foreach (glob(dirname(__DIR__) . '/*/Registrazione.php') ?: [] as $f) {
                $classe = 'App\\' . basename(dirname($f)) . '\\Registrazione';
                if (class_exists($classe) && method_exists($classe, 'registra')) {
                    $elenco[] = $classe;
                }
            }
            sort($elenco);
        }

        return $elenco;
    }

    private static function nuovoContainer(mysqli $conn, ?Config $config): Container
    {
        $c = new Container();
        $c->istanza(Container::class, $c);
        $c->istanza(Database::class, Database::per($conn));
        if ($config !== null) {
            $c->istanza(Config::class, $config);
        } else {
            // Letta solo se qualcuno la chiede: lo stesso file di config.php (.env, o .env.locale in locale)
            $c->set(Config::class, static fn (): Config => Config::daFile(defined('FILE_ENV') ? (string) FILE_ENV : self::radice() . '/.env'));
        }
        $c->set(Sito::class, static fn (): Sito => new Sito(self::radice(), function_exists('env_valore') ? env_valore('URL_SITO') : null));
        $c->set(Orologio::class, static fn (): Orologio => new OrologioDiSistema());
        $c->set(IndirizzoClient::class, static fn (): IndirizzoClient => new IndirizzoClientDaServer());
        // Email dei servizi: passano da inviaNotificaEmail() (stesso registro log_email, ambiente locale, intercettazione nelle prove automatiche)
        $c->set(Mailer::class, static fn (Container $c): Mailer => new MailerDaFunzione($c->get(Database::class)));
        // Servizi dei moduli: ogni modulo registra i suoi in src/<Modulo>/Registrazione.php (registra(Container): void)
        foreach (self::registrazioni() as $classe) {
            $classe::registra($c);
        }

        return $c;
    }
}
