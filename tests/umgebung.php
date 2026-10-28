<?php
/**
 * Gemeinsame Umgebung der Spamschutz-Tests: lädt das Plugin mit den Grav-Nachbildungen,
 * legt einen leeren user/-Ordner im System-Temp an und bietet einsenden() für eine Einsendung.
 *
 * SPAMSCHUTZ_ORDNER=/pfad/zum/plugin prüft eine andere Kopie (z. B. eine absichtlich kaputte).
 */
declare(strict_types=1);

require __DIR__ . '/hilfen.php';
require __DIR__ . '/stubs.php';

use Grav\Common\Data\ValidationException;
use Grav\Plugin\SpamschutzPlugin;
use RocketTheme\Toolbox\Event\Event;
use SpamschutzTest\Formular;
use SpamschutzTest\Konfig;

$PLUGIN_ORDNER = getenv('SPAMSCHUTZ_ORDNER') ?: dirname(__DIR__);
require $PLUGIN_ORDNER . '/spamschutz.php';

define('USER_DIR', tempOrdner('spamschutz-test-') . '/user/');
mkdir(USER_DIR, 0700, true);

/** Standardwerte aus der mitgelieferten spamschutz.yaml, wie sie das Plugin ausliefert. */
function standard(): array
{
    static $cfg;
    return $cfg ??= ladeYaml($GLOBALS['PLUGIN_ORDNER'] . '/spamschutz.yaml');
}

function datenOrdner(): string
{
    return rtrim(USER_DIR, '/') . '/data/spamschutz';
}

/** Löscht Protokoll und Takt-Zähler, damit eine Prüfung unabhängig von den anderen ist. */
function frisch(): void
{
    loesche(datenOrdner());
}

/** @return array<int, array> alle Protokollzeilen */
function protokoll(): array
{
    $datei = datenOrdner() . '/protokoll.log';
    if (!is_file($datei)) {
        return [];
    }
    return array_map(static fn($z) => json_decode($z, true), file($datei, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
}

function neuesPlugin(array $einstellungen = [], array $grav = []): SpamschutzPlugin
{
    $cfg = new Konfig(['plugins' => ['spamschutz' => array_replace(standard(), $einstellungen)]]);
    return new SpamschutzPlugin('spamschutz', $grav, $cfg);
}

/**
 * Eine Einsendung prüfen.
 *
 * @param array<string, string|array{0:string,1:string}> $felder Name => Wert (Typ text) oder [Typ, Wert]
 * @param array{sekunden?:int|null, ip?:string, server?:array, einstellungen?:array, formular?:string} $o
 *        sekunden: so lange vor dem Absenden wurde die Seite gezeigt; null = keine Sitzung
 * @return array{abgelehnt:bool, meldung:?string, punkte:int, gruende:array, eintrag:?array}
 */
function einsenden(array $felder, array $o = []): array
{
    $definition = [];
    $werte = [];
    foreach ($felder as $name => $f) {
        [$typ, $wert] = is_array($f) ? $f : ['text', $f];
        $definition[$name] = ['type' => $typ, 'name' => $name];
        $werte[$name] = $wert;
    }
    $sekunden = array_key_exists('sekunden', $o) ? $o['sekunden'] : 30;
    $sitzung = null;
    if ($sekunden !== null) {
        $sitzung = new \stdClass();
        $sitzung->spamschutz_zeit = time() - $sekunden;
    }
    // Adressen der Anfrage: REMOTE_ADDR wie vom Webserver, dazu ggf. Proxy-Köpfe
    unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP']);
    $_SERVER = array_replace($_SERVER, ['REMOTE_ADDR' => $o['ip'] ?? '198.51.100.7'], $o['server'] ?? []);

    $plugin = neuesPlugin($o['einstellungen'] ?? [], ['session' => $sitzung]);
    $vorher = count(protokoll());
    $meldung = null;
    try {
        $plugin->pruefen(new Event(['form' => new Formular($o['formular'] ?? 'kontakt', $definition, $werte)]));
    } catch (ValidationException $e) {
        $meldung = $e->getMessage();
    }
    $neu = array_slice(protokoll(), $vorher);
    $eintrag = $neu ? end($neu) : null;
    return [
        'abgelehnt' => $meldung !== null,
        'meldung'   => $meldung,
        'punkte'    => $eintrag['punkte'] ?? 0,
        'gruende'   => $eintrag['gruende'] ?? [],
        'eintrag'   => $eintrag,
    ];
}

/** Ein Kontaktformular wie auf den Vereinsseiten: Name, E-Mail, Nachricht, Honigtopf. */
function kontakt(string $nachricht, string $name = 'Anna Beispiel', string $email = 'anna@beispiel.at'): array
{
    return [
        'name'      => $name,
        'email'     => ['email', $email],
        'nachricht' => ['textarea', $nachricht],
        'website'   => ['honeypot', ''],
    ];
}

/** Aufruf einer privaten Methode, für Teile, die sonst nur zufällig laufen (Protokoll kürzen). */
function privat(object $obj, string $methode, mixed ...$args): mixed
{
    $m = new \ReflectionMethod($obj, $methode);
    return $m->invoke($obj, ...$args);
}
