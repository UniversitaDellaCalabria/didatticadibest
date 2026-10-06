<?php

declare(strict_types=1);

namespace App\Core;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * Container per la Dependency Injection, minimale e senza librerie.
 *
 * - set(id, fn(Container $c) => …): registra come si crea un servizio (creato una volta sola, poi condiviso);
 * - istanza(id, $oggetto): registra un oggetto già pronto (es. la Database del portale);
 * - get(Classe::class): restituisce il servizio; se non è registrato e la classe ha un costruttore con
 *   parametri tipizzati da classi, li risolve da solo (autowiring).
 *
 * Le classi di src/ non conoscono il container: ricevono le dipendenze nel costruttore.
 */
final class Container
{
    /** @var array<string, Closure(Container): object> */
    private array $fabbriche = [];

    /** @var array<string, object> */
    private array $istanze = [];

    /** @var array<string, true> classi in costruzione (per riconoscere le dipendenze circolari) */
    private array $inCorso = [];

    /** @param Closure(Container): object $fabbrica */
    public function set(string $id, Closure $fabbrica): void
    {
        $this->fabbriche[$id] = $fabbrica;
        unset($this->istanze[$id]);
    }

    public function istanza(string $id, object $oggetto): void
    {
        $this->istanze[$id] = $oggetto;
    }

    public function has(string $id): bool
    {
        return isset($this->istanze[$id]) || isset($this->fabbriche[$id]) || class_exists($id);
    }

    /**
     * @template T of object
     * @param class-string<T>|string $id
     * @return ($id is class-string<T> ? T : object)
     */
    public function get(string $id): object
    {
        if (isset($this->istanze[$id])) {
            return $this->istanze[$id];
        }
        if (isset($this->inCorso[$id])) {
            throw new RuntimeException("Dipendenza circolare: $id");
        }
        $this->inCorso[$id] = true;
        try {
            $oggetto = isset($this->fabbriche[$id]) ? ($this->fabbriche[$id])($this) : $this->costruisci($id);
        } finally {
            unset($this->inCorso[$id]);
        }

        return $this->istanze[$id] = $oggetto;
    }

    private function costruisci(string $classe): object
    {
        if (!class_exists($classe)) {
            throw new RuntimeException("Servizio non registrato e classe inesistente: $classe");
        }
        $rc = new ReflectionClass($classe);
        if (!$rc->isInstantiable()) {
            throw new RuntimeException("La classe $classe non si può istanziare: registrala con set()");
        }
        $costruttore = $rc->getConstructor();
        if ($costruttore === null) {
            return new $classe();
        }
        $argomenti = [];
        foreach ($costruttore->getParameters() as $p) {
            $tipo = $p->getType();
            if ($tipo instanceof ReflectionNamedType && !$tipo->isBuiltin()) {
                $argomenti[] = $this->get($tipo->getName());
            } elseif ($p->isDefaultValueAvailable()) {
                $argomenti[] = $p->getDefaultValue();
            } else {
                throw new RuntimeException("Parametro \${$p->getName()} di $classe non risolvibile: registra la classe con set()");
            }
        }

        return $rc->newInstanceArgs($argomenti);
    }
}
