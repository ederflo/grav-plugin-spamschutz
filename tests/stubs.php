<?php
/**
 * Minimale Nachbildung der Grav-Klassen, die spamschutz.php braucht. Sie bildet nur das ab,
 * was das Plugin tatsächlich aufruft; alles andere fehlt absichtlich.
 */
declare(strict_types=1);

namespace Grav\Common {
    class Plugin
    {
        /** @var array<string, array{0:string,1:int}> was enable() eingeschaltet hat */
        public array $aktiviert = [];
        public bool $admin = false;

        public function __construct(public string $name, protected $grav, protected $config)
        {
        }

        public function isAdmin(): bool
        {
            return $this->admin;
        }

        public function enable(array $events): void
        {
            $this->aktiviert = $events;
        }
    }
}

namespace Grav\Common\Data {
    class ValidationException extends \RuntimeException
    {
    }
}

namespace RocketTheme\Toolbox\Event {
    class Event implements \ArrayAccess
    {
        public function __construct(private array $daten = [])
        {
        }

        public function offsetExists(mixed $k): bool { return isset($this->daten[$k]); }
        public function offsetGet(mixed $k): mixed { return $this->daten[$k] ?? null; }
        public function offsetSet(mixed $k, mixed $v): void { $this->daten[$k] = $v; }
        public function offsetUnset(mixed $k): void { unset($this->daten[$k]); }
    }
}

namespace SpamschutzTest {
    /** Konfiguration mit Punkt-Pfaden wie Grav\Common\Config\Config::get(). */
    class Konfig
    {
        public function __construct(private array $daten)
        {
        }

        public function get(string $pfad, mixed $standard = null): mixed
        {
            $w = $this->daten;
            foreach (explode('.', $pfad) as $teil) {
                if (!is_array($w) || !array_key_exists($teil, $w)) {
                    return $standard;
                }
                $w = $w[$teil];
            }
            return $w;
        }
    }

    /** Formular wie Grav\Plugin\Form\Form: fields(), value(), name(). */
    class Formular
    {
        /** @param array<string, array{type?:string, name?:string}> $felder */
        public function __construct(private string $name, private array $felder, private array $werte)
        {
        }

        public function fields(): array { return $this->felder; }
        public function value(string $name): mixed { return $this->werte[$name] ?? null; }
        public function name(): string { return $this->name; }
    }

    /** Seite mit Frontmatter-Header. */
    class Seite
    {
        public function __construct(private object $kopf)
        {
        }

        public function header(): object { return $this->kopf; }
    }
}
