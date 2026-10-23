<?php
/**
 * Spamschutz für die Kontaktformulare.
 *
 * Der Honigtopf des Form-Plugins reicht nicht mehr: die Bots rendern die Seite
 * richtig und lassen das versteckte Feld leer. Dieses Plugin bewertet darum den
 * Inhalt der Einsendung nach Punkten und lehnt erst ab einer Schwelle ab, damit
 * eine echte Nachricht nicht an einer einzelnen Auffälligkeit scheitert.
 *
 * Es wird an allen vier Seiten gleich eingesetzt. Quelle:
 *   /usr/local/share/grav-spamschutz  ->  verteilt mit grav-spamschutz-deploy.sh
 */
namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\Data\ValidationException;
use RocketTheme\Toolbox\Event\Event;

class SpamschutzPlugin extends Plugin
{
    public static function getSubscribedEvents(): array
    {
        return ['onPluginsInitialized' => ['onPluginsInitialized', 0]];
    }

    public function onPluginsInitialized(): void
    {
        if ($this->isAdmin()) {
            return;
        }
        $this->enable([
            // nach dem Honigtopf des Form-Plugins pruefen
            'onFormValidationProcessed' => ['pruefen', -100],
            'onTwigSiteVariables'       => ['zeitMerken', 0],
        ]);
    }

    /** Wann wurde das Formular ausgeliefert? Zu schnelles Absenden ist ein Bot. */
    public function zeitMerken(): void
    {
        $page = $this->grav['page'] ?? null;
        if (!$page) {
            return;
        }
        $header = $page->header();
        if (empty($header->form) && empty($header->forms)) {
            return;
        }
        $session = $this->grav['session'] ?? null;
        if ($session) {
            $session->spamschutz_zeit = time();
        }
    }

    public function pruefen(Event $event): void
    {
        $cfg     = $this->config->get('plugins.spamschutz');
        $form    = $event['form'];
        $punkte  = 0;
        $gruende = [];

        // --- alle Texteingaben einsammeln ---------------------------------
        $texte = [];
        foreach ($form->fields() as $name => $feld) {
            $typ = $feld['type'] ?? 'text';
            if (in_array($typ, ['text', 'textarea', 'email', 'tel', 'url', 'select'], true)) {
                $wert = $form->value($feld['name'] ?? $name);
                if (is_string($wert) && $wert !== '') {
                    $texte[$feld['name'] ?? $name] = $wert;
                }
            }
        }
        $alles = implode("\n", $texte);
        if ($alles === '') {
            return;
        }
        $klein = mb_strtolower($alles);

        // --- fremde Schriftsysteme ----------------------------------------
        if (preg_match('/[\x{0400}-\x{04FF}\x{0370}-\x{03FF}\x{0590}-\x{05FF}\x{0600}-\x{06FF}\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}]/u', $alles)) {
            $punkte += 3; $gruende[] = 'fremdes Schriftsystem';
        }

        // --- Links ---------------------------------------------------------
        $links = preg_match_all('#(https?://|www\.[a-z0-9-]+\.[a-z]{2,}|\b[a-z0-9-]+\.(?:ru|cn|top|xyz|click|shop|online|site|info|biz)\b)#i', $alles);
        if ($links === 1) { $punkte += 2; $gruende[] = '1 Link'; }
        elseif ($links > 1) { $punkte += 4; $gruende[] = $links . ' Links'; }
        if (preg_match('/\[(url|link|b)[=\]]/i', $alles)) {
            $punkte += 4; $gruende[] = 'BBCode';
        }

        // --- typische Werbebegriffe ----------------------------------------
        $treffer = [];
        foreach ((array)($cfg['begriffe'] ?? []) as $begriff) {
            $begriff = mb_strtolower(trim((string)$begriff));
            if ($begriff !== '' && str_contains($klein, $begriff)) {
                $treffer[] = $begriff;
            }
        }
        if ($treffer) {
            $punkte += min(4, 2 * count($treffer));
            $gruende[] = 'Begriffe: ' . implode(', ', array_slice($treffer, 0, 5));
        }

        // --- Sprache: laengster Text ohne ein einziges deutsches Allerweltswort
        $langtext = '';
        foreach ($texte as $wert) {
            if (mb_strlen($wert) > mb_strlen($langtext)) {
                $langtext = $wert;
            }
        }
        if (mb_strlen($langtext) > 25) {
            $deutsch = ['der','die','das','und','ist','sind','wir','ich','mit','für','fuer','nicht','ein','eine','einen','bei','auf','von','zu','habe','hallo','servus','guten','gerne','bitte','danke','würde','wuerde','möchte','moechte','können','koennen','anfrage','feuerwehr','verein','sie','ihr','uns','mich','mir','am','im','an','dass','wie','was','oder'];
            $worte = preg_split('/[^\p{L}äöüß]+/u', mb_strtolower($langtext), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (!array_intersect($worte, $deutsch)) {
                $punkte += 2; $gruende[] = 'kein deutsches Wort';
            }
        }

        // Tempo und Masse sind die sichersten Bot-Zeichen: Sie bringen so viele Punkte
        // wie die Schwelle und lehnen damit allein ab. Alles andere braucht mehrere Treffer.
        $schwelle = (int)($cfg['schwelle'] ?? 6);

        // --- Formular zu schnell abgeschickt --------------------------------
        $session = $this->grav['session'] ?? null;
        $seit    = $session ? (int)($session->spamschutz_zeit ?? 0) : 0;
        $mindest = (int)($cfg['mindestzeit'] ?? 4);
        if ($seit > 0) {
            $dauer = time() - $seit;
            if ($dauer < $mindest) {
                $punkte += $schwelle; $gruende[] = "in {$dauer}s abgeschickt";
            }
        } else {
            $punkte += 2; $gruende[] = 'ohne Sitzung abgeschickt';
        }

        // --- zu viele Einsendungen von derselben Adresse ---------------------
        $ip = $this->ipAdresse();
        if ($this->zuOft($ip, (int)($cfg['max_pro_stunde'] ?? 5))) {
            $punkte += $schwelle; $gruende[] = 'zu viele Einsendungen';
        }

        if ($punkte >= $schwelle) {
            $this->protokollieren('ABGELEHNT', $punkte, $gruende, $form->name(), $ip, $langtext);
            throw new ValidationException($cfg['melde_text'] ?? 'Deine Nachricht wurde vom Spamschutz abgelehnt. Bitte schick sie uns direkt per E-Mail.');
        }
        if ($punkte > 0) {
            $this->protokollieren('durchgelassen', $punkte, $gruende, $form->name(), $ip, $langtext);
        }
    }

    private function ipAdresse(): string
    {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(',', (string)$_SERVER[$k])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '';
    }

    /** Zaehlt die Einsendungen je Adresse in der letzten Stunde. */
    private function zuOft(string $ip, int $max): bool
    {
        if ($ip === '' || $max <= 0) {
            return false;
        }
        $datei = $this->datenOrdner() . '/takt.json';
        $jetzt = time();
        $liste = is_file($datei) ? (json_decode((string)file_get_contents($datei), true) ?: []) : [];
        foreach ($liste as $k => $zeiten) {
            $liste[$k] = array_values(array_filter((array)$zeiten, static fn($t) => $jetzt - (int)$t < 3600));
            if (!$liste[$k]) {
                unset($liste[$k]);
            }
        }
        $eigene = $liste[$ip] ?? [];
        $eigene[] = $jetzt;
        $liste[$ip] = $eigene;
        @file_put_contents($datei, json_encode($liste), LOCK_EX);
        return count($eigene) > $max;
    }

    private function datenOrdner(): string
    {
        $ordner = rtrim(USER_DIR, '/') . '/data/spamschutz';
        if (!is_dir($ordner)) {
            @mkdir($ordner, 0775, true);
        }
        return $ordner;
    }

    private function protokollieren(string $was, int $punkte, array $gruende, string $formular, string $ip, string $text): void
    {
        $zeile = json_encode([
            'zeit'     => date('c'),
            'ergebnis' => $was,
            'punkte'   => $punkte,
            'gruende'  => $gruende,
            'formular' => $formular,
            'ip'       => $ip,
            'text'     => mb_substr($text, 0, 400),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        @file_put_contents($this->datenOrdner() . '/protokoll.log', $zeile . "\n", FILE_APPEND | LOCK_EX);
    }
}
