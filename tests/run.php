<?php

declare(strict_types=1);

/**
 * Funktionstests für das Modul „Wetter“ mit den offiziellen Symcon-Stubs.
 *
 * Die Antworten von Open-Meteo und Bright Sky werden als Fixtures (relativ zur
 * aktuellen Uhrzeit) erzeugt und direkt an die Verarbeitung übergeben – es wird
 * kein Netzwerk benötigt.
 *
 * Aufruf: php tests/run.php [Pfad zu SymconStubs]
 *
 * SPDX-License-Identifier: MIT
 */

$stubs = $argv[1] ?? __DIR__ . '/../../SymconStubs';
if (!is_file($stubs . '/autoload.php')) {
    // im Workflow werden die Stubs erst später geholt
    $clone = sys_get_temp_dir() . '/wetter-symconstubs';
    if (!is_file($clone . '/autoload.php')) {
        exec('git clone --depth 1 https://github.com/symcon/SymconStubs.git ' . escapeshellarg($clone) . ' 2>&1', $out, $rc);
    }
    $stubs = $clone;
}
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, 'SymconStubs nicht gefunden: ' . $stubs . PHP_EOL);
    exit(2);
}

set_error_handler(static function (int $no, string $str): bool {
    return $no === E_DEPRECATED || $no === E_USER_DEPRECATED || str_contains($str, 'could not be found');
});

// Die Stubs verlangen für Timer eine Testuhr (getTime). In eine Kopie die normale Uhrzeit eintragen.
$copy = sys_get_temp_dir() . '/wetter-stubs-' . getmypid();
@mkdir($copy);
foreach (glob($stubs . '/*.php') as $file) {
    $code = (string) file_get_contents($file);
    if (basename($file) === 'ModuleStrictStubs.php') {
        $code = str_replace(
            "throw new Exception('getTime needs to be implemented by module under test');\n    }\n}",
            "return time();\n    }\n}",
            $code
        );
    }
    file_put_contents($copy . '/' . basename($file), $code);
}
register_shutdown_function(static function () use ($copy): void {
    array_map('unlink', glob($copy . '/*.php'));
    @rmdir($copy);
});

require $copy . '/autoload.php';

\IPS\Kernel::reset();
IPS_CreateVariableProfile('~UnixTimestamp', 1);
\IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');

const MODULE = '{4389ADEF-256A-4228-9091-7DECC8A9D367}';

$failed = 0;
$passed = 0;
function ok(bool $condition, string $message): void
{
    global $failed, $passed;
    echo ($condition ? '  ✓ ' : '  ✗ ') . $message . PHP_EOL;
    $condition ? $passed++ : $failed++;
}

function module(int $id): object
{
    return \IPS\InstanceManager::getInstanceInterface($id);
}

/** Ruft eine private Methode des Moduls auf. */
function call(int $id, string $method, array $args = []): mixed
{
    $instance = module($id);
    $object = $instance;
    // Die Stubs kapseln das Modul – an das eigentliche Objekt herankommen
    foreach (['module'] as $inner) {
        if (property_exists($object, $inner)) {
            $prop = new ReflectionProperty($object, $inner);
            $object = $prop->getValue($object);
        }
    }
    $m = new ReflectionMethod($object, $method);
    return $m->invokeArgs($object, $args);
}

function value(int $id, string $ident): mixed
{
    $vid = @IPS_GetObjectIDByIdent($ident, $id);
    return $vid === false ? null : GetValue($vid);
}

function exists(int $id, string $ident): bool
{
    return @IPS_GetObjectIDByIdent($ident, $id) !== false;
}

/** Open-Meteo-Antwort relativ zu jetzt. */
function forecast(array $override = []): array
{
    $now = time();
    $hour = $now - $now % 3600;
    $hours = [];
    for ($i = 0; $i < 48; $i++) {
        $hours[] = $hour + $i * 3600;
    }
    $offset = 7200;
    $midnight = (int) (floor(($now + $offset) / 86400) * 86400) - $offset;
    $days = [];
    for ($i = 0; $i < 7; $i++) {
        $days[] = $midnight + $i * 86400;
    }
    $data = [
        'latitude'           => 52.52,
        'longitude'          => 13.42,
        'utc_offset_seconds' => $offset,
        'timezone'           => 'Europe/Berlin',
        'current'            => [
            'time'                 => $now - $now % 900,
            'interval'             => 900,
            'temperature_2m'       => 12.34,
            'relative_humidity_2m' => 71,
            'apparent_temperature' => 10.8,
            'dew_point_2m'         => 7.2,
            'is_day'               => 1,
            'precipitation'        => 0.0,
            'weather_code'         => 2,
            'cloud_cover'          => 45,
            'pressure_msl'         => 1013.6,
            'wind_speed_10m'       => 14.4,
            'wind_direction_10m'   => 315,
            'wind_gusts_10m'       => 31.7,
            'uv_index'             => 2.35,
            'visibility'           => 24140,
        ],
        'hourly' => [
            'time'                      => $hours,
            'temperature_2m'            => array_map(static fn (int $i): float => 12 + sin($i / 4) * 3, range(0, 47)),
            'precipitation_probability' => array_map(static fn (int $i): int => $i === 1 ? 65 : 5, range(0, 47)),
            'precipitation'             => array_map(static fn (int $i): float => $i === 1 ? 0.4 : 0.0, range(0, 47)),
            'weather_code'              => array_map(static fn (int $i): int => $i === 1 ? 61 : 2, range(0, 47)),
            'is_day'                    => array_map(static fn (int $i): int => ($i % 24) < 12 ? 1 : 0, range(0, 47)),
            'wind_speed_10m'            => array_fill(0, 48, 12.0),
        ],
        'daily' => [
            'time'                          => $days,
            'weather_code'                  => [2, 61, 3, 0, 71, 95, 45],
            'temperature_2m_max'            => [15.2, 13.1, 11.0, 16.4, 2.0, 21.5, 9.0],
            'temperature_2m_min'            => [7.1, 6.0, 4.2, 5.5, -3.2, 12.0, 3.3],
            'precipitation_sum'             => [0.4, 6.2, 0.0, 0.0, 3.1, 12.5, 0.1],
            'precipitation_probability_max' => [65, 90, 10, 0, 70, 85, 20],
            'sunrise'                       => array_map(static fn (int $d): int => $d + 7 * 3600 + 1800, $days),
            'sunset'                        => array_map(static fn (int $d): int => $d + 18 * 3600 + 3000, $days),
            'sunshine_duration'             => [18000, 3600, 9000, 36000, 0, 20000, 1000],
            'uv_index_max'                  => [3.1, 2.0, 2.2, 3.5, 1.0, 4.0, 1.5],
            'wind_speed_10m_max'            => [20, 30, 15, 10, 25, 40, 8],
            'wind_gusts_10m_max'            => [40, 55, 30, 20, 45, 80, 15],
        ],
    ];
    return array_replace_recursive($data, $override);
}

function alert(string $severity, string $headline, array $extra = []): array
{
    return $extra + [
        'id'             => random_int(1, 99999),
        'alert_id'       => 'x' . random_int(1, 99999),
        'status'         => 'actual',
        'effective'      => gmdate('c', time() - 3600),
        'onset'          => gmdate('c', time() + 1800),
        'expires'        => gmdate('c', time() + 8 * 3600),
        'category'       => 'met',
        'response_type'  => 'prepare',
        'urgency'        => 'immediate',
        'severity'       => $severity,
        'certainty'      => 'likely',
        'event_code'     => 51,
        'event_en'       => 'wind gusts',
        'event_de'       => 'WINDBÖEN',
        'headline_en'    => $headline,
        'headline_de'    => $headline . ' (de)',
        'description_en' => 'Description for ' . $headline,
        'description_de' => 'Beschreibung',
        'instruction_en' => 'Secure loose objects.',
        'instruction_de' => 'Gegenstände sichern.',
    ];
}

function alerts(array $list, bool $germany = true): array
{
    return [
        'alerts'   => $list,
        'location' => $germany ? ['warn_cell_id' => 711000101, 'name' => 'Berl. - Mitte', 'name_short' => 'B.-Mitte', 'district' => 'Berlin', 'state' => 'Berlin', 'state_short' => 'BE'] : null,
    ];
}

// ---------------------------------------------------------------------------
echo 'Anlegen und Standort' . PHP_EOL;
$id = IPS_CreateInstance(MODULE);
ok($id > 0, 'Instanz angelegt');
ok(IPS_GetInstance($id)['InstanceStatus'] === 104, 'Ohne Standort in Symcon: Status 104');
$tile = json_decode(call($id, 'ReadAttributeString', ['TileData']), true);
ok(($tile['error'] ?? '') === 'Please set a location', 'Kachel zeigt „Bitte Standort festlegen“');
ok(exists($id, 'Temperature') && exists($id, 'WeatherCode'), 'Grundvariablen vorhanden');
ok(!exists($id, 'DewPoint'), 'Details standardmäßig aus');
ok(exists($id, 'Sunrise') && exists($id, 'TomorrowMax') && exists($id, 'WarningLevel'), 'Sonne, Vorhersage und Warnungen standardmäßig an');

IPS_SetProperty($id, 'UseSymconLocation', false);
IPS_SetProperty($id, 'Location', '{"latitude":52.520008,"longitude":13.404954}');
IPS_ApplyChanges($id);
ok(IPS_GetInstance($id)['InstanceStatus'] !== 104, 'Mit eigenem Standort kein Status 104 mehr');
ok(call($id, 'Coordinates') === [52.52, 13.405], 'Koordinaten auf 4 Stellen gerundet');
IPS_SetProperty($id, 'Location', '{"latitude":0,"longitude":0}');
IPS_ApplyChanges($id);
ok(IPS_GetInstance($id)['InstanceStatus'] === 104, 'Koordinaten 0/0 gelten als nicht gesetzt');
IPS_SetProperty($id, 'Location', '{"latitude":52.52,"longitude":13.405}');
IPS_ApplyChanges($id);

$url = call($id, 'ForecastUrl', [52.52, 13.405]);
ok(str_starts_with($url, 'https://api.open-meteo.com/v1/forecast?'), 'Open-Meteo über HTTPS');
ok(str_contains($url, 'timeformat=unixtime') && str_contains($url, 'timezone=auto'), 'Unix-Zeit und Zeitzone des Standorts');
ok(!str_contains($url, 'models='), 'Automatisches Modell ohne models-Parameter');
IPS_SetProperty($id, 'Model', 'icon_seamless');
IPS_SetProperty($id, 'WindUnit', 'ms');
IPS_ApplyChanges($id);
$url = call($id, 'ForecastUrl', [52.52, 13.405]);
ok(str_contains($url, 'models=icon_seamless') && str_contains($url, 'wind_speed_unit=ms'), 'Modell und Windeinheit werden übergeben');
IPS_SetProperty($id, 'Model', '../../evil');
IPS_ApplyChanges($id);
ok(!str_contains(call($id, 'ForecastUrl', [52.52, 13.405]), 'evil'), 'Unbekanntes Modell wird ignoriert');
IPS_SetProperty($id, 'Model', 'best_match');
IPS_SetProperty($id, 'WindUnit', 'kmh');
IPS_ApplyChanges($id);

// ---------------------------------------------------------------------------
echo 'Verarbeitung' . PHP_EOL;
$result = call($id, 'Process', [forecast(), alerts([
    alert('minor', 'Frost'),
    alert('severe', 'Storm gusts </script><img src=x onerror=alert(1)>'),
    alert('moderate', 'Test alert', ['status' => 'test']),
    alert('moderate', 'All clear', ['response_type' => 'allclear']),
    alert('extreme', 'Expired', ['expires' => gmdate('c', time() - 60)]),
])]);
ok($result === true, 'Verarbeitung erfolgreich');
ok(IPS_GetInstance($id)['InstanceStatus'] === 102, 'Status 102');
ok(value($id, 'Temperature') === 12.3, 'Temperatur auf 0,1 gerundet');
ok(value($id, 'Humidity') === 71, 'Luftfeuchte als Ganzzahl');
ok(value($id, 'WeatherCode') === 2, 'Wettercode');
ok(value($id, 'WindDirection') === 315, 'Windrichtung');
ok(value($id, 'WindGusts') === 31.7, 'Böen');
ok(value($id, 'TodayMax') === 15.2 && value($id, 'TomorrowMin') === 6.0, 'Höchst- und Tiefstwerte heute/morgen');
ok(value($id, 'TomorrowCode') === 61, 'Wetter morgen');
ok(value($id, 'SunshineDuration') === 5.0, 'Sonnenscheindauer in Stunden');
ok(value($id, 'Sunrise') > 0 && value($id, 'Sunset') > value($id, 'Sunrise'), 'Sonnenauf- und -untergang');
ok(value($id, 'RainSoon') === true, 'Regen in den nächsten 2 Stunden erkannt');
ok(value($id, 'WarningCount') === 2, 'Test-, Entwarnungs- und abgelaufene Meldungen ignoriert');
ok(value($id, 'WarningLevel') === 3, 'Höchste Warnstufe 3 (Unwetter)');
ok(str_starts_with((string) value($id, 'WarningText'), 'Storm gusts'), 'Schwerste Warnung steht oben');

$data = json_decode(WETTER_GetForecast($id), true);
ok(count($data['daily']) === 7 && count($data['hourly']) >= 46, 'GetForecast liefert 7 Tage und die Stunden');
ok($data['current']['condition'] === 'Partly cloudy', 'Wetterlage als Text');
ok($data['current']['Visibility'] === 24.1, 'Sichtweite in km');
ok(count(json_decode(WETTER_GetWarnings($id), true)) === 2, 'GetWarnings liefert 2 Warnungen');

$tile = json_decode(call($id, 'ReadAttributeString', ['TileData']), true);
ok(isset($tile['now']) && $tile['now']['dir'] === 'NW', 'Kachel: aktuelles Wetter, Windrichtung NW');
ok(count($tile['hours']) === 24 && $tile['hours'][0][0] === 'Now', 'Kachel: 24 Stunden, beginnend mit „Jetzt“');
ok(count($tile['days']) === 7 && $tile['days'][0][0] === 'Today', 'Kachel: 7 Tage, beginnend mit „Heute“');
ok($tile['warn'][0][0] === 3, 'Kachel: Warnungen sortiert');
$html = WETTER_GetVisualizationTile($id);
ok(!str_contains($html, '</script><img'), 'Kachel: Daten können das Skript nicht beenden');
ok(str_contains($html, 'Storm gusts \u003C\/script\u003E'), 'Kachel: Startdaten maskiert eingebettet');

// Variablen nur bei Änderung schreiben
$vid = IPS_GetObjectIDByIdent('Temperature', $id);
$before = IPS_GetVariable($vid)['VariableUpdated'];
sleep(1);
call($id, 'Process', [forecast(), alerts([])]);
ok(IPS_GetVariable($vid)['VariableUpdated'] === $before, 'Unveränderte Werte werden nicht neu geschrieben');
ok(value($id, 'WarningCount') === 0 && value($id, 'WarningText') === 'No warnings', 'Warnungen aufgehoben');

// ---------------------------------------------------------------------------
echo 'Warnungen' . PHP_EOL;
IPS_SetProperty($id, 'WarningMinLevel', 3);
IPS_ApplyChanges($id);
call($id, 'Process', [forecast(), alerts([alert('minor', 'Frost'), alert('severe', 'Storm')])]);
ok(value($id, 'WarningCount') === 1, 'Mindeststufe 3 filtert Stufe 1');
call($id, 'Process', [forecast(), null]);
ok(value($id, 'WarningCount') === 1, 'Fehlgeschlagener Warnabruf behält die letzten Warnungen');
call($id, 'Process', [forecast(), alerts([], false)]);
ok(value($id, 'WarningLevel') === 0 && str_contains((string) value($id, 'WarningText'), 'only available in Germany'), 'Außerhalb Deutschlands: Hinweis statt Warnung');
$tile = json_decode(call($id, 'ReadAttributeString', ['TileData']), true);
ok($tile['warnOutside'] === true && $tile['i18n']['source'] === 'Open-Meteo', 'Kachel: ohne DWD-Quelle außerhalb Deutschlands');
IPS_SetProperty($id, 'WarningMinLevel', 1);
IPS_ApplyChanges($id);

// ---------------------------------------------------------------------------
echo 'Zuschaltbare Variablen' . PHP_EOL;
IPS_SetProperty($id, 'ShowDetails', true);
IPS_SetProperty($id, 'ShowForecast', false);
IPS_ApplyChanges($id);
ok(exists($id, 'DewPoint') && exists($id, 'UVIndex'), 'Details angelegt');
ok(!exists($id, 'TomorrowMax') && !exists($id, 'RainSoon'), 'Vorhersage entfernt');
call($id, 'Process', [forecast(), alerts([])]);
ok(value($id, 'Pressure') === 1013.6 && value($id, 'UVIndex') === 2.4, 'Details befüllt');

// ---------------------------------------------------------------------------
echo 'DWD-Station' . PHP_EOL;
function station(array $weather = [], int $age = 600): array
{
    return [
        'weather' => $weather + [
            'source_id'          => 6164,
            'timestamp'          => gmdate('c', time() - $age),
            'temperature'        => 13.6,
            'dew_point'          => 9.1,
            'relative_humidity'  => 74.0,
            'pressure_msl'       => 1011.2,
            'wind_speed_10'      => 7.2,
            'wind_direction_10'  => 250,
            'wind_gust_speed_10' => 18.0,
            'precipitation_10'   => 0.2,
            'cloud_cover'        => null,
            'visibility'         => 31000,
        ],
        'sources' => [
            ['id' => 6164, 'dwd_station_id' => '07367', 'wmo_station_id' => '10442', 'station_name' => 'Alfeld', 'observation_type' => 'current', 'distance' => 312.0],
        ],
    ];
}
ok(!exists($id, 'StationName'), 'Messstation standardmäßig aus');
IPS_SetProperty($id, 'UseStation', true);
IPS_ApplyChanges($id);
ok(exists($id, 'StationName'), 'Variable „Messstation“ angelegt');
call($id, 'Process', [forecast(), alerts([]), station()]);
ok(value($id, 'Temperature') === 13.6, 'Temperatur von der Station');
ok(value($id, 'ApparentTemperature') === 12.1, 'Gefühlte Temperatur mit Abstand des Modells (12,34 → 10,8)');
ok(value($id, 'Humidity') === 74 && value($id, 'DewPoint') === 9.1 && value($id, 'Pressure') === 1011.2, 'Feuchte, Taupunkt, Luftdruck von der Station');
ok(value($id, 'WindSpeed') === 7.2 && value($id, 'WindGusts') === 18.0 && value($id, 'WindDirection') === 250, 'Wind von der Station');
ok(value($id, 'Precipitation') === 0.2 && value($id, 'Visibility') === 31.0, 'Niederschlag und Sichtweite von der Station');
ok(value($id, 'CloudCover') === 45, 'Fehlender Stationswert (Bewölkung) bleibt beim Modell');
ok(value($id, 'StationName') === 'Measured: Alfeld · 0.3 km', 'Messstation mit Entfernung');
$tile = json_decode(call($id, 'ReadAttributeString', ['TileData']), true);
ok($tile['station'] === "Measured: Alfeld · 0.3\u{00A0}km" && $tile['now']['temp'] === 13.6, 'Kachel zeigt Station und Messwert');
ok(json_decode(WETTER_GetForecast($id), true)['station']['id'] === '07367', 'GetForecast nennt die Station');
IPS_SetProperty($id, 'WindUnit', 'ms');
IPS_ApplyChanges($id);
call($id, 'Process', [forecast(), alerts([]), station()]);
ok(value($id, 'WindSpeed') === 2.0 && value($id, 'WindGusts') === 5.0, 'Stationswind in m/s umgerechnet');
IPS_SetProperty($id, 'WindUnit', 'kmh');
IPS_ApplyChanges($id);
call($id, 'Process', [forecast(), alerts([]), station([], 3 * 3600)]);
ok(value($id, 'Temperature') === 12.3 && str_contains((string) value($id, 'StationName'), 'model values'), 'Veraltete Messwerte: Modellwerte');
call($id, 'Process', [forecast(), alerts([]), null]);
ok(value($id, 'Temperature') === 12.3, 'Ohne Stationsantwort: Modellwerte');
IPS_SetProperty($id, 'UseStation', false);
IPS_ApplyChanges($id);
ok(!exists($id, 'StationName'), 'Abgeschaltet: Variable entfernt');

// ---------------------------------------------------------------------------
echo 'Fehlerfälle' . PHP_EOL;
call($id, 'WriteAttributeInteger', ['FailCount', 0]);
for ($i = 0; $i < 3; $i++) {
    $r = call($id, 'Process', [['error' => true, 'reason' => 'x'], null]);
}
ok($r === false, 'Ungültige Antwort wird abgelehnt');
ok(IPS_GetInstance($id)['InstanceStatus'] === 201, 'Nach drei Fehlschlägen Status 201');
ok(value($id, 'Temperature') === 12.3, 'Letzte Werte bleiben erhalten');
$tile = json_decode(call($id, 'ReadAttributeString', ['TileData']), true);
ok(isset($tile['now']) && $tile['error'] === 'Weather data could not be loaded', 'Kachel zeigt Fehler und letzte Werte');
call($id, 'Process', [forecast(), alerts([])]);
ok(IPS_GetInstance($id)['InstanceStatus'] === 102, 'Nach erfolgreichem Abruf wieder Status 102');
try {
    IPS_RequestAction($id, 'Unbekannt', 1);
    ok(false, 'Unbekannter Ident wirft Ausnahme');
} catch (Throwable $e) {
    ok(true, 'Unbekannter Ident wirft Ausnahme');
}

// ---------------------------------------------------------------------------
echo 'Darstellungen und Typen' . PHP_EOL;
$presentationOk = true;
foreach (call($id, 'VariableTable') as $row) {
    $p = call($id, 'Presentation', [$row[3], $row[4]]);
    if (is_array($p) && isset($p['OPTIONS'])) {
        foreach (json_decode($p['OPTIONS'], true) as $option) {
            $need = $p['PRESENTATION'] === VARIABLE_PRESENTATION_ENUMERATION
                ? ['Value', 'Caption', 'IconActive', 'IconValue', 'Color']
                : ['Value', 'Caption', 'IconActive', 'IconValue', 'ColorActive', 'ColorValue'];
            foreach ($need as $key) {
                if (!array_key_exists($key, $option)) {
                    $presentationOk = false;
                    echo "    fehlt $key in {$row[0]}" . PHP_EOL;
                }
            }
        }
    }
    if ($p === '' && $row[3] !== 'timestamp') {
        $presentationOk = false;
    }
}
ok($presentationOk, 'Alle Darstellungen vollständig (OPTIONS mit Farben)');

$class = new ReflectionClass('Wetter');
$typed = true;
foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
    if ($method->getDeclaringClass()->getName() !== 'Wetter') {
        continue;
    }
    if (!$method->hasReturnType()) {
        $typed = false;
        echo '    ohne Rückgabetyp: ' . $method->getName() . PHP_EOL;
    }
    foreach ($method->getParameters() as $param) {
        if (!$param->hasType()) {
            $typed = false;
            echo '    ohne Typ: ' . $method->getName() . '($' . $param->getName() . ')' . PHP_EOL;
        }
    }
}
ok($typed, 'Alle öffentlichen Funktionen vollständig typisiert');
$public = array_map(static fn (ReflectionMethod $m): string => $m->getName(), array_filter(
    $class->getMethods(ReflectionMethod::IS_PUBLIC),
    static fn (ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === 'Wetter'
));
sort($public);
ok($public === ['ApplyChanges', 'Create', 'GetConfigurationForm', 'GetForecast', 'GetVisualizationTile', 'GetWarnings', 'MessageSink', 'RequestAction', 'Update'], 'Nur gewollte öffentliche Funktionen (' . implode(', ', $public) . ')');

$locale = json_decode((string) file_get_contents(__DIR__ . '/../Wetter/locale.json'), true)['translations']['de'];
$php = (string) file_get_contents(__DIR__ . '/../Wetter/module.php');
preg_match_all("/Translate\\('([^']+)'\\)/", $php, $m);
$missing = array_diff(array_unique($m[1]), array_keys($locale));
ok($missing === [], 'Alle Texte übersetzt' . ($missing !== [] ? ': ' . implode(', ', $missing) : ''));

echo PHP_EOL . ($failed === 0 ? "Alle $passed Tests bestanden." : "$failed von " . ($passed + $failed) . ' Tests fehlgeschlagen.') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
