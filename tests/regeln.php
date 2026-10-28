<?php
/**
 * Zeit- und Rate-Regeln, Einstellungen (Sprachprüfung, Begriffe, Proxy) und Protokoll.
 * Aufruf: php tests/regeln.php (Exit-Code 1, wenn eine Prüfung scheitert)
 */
declare(strict_types=1);

require __DIR__ . '/umgebung.php';

use RocketTheme\Toolbox\Event\Event;
use SpamschutzTest\Seite;

echo "Spamschutz: Regeln und Einstellungen\n\n";

const HARMLOS = 'Servus, wann ist die nächste Probe für den Lauf?';

// --- Mindestzeit ------------------------------------------------------------

pruefe('Mindestzeit 4 s: nach 2 s abgelehnt, nach 4 s durch', function () {
    frisch();
    gleich(true, einsenden(kontakt(HARMLOS), ['sekunden' => 2])['abgelehnt']);
    $r = einsenden(kontakt(HARMLOS), ['sekunden' => 4]);
    gleich(false, $r['abgelehnt']);
    gleich(0, $r['punkte']);
});

pruefe('Mindestzeit ist einstellbar', function () {
    frisch();
    gleich(true, einsenden(kontakt(HARMLOS), ['sekunden' => 8, 'einstellungen' => ['mindestzeit' => 10]])['abgelehnt']);
    gleich(false, einsenden(kontakt(HARMLOS), ['sekunden' => 10, 'einstellungen' => ['mindestzeit' => 10]])['abgelehnt']);
});

pruefe('Zeitpunkt merken: nur auf Seiten mit Formular, ohne Seite oder Sitzung kein Fehler', function () {
    $mitForm = new Seite((object)['form' => ['name' => 'kontakt']]);
    $mitForms = new Seite((object)['forms' => ['a' => []]]);
    $ohne = new Seite((object)['title' => 'Über uns']);
    foreach ([[$mitForm, true], [$mitForms, true], [$ohne, false]] as [$seite, $erwartet]) {
        $sitzung = new \stdClass();
        neuesPlugin([], ['page' => $seite, 'session' => $sitzung])->zeitMerken();
        gleich($erwartet, isset($sitzung->spamschutz_zeit));
        if ($erwartet) {
            wahr(abs(time() - $sitzung->spamschutz_zeit) <= 1, 'Zeitstempel nicht aktuell');
        }
    }
    neuesPlugin([], ['page' => $mitForm])->zeitMerken();
    neuesPlugin([], [])->zeitMerken();
});

// --- Einsendungen je Adresse und Stunde -------------------------------------

pruefe('8 Einsendungen je Adresse und Stunde gehen durch, die 9. nicht, andere Adressen schon', function () {
    frisch();
    for ($i = 1; $i <= 8; $i++) {
        gleich(false, einsenden(kontakt(HARMLOS), ['ip' => '198.51.100.20'])['abgelehnt'], "Einsendung $i");
    }
    $neunte = einsenden(kontakt(HARMLOS), ['ip' => '198.51.100.20']);
    gleich(true, $neunte['abgelehnt']);
    gleich(['zu viele Einsendungen'], $neunte['gruende']);
    gleich(6, $neunte['punkte']);
    gleich(false, einsenden(kontakt(HARMLOS), ['ip' => '198.51.100.21'])['abgelehnt']);
});

pruefe('Einsendungen älter als eine Stunde zählen nicht mehr und verschwinden aus takt.json', function () {
    frisch();
    @mkdir(datenOrdner(), 0700, true);
    $alt = array_fill(0, 20, time() - 3700);
    file_put_contents(datenOrdner() . '/takt.json', json_encode(['198.51.100.30' => $alt, '198.51.100.31' => $alt]));
    gleich(false, einsenden(kontakt(HARMLOS), ['ip' => '198.51.100.30'])['abgelehnt']);
    $takt = json_decode(file_get_contents(datenOrdner() . '/takt.json'), true);
    gleich(['198.51.100.30'], array_keys($takt));
    gleich(1, count($takt['198.51.100.30']));
});

pruefe('max_pro_stunde 0 schaltet die Grenze ab', function () {
    frisch();
    for ($i = 1; $i <= 20; $i++) {
        gleich(false, einsenden(kontakt(HARMLOS), ['ip' => '198.51.100.40', 'einstellungen' => ['max_pro_stunde' => 0]])['abgelehnt'], "Einsendung $i");
    }
});

// --- Adresse hinter einem Proxy ----------------------------------------------

pruefe('proxy_vertrauen aus: X-Forwarded-For wird ignoriert, die Grenze lässt sich nicht umgehen', function () {
    frisch();
    for ($i = 1; $i <= 9; $i++) {
        $r = einsenden(kontakt(HARMLOS), ['ip' => '198.51.100.50', 'server' => ['HTTP_X_FORWARDED_FOR' => "192.0.2.$i"]]);
    }
    gleich(true, $r['abgelehnt'], '9. Einsendung mit wechselndem X-Forwarded-For');
    gleich('198.51.100.50', $r['eintrag']['ip']);
});

pruefe('proxy_vertrauen an: X-Forwarded-For bzw. X-Real-IP zählen, ungültige Werte nicht', function () {
    frisch();
    $an = ['proxy_vertrauen' => true];
    for ($i = 1; $i <= 9; $i++) {
        $r = einsenden(kontakt(HARMLOS), ['ip' => '127.0.0.1', 'sekunden' => null, 'einstellungen' => $an,
            'server' => ['HTTP_X_FORWARDED_FOR' => "192.0.2.$i"]]);
        gleich(false, $r['abgelehnt'], "Einsendung $i");
    }
    gleich('192.0.2.9', $r['eintrag']['ip']);
    $real = einsenden(kontakt(HARMLOS), ['ip' => '127.0.0.1', 'sekunden' => null, 'einstellungen' => $an,
        'server' => ['HTTP_X_REAL_IP' => '192.0.2.77']]);
    gleich('192.0.2.77', $real['eintrag']['ip']);
    $kaputt = einsenden(kontakt(HARMLOS), ['ip' => '127.0.0.1', 'sekunden' => null, 'einstellungen' => $an,
        'server' => ['HTTP_X_FORWARDED_FOR' => 'kein-ip-wert']]);
    gleich('127.0.0.1', $kaputt['eintrag']['ip']);
});

// Bekannter Fehler: Hinter nginx mit $proxy_add_x_forwarded_for kommt X-Forwarded-For als
// "<Wert vom Client>, <echte Adresse>" an. Das Plugin nimmt den ersten Eintrag, also den, den der
// Client selbst setzen kann; mit wechselnden Werten umgeht er die Grenze. Richtig wäre der letzte
// Eintrag (den der eigene Proxy anhängt) bzw. X-Real-IP.
bekannterFehler('proxy_vertrauen an: vorgetäuschter erster Eintrag in X-Forwarded-For umgeht die Grenze nicht', function () {
    frisch();
    for ($i = 1; $i <= 9; $i++) {
        $r = einsenden(kontakt(HARMLOS), ['ip' => '127.0.0.1', 'einstellungen' => ['proxy_vertrauen' => true],
            'server' => ['HTTP_X_FORWARDED_FOR' => "192.0.2.$i, 203.0.113.5", 'HTTP_X_REAL_IP' => '203.0.113.5']]);
    }
    gleich(true, $r['abgelehnt'], '9. Einsendung derselben echten Adresse');
});

// --- Sprachprüfung und Begriffe -------------------------------------------------

const ENGLISCH = 'Hello, we are a family of four and would like to know when the Krampus run takes place this year.';

pruefe('sprachpruefung: false schaltet die Prüfung ab', function () {
    frisch();
    gleich(2, einsenden(kontakt(ENGLISCH))['punkte']);
    gleich(0, einsenden(kontakt(ENGLISCH), ['einstellungen' => ['sprachpruefung' => false]])['punkte']);
});

pruefe('alltagswoerter: eigene Liste für eine englische Seite, leere Liste prüft nichts', function () {
    frisch();
    $englisch = ['alltagswoerter' => ['the', 'and', 'is', 'you', 'we', 'with']];
    gleich(0, einsenden(kontakt(ENGLISCH), ['einstellungen' => $englisch])['punkte']);
    gleich(2, einsenden(kontakt('Servus beinand, mia kemman morgn a bissl spata.'), ['einstellungen' => $englisch])['punkte']);
    gleich(0, einsenden(kontakt(ENGLISCH), ['einstellungen' => ['alltagswoerter' => []]])['punkte']);
});

pruefe('begriffe: eigene Liste (z. B. ohne Preisfragen für einen Betrieb)', function () {
    frisch();
    $ohnePreis = array_values(array_filter(standard()['begriffe'], static fn($b) => !str_contains($b, 'precio') && !str_contains($b, 'price')));
    $r = einsenden(kontakt('Hola, quería saber tu precio.'), ['einstellungen' => ['begriffe' => $ohnePreis]]);
    gleich(false, $r['abgelehnt']);
    gleich(['kein deutsches Wort'], $r['gruende']);
    $eigen = einsenden(kontakt('Bitte schicken Sie mir die Preisliste für Mai.'), ['einstellungen' => ['begriffe' => ['preisliste']]]);
    gleich(['Begriffe: preisliste'], $eigen['gruende']);
    gleich(2, $eigen['punkte']);
});

// --- Protokoll -----------------------------------------------------------------

pruefe('Protokoll: Ablehnungen und Beinahe-Treffer mit allen Feldern, Text höchstens 400 Zeichen', function () {
    frisch();
    $lang = 'Hola, quería saber tu precio. ' . str_repeat('Lorem ipsum dolor sit amet. ', 30);
    $r = einsenden(kontakt($lang), ['ip' => '198.51.100.60', 'formular' => 'kontakt-test']);
    $e = $r['eintrag'];
    gleich(['zeit', 'ergebnis', 'punkte', 'gruende', 'formular', 'ip', 'text'], array_keys($e));
    gleich('ABGELEHNT', $e['ergebnis']);
    gleich('kontakt-test', $e['formular']);
    gleich('198.51.100.60', $e['ip']);
    gleich(400, mb_strlen($e['text']));
    wahr(strtotime($e['zeit']) >= time() - 5, 'Zeit fehlt oder falsch');
    gleich('durchgelassen', einsenden(kontakt(ENGLISCH))['eintrag']['ergebnis']);
    $vorher = count(protokoll());
    einsenden(kontakt(HARMLOS));
    gleich($vorher, count(protokoll()), 'saubere Einsendung darf nicht ins Protokoll');
});

/** Protokoll mit Einträgen bestimmten Alters (in Tagen) anlegen. */
function altesProtokoll(array $tage): string
{
    frisch();
    @mkdir(datenOrdner(), 0700, true);
    $datei = datenOrdner() . '/protokoll.log';
    $zeilen = array_map(static fn($t) => json_encode(['zeit' => date('c', time() - $t * 86400), 'ergebnis' => 'ABGELEHNT', 'punkte' => 6, 'text' => "vor $t Tagen"]), $tage);
    file_put_contents($datei, implode("\n", $zeilen) . "\n");
    return $datei;
}

pruefe('protokoll_tage: Einträge älter als die eingestellten Tage werden entfernt', function () {
    $datei = altesProtokoll([40, 31, 29, 1]);
    privat(neuesPlugin(), 'protokollKuerzen', $datei, 30);
    gleich(['vor 29 Tagen', 'vor 1 Tagen'], array_column(protokoll(), 'text'));
    $datei = altesProtokoll([10, 3]);
    privat(neuesPlugin(), 'protokollKuerzen', $datei, 7);
    gleich(['vor 3 Tagen'], array_column(protokoll(), 'text'));
});

pruefe('protokoll_tage im Betrieb: kürzt beim Schreiben (stichprobenartig), 0 behält alles', function () {
    // Das Plugin kürzt bei etwa jedem 20. Eintrag; 400 Einträge machen das Ergebnis sicher
    $datei = altesProtokoll([60]);
    $plugin = neuesPlugin(['protokoll_tage' => 30]);
    for ($i = 0; $i < 400; $i++) {
        privat($plugin, 'protokollieren', 'durchgelassen', 2, ['Test'], 'kontakt', '198.51.100.70', 'Text');
    }
    wahr(!in_array('vor 60 Tagen', array_column(protokoll(), 'text'), true), 'alter Eintrag nicht entfernt');
    $datei = altesProtokoll([3650]);
    $plugin = neuesPlugin(['protokoll_tage' => 0]);
    for ($i = 0; $i < 400; $i++) {
        privat($plugin, 'protokollieren', 'durchgelassen', 2, ['Test'], 'kontakt', '198.51.100.70', 'Text');
    }
    gleich('vor 3650 Tagen', protokoll()[0]['text'] ?? null);
    gleich(401, count(protokoll()));
});

// --- Einbindung in Grav --------------------------------------------------------

pruefe('prüft nach dem Honigtopf (Priorität -100), merkt die Zeit beim Rendern, im Admin gar nicht', function () {
    gleich(['onPluginsInitialized' => ['onPluginsInitialized', 0]], \Grav\Plugin\SpamschutzPlugin::getSubscribedEvents());
    $p = neuesPlugin();
    $p->onPluginsInitialized();
    gleich(['onFormValidationProcessed' => ['pruefen', -100], 'onTwigSiteVariables' => ['zeitMerken', 0]], $p->aktiviert);
    $admin = neuesPlugin();
    $admin->admin = true;
    $admin->onPluginsInitialized();
    gleich([], $admin->aktiviert);
});

fertig();
