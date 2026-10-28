<?php
/**
 * Punktwertung und Schwelle: echte Nachrichten kommen durch, typischer Spam nicht.
 * Aufruf: php tests/punkte.php (Exit-Code 1, wenn eine Prüfung scheitert)
 *
 * Alle Namen, Adressen und Texte sind erfunden.
 */
declare(strict_types=1);

require __DIR__ . '/umgebung.php';

echo "Spamschutz: Punktwertung\n\n";

// Eine Anmeldung zum Boccia-Turnier: Teamname, Ansprechperson, Spielerliste
function anmeldung(string $anmerkung = ''): array
{
    return [
        'team'           => 'Kugelblitz Oberdorf',
        'ansprechperson' => 'Max Muster',
        'email'          => ['email', 'max@beispiel.at'],
        'telefon'        => ['tel', '+43 660 0000000'],
        'spieler'        => ['textarea', "Max Muster\nLisa Lustig\nOtto Oberhuber\nPaula Probst"],
        'anmerkung'      => ['textarea', $anmerkung],
        'einverstanden'  => ['checkbox', '1'],
        'website'        => ['honeypot', ''],
    ];
}

pruefe('Auslieferungszustand: Schwelle 6, Mindestzeit 4 s, 8 pro Stunde, Proxy aus, 30 Tage Protokoll', function () {
    $s = standard();
    gleich(6, $s['schwelle'], 'schwelle');
    gleich(4, $s['mindestzeit'], 'mindestzeit');
    gleich(8, $s['max_pro_stunde'], 'max_pro_stunde');
    gleich(false, $s['proxy_vertrauen'], 'proxy_vertrauen');
    gleich(30, $s['protokoll_tage'], 'protokoll_tage');
    gleich(true, $s['sprachpruefung'], 'sprachpruefung');
    wahr(in_array('und', $s['alltagswoerter'], true) && in_array('für', $s['alltagswoerter'], true), 'Alltagswörter fehlen');
    wahr(in_array('quería saber tu precio', $s['begriffe'], true), 'typischer Spam-Satz fehlt in begriffe');
    wahr(count($s['begriffe']) >= 20, 'begriffe zu kurz: ' . count($s['begriffe']));
});

// --- echte Nachrichten ------------------------------------------------------

pruefe('deutsche Anfrage: 0 Punkte, durch, kein Protokolleintrag', function () {
    frisch();
    $r = einsenden(kontakt('Servus, wir würden gerne beim Krampuslauf mitgehen. Wann ist heuer die Anmeldung?'));
    gleich(false, $r['abgelehnt'], 'abgelehnt');
    gleich(0, $r['punkte'], 'punkte');
    gleich(null, $r['eintrag'], 'Protokoll');
});

pruefe('Anmeldung mit Namensliste: 2 Punkte (kein Alltagswort), durch', function () {
    frisch();
    $r = einsenden(anmeldung(), ['formular' => 'boccia-2026']);
    gleich(false, $r['abgelehnt']);
    gleich(2, $r['punkte']);
    gleich(['kein deutsches Wort'], $r['gruende']);
    gleich('durchgelassen', $r['eintrag']['ergebnis']);
});

pruefe('Anmeldung mit Link zur Vereinsseite: 4 Punkte, durch', function () {
    frisch();
    $r = einsenden(anmeldung('Unsere Seite: www.kugelblitz-oberdorf.at'));
    gleich(false, $r['abgelehnt']);
    gleich(4, $r['punkte']);
    gleich(['1 Link', 'kein deutsches Wort'], $r['gruende']);
});

pruefe('Anmeldung nach abgelaufener Sitzung: 4 Punkte, durch', function () {
    frisch();
    $r = einsenden(anmeldung(), ['sekunden' => null]);
    gleich(false, $r['abgelehnt']);
    gleich(4, $r['punkte']);
});

pruefe('englische Anfrage von Gästen: 2 Punkte, durch', function () {
    frisch();
    $r = einsenden(kontakt('Hello, we are a family of four and would like to know when the Krampus run takes place this year.', 'John Example', 'john@example.com'));
    gleich(false, $r['abgelehnt']);
    gleich(2, $r['punkte']);
    gleich(['kein deutsches Wort'], $r['gruende']);
});

pruefe('deutsche Nachricht mit einem Link: 2 Punkte, durch', function () {
    frisch();
    $r = einsenden(kontakt('Hallo, hier ist das Video vom Lauf am Samstag: www.beispiel-video.at/lauf'));
    gleich(false, $r['abgelehnt']);
    gleich(2, $r['punkte']);
    gleich(['1 Link'], $r['gruende']);
});

pruefe('kurze Texte werden nicht auf die Sprache geprüft', function () {
    frisch();
    gleich(0, einsenden(kontakt('Thanks, see you there!'))['punkte']);
});

pruefe('Großschreibung spielt keine Rolle, Begriffe zählen höchstens 4 Punkte', function () {
    frisch();
    $r = einsenden(kontakt('Ich habe eine Frage zu CASINO, Viagra, Crypto, Bitcoin und Porn auf eurer Seite.'));
    gleich(4, $r['punkte']);
    gleich(false, $r['abgelehnt']);
    foreach (['casino', 'viagra', 'crypto', 'bitcoin', 'porn'] as $b) {
        wahr(str_contains($r['gruende'][0], $b), "Begriff $b fehlt in " . $r['gruende'][0]);
    }
});

// --- typischer Spam ---------------------------------------------------------

pruefe('„Hola, quería saber tu precio.“: 6 Punkte, abgelehnt, obwohl langsam getippt', function () {
    frisch();
    $r = einsenden(kontakt('Hola, quería saber tu precio.', 'Robert', 'robert@example.com'), ['sekunden' => 120]);
    gleich(true, $r['abgelehnt']);
    gleich(6, $r['punkte']);
    gleich('ABGELEHNT', $r['eintrag']['ergebnis']);
});

pruefe('SEO-Spam mit Links und Werbebegriffen: 10 Punkte, abgelehnt', function () {
    frisch();
    $r = einsenden(kontakt('Hello! I can increase your sales with our SEO service and backlink packages. '
        . 'Visit https://seo-angebot.example and see www.backlinks.example now. Best regards, Mr Smith', 'Mr Smith', 'smith@example.com'));
    gleich(true, $r['abgelehnt']);
    gleich(10, $r['punkte']);
});

pruefe('kyrillischer Text: allein 5 Punkte (durch), mit Link 7 (abgelehnt)', function () {
    frisch();
    $ohne = einsenden(kontakt('Здравствуйте! Предлагаем продвижение вашего сайта в поиске.'));
    gleich(false, $ohne['abgelehnt']);
    gleich(5, $ohne['punkte']);
    $mit = einsenden(kontakt('Здравствуйте! Предлагаем продвижение сайта: http://seo.example'));
    gleich(true, $mit['abgelehnt']);
    gleich(7, $mit['punkte']);
});

pruefe('BBCode mit Link in einer sonst deutschen Nachricht: 6 Punkte, abgelehnt', function () {
    frisch();
    $r = einsenden(kontakt('Hallo, hier ist ein Gewinn für dich [url=http://spiel.example]klick[/url]'));
    gleich(true, $r['abgelehnt']);
    gleich(6, $r['punkte']);
    wahr(in_array('BBCode', $r['gruende'], true), 'BBCode nicht erkannt');
});

pruefe('genau an der Schwelle wird abgelehnt, einen Punkt darunter nicht', function () {
    frisch();
    // Namensliste (2) + Link (2) + ohne Sitzung (2) = 6
    $an = einsenden(anmeldung('Unsere Seite: www.kugelblitz-oberdorf.at'), ['sekunden' => null]);
    gleich(6, $an['punkte']);
    gleich(true, $an['abgelehnt']);
    $unter = einsenden(anmeldung('Unsere Seite: www.kugelblitz-oberdorf.at'), ['sekunden' => null, 'einstellungen' => ['schwelle' => 7]]);
    gleich(6, $unter['punkte']);
    gleich(false, $unter['abgelehnt']);
});

// Bekannter Fehler: Eine einzige Adresse wie https://www.beispiel.at trifft zwei Teile des Link-Musters
// ("https://" und "www.beispiel.at") und zählt darum als zwei Links (4 statt 2 Punkte). Eine Anmeldung
// mit Namensliste (2) und der Vereinsadresse mit https://www. erreicht so 6 Punkte und wird abgelehnt.
bekannterFehler('eine Adresse mit https://www. zählt als ein Link', function () {
    frisch();
    $r = einsenden(kontakt('Hallo, hier ist das Video vom Lauf am Samstag: https://www.beispiel-video.at/lauf'));
    gleich(['1 Link'], $r['gruende']);
    gleich(2, $r['punkte']);
});

// --- Tempo als Bot-Zeichen --------------------------------------------------

pruefe('zu schnell abgeschickt bringt so viele Punkte wie die Schwelle und lehnt allein ab', function () {
    frisch();
    $r = einsenden(kontakt('Servus, wann ist die nächste Probe für den Lauf?'), ['sekunden' => 1]);
    gleich(true, $r['abgelehnt']);
    gleich(6, $r['punkte']);
    wahr((bool)preg_match('/^in [12]s abgeschickt$/', $r['gruende'][0]), 'Grund: ' . $r['gruende'][0]);
    $hoch = einsenden(kontakt('Servus, wann ist die nächste Probe für den Lauf?'), ['sekunden' => 1, 'einstellungen' => ['schwelle' => 10]]);
    gleich(10, $hoch['punkte']);
    gleich(true, $hoch['abgelehnt']);
});

pruefe('ohne Sitzung: 2 Punkte, auch wenn die Sitzung keinen Zeitstempel hat', function () {
    frisch();
    $r = einsenden(kontakt('Servus, wann ist die nächste Probe für den Lauf?'), ['sekunden' => null]);
    gleich(2, $r['punkte']);
    gleich(['ohne Sitzung abgeschickt'], $r['gruende']);
    // Sitzung vorhanden, aber das Formular wurde nie ausgeliefert
    $plugin = neuesPlugin([], ['session' => new \stdClass()]);
    $vorher = count(protokoll());
    $plugin->pruefen(new \RocketTheme\Toolbox\Event\Event(['form' => new \SpamschutzTest\Formular('kontakt',
        ['nachricht' => ['type' => 'textarea', 'name' => 'nachricht']], ['nachricht' => 'Servus, wann ist die nächste Probe?'])]));
    gleich(['ohne Sitzung abgeschickt'], array_slice(protokoll(), $vorher)[0]['gruende']);
});

// --- Meldung, Sonderfälle ---------------------------------------------------

pruefe('Ablehnung: Grav\Common\Data\ValidationException mit dem eingestellten Text', function () {
    frisch();
    $r = einsenden(kontakt('Hola, quería saber tu precio.'));
    gleich(standard()['melde_text'], $r['meldung']);
    $eigen = einsenden(kontakt('Hola, quería saber tu precio.'), ['einstellungen' => ['melde_text' => 'Bitte per Mail.']]);
    gleich('Bitte per Mail.', $eigen['meldung']);
});

pruefe('Formular ohne Textfelder wird nicht bewertet', function () {
    frisch();
    $r = einsenden(['zustimmung' => ['checkbox', '1'], 'anzahl' => ['number', '4']], ['sekunden' => 0]);
    gleich(false, $r['abgelehnt']);
    gleich(null, $r['eintrag']);
});

pruefe('fehlende Einstellungen fallen auf dieselben Werte wie die Auslieferung zurück', function () {
    frisch();
    $cfg = new \SpamschutzTest\Konfig(['plugins' => ['spamschutz' => ['enabled' => true]]]);
    $sitzung = new \stdClass();
    $sitzung->spamschutz_zeit = time() - 1;
    $plugin = new \Grav\Plugin\SpamschutzPlugin('spamschutz', ['session' => $sitzung], $cfg);
    try {
        $plugin->pruefen(new \RocketTheme\Toolbox\Event\Event(['form' => new \SpamschutzTest\Formular('kontakt',
            ['nachricht' => ['type' => 'textarea', 'name' => 'nachricht']], ['nachricht' => 'Servus, wann ist die Probe?'])]));
        throw new PruefFehler('nicht abgelehnt');
    } catch (\Grav\Common\Data\ValidationException $e) {
        wahr(str_contains($e->getMessage(), 'E-Mail'), 'Ersatztext fehlt: ' . $e->getMessage());
    }
    $eintraege = protokoll();
    gleich(6, end($eintraege)['punkte'], 'Schwelle ohne Einstellung');
});

fertig();
