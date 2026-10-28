<?php
/**
 * Kleine Prüfhilfe ohne Composer/PHPUnit: pruefe() führt eine Prüfung aus, fertig() meldet das
 * Ergebnis und beendet mit Exit-Code 1, wenn etwas fehlgeschlagen ist.
 */
declare(strict_types=1);

final class PruefFehler extends \RuntimeException {}

// Warnungen und Hinweise lassen eine Prüfung scheitern (mit @ unterdrückte nicht)
set_error_handler(static function (int $nr, string $text, string $datei, int $zeile): bool {
    if (!(error_reporting() & $nr)) {
        return false;
    }
    throw new \ErrorException($text, 0, $nr, $datei, $zeile);
});

$GLOBALS['__ergebnis'] = ['ok' => 0, 'fehler' => [], 'skript' => basename($_SERVER['argv'][0] ?? 'tests')];

function pruefe(string $name, callable $f): void
{
    try {
        $f();
        $GLOBALS['__ergebnis']['ok']++;
        echo "ok      $name\n";
    } catch (\Throwable $e) {
        $wo = $e instanceof PruefFehler ? '' : ' [' . get_class($e) . ' in ' . basename($e->getFile()) . ':' . $e->getLine() . ']';
        $GLOBALS['__ergebnis']['fehler'][] = $name;
        echo "FEHLER  $name\n        " . $e->getMessage() . $wo . "\n";
    }
}

/**
 * Bekannter Fehler: Die Prüfung beschreibt das gewünschte Verhalten und scheitert derzeit.
 * Besteht sie eines Tages, ist der Fehler behoben; dann meldet sie sich als Fehler, damit sie
 * zu einer normalen pruefe() umgestellt wird.
 */
function bekannterFehler(string $name, callable $f): void
{
    try {
        $f();
    } catch (\Throwable $e) {
        $GLOBALS['__ergebnis']['ok']++;
        echo "bekannt $name\n        (bekannter Fehler, scheitert wie erwartet: " . $e->getMessage() . ")\n";
        return;
    }
    $GLOBALS['__ergebnis']['fehler'][] = $name;
    echo "FEHLER  $name\n        bekannter Fehler ist behoben: bitte zu pruefe() umstellen\n";
}

function gleich(mixed $erwartet, mixed $ist, string $was = ''): void
{
    if ($erwartet !== $ist) {
        $zeige = static fn($w) => json_encode($w, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        throw new PruefFehler(($was ? "$was: " : '') . 'erwartet ' . $zeige($erwartet) . ', erhalten ' . $zeige($ist));
    }
}

function wahr(bool $bedingung, string $was): void
{
    if (!$bedingung) {
        throw new PruefFehler($was);
    }
}

function fertig(): never
{
    $e = $GLOBALS['__ergebnis'];
    $n = count($e['fehler']);
    echo "\n" . $e['skript'] . ': ' . $e['ok'] . ' bestanden, ' . $n . " fehlgeschlagen\n";
    exit($n ? 1 : 0);
}

/**
 * Liest die flachen YAML-Dateien des Plugins (Skalare, [a, b]-Listen, "- x"-Listen).
 * Für mehr ist sie nicht gedacht; Unbekanntes führt zum Abbruch statt zu falschen Werten.
 */
function ladeYaml(string $datei): array
{
    $wert = static function (string $w): mixed {
        $w = trim($w);
        if ($w === 'true') return true;
        if ($w === 'false') return false;
        if (preg_match('/^-?\d+$/', $w)) return (int)$w;
        if (preg_match("/^'(.*)'$/s", $w, $m)) return str_replace("''", "'", $m[1]);
        if (preg_match('/^"(.*)"$/s', $w, $m)) return stripcslashes($m[1]);
        return $w;
    };
    $daten = [];
    $liste = null;
    foreach (file($datei, FILE_IGNORE_NEW_LINES) as $nr => $zeile) {
        if (trim($zeile) === '' || str_starts_with(ltrim($zeile), '#')) {
            continue;
        }
        if ($liste !== null && preg_match('/^\s+-\s+(.*)$/', $zeile, $m)) {
            $daten[$liste][] = $wert($m[1]);
            continue;
        }
        if (!preg_match('/^([a-z_]+):\s*(.*)$/', $zeile, $m)) {
            throw new \RuntimeException("$datei:" . ($nr + 1) . ': unbekanntes YAML');
        }
        $liste = null;
        if ($m[2] === '') {
            $liste = $m[1];
            $daten[$liste] = [];
        } elseif (preg_match('/^\[(.*)\]$/', $m[2], $l)) {
            $daten[$m[1]] = array_map($wert, explode(',', $l[1]));
        } else {
            $daten[$m[1]] = $wert($m[2]);
        }
    }
    return $daten;
}

/** Leerer Arbeitsordner unter dem System-Temp, wird am Ende wieder gelöscht. */
function tempOrdner(string $praefix): string
{
    $ordner = sys_get_temp_dir() . '/' . $praefix . bin2hex(random_bytes(4));
    mkdir($ordner, 0700, true);
    register_shutdown_function(static fn() => loesche($ordner));
    return $ordner;
}

function loesche(string $pfad): void
{
    if (is_dir($pfad) && !is_link($pfad)) {
        foreach (scandir($pfad) ?: [] as $e) {
            if ($e !== '.' && $e !== '..') {
                loesche("$pfad/$e");
            }
        }
        @rmdir($pfad);
    } elseif (file_exists($pfad) || is_link($pfad)) {
        @unlink($pfad);
    }
}
