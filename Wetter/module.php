<?php

declare(strict_types=1);

/**
 * Wetter – IP-Symcon-Modul für aktuelles Wetter, Vorhersage (Open-Meteo)
 * und amtliche Unwetterwarnungen des DWD (über Bright Sky)
 *
 * @author    Armin Frohwerk
 * @copyright 2026 Armin Frohwerk
 * @license   MIT – siehe Datei LICENSE im Hauptverzeichnis
 *
 * SPDX-License-Identifier: MIT
 */

class Wetter extends IPSModuleStrict
{
    // Symcon-Kerninstanz „Location“ (Standort unter Kern-Instanzen)
    private const LOCATION_GUID = '{45E97A63-F870-408A-B259-2933F7EABF74}';

    private const FORECAST_URL = 'https://api.open-meteo.com/v1/forecast';
    private const ALERTS_URL = 'https://api.brightsky.dev/alerts';
    private const STATION_URL = 'https://api.brightsky.dev/current_weather';

    // Stationswerte gelten nur, wenn sie höchstens so alt sind (Sekunden)
    private const STATION_MAX_AGE = 7200;

    private const MODELS = ['best_match', 'icon_seamless', 'ecmwf_ifs025', 'gfs_seamless', 'meteofrance_seamless', 'metno_seamless'];
    private const WIND_UNITS = ['kmh' => ' km/h', 'ms' => ' m/s', 'kn' => ' kn'];

    // WMO-Wettercodes (Open-Meteo): Bezeichnung, Symbol der Visualisierung, Gruppe für die Kachel
    private const WMO = [
        0  => ['Clear sky', 'sun', 'clear'],
        1  => ['Mainly clear', 'cloud-sun', 'partly'],
        2  => ['Partly cloudy', 'cloud-sun', 'partly'],
        3  => ['Overcast', 'cloud', 'cloudy'],
        45 => ['Fog', 'smog', 'fog'],
        48 => ['Depositing rime fog', 'smog', 'fog'],
        51 => ['Light drizzle', 'cloud-rain', 'drizzle'],
        53 => ['Moderate drizzle', 'cloud-rain', 'drizzle'],
        55 => ['Dense drizzle', 'cloud-rain', 'drizzle'],
        56 => ['Light freezing drizzle', 'cloud-rain', 'drizzle'],
        57 => ['Dense freezing drizzle', 'cloud-rain', 'drizzle'],
        61 => ['Slight rain', 'cloud-rain', 'rain'],
        63 => ['Moderate rain', 'cloud-rain', 'rain'],
        65 => ['Heavy rain', 'cloud-showers-heavy', 'rain'],
        66 => ['Light freezing rain', 'cloud-rain', 'rain'],
        67 => ['Heavy freezing rain', 'cloud-showers-heavy', 'rain'],
        71 => ['Slight snowfall', 'snowflake', 'snow'],
        73 => ['Moderate snowfall', 'snowflake', 'snow'],
        75 => ['Heavy snowfall', 'snowflake', 'snow'],
        77 => ['Snow grains', 'snowflake', 'snow'],
        80 => ['Slight rain showers', 'cloud-sun-rain', 'rain'],
        81 => ['Moderate rain showers', 'cloud-showers-heavy', 'rain'],
        82 => ['Violent rain showers', 'cloud-showers-heavy', 'rain'],
        85 => ['Slight snow showers', 'snowflake', 'snow'],
        86 => ['Heavy snow showers', 'snowflake', 'snow'],
        95 => ['Thunderstorm', 'cloud-bolt', 'thunder'],
        96 => ['Thunderstorm with slight hail', 'cloud-bolt', 'thunder'],
        99 => ['Thunderstorm with heavy hail', 'cloud-bolt', 'thunder'],
    ];

    // DWD-Warnstufen (Bright Sky „severity“)
    private const SEVERITY = ['minor' => 1, 'moderate' => 2, 'severe' => 3, 'extreme' => 4];

    public function Create(): void
    {
        // Never delete this line!
        parent::Create();

        // Standort und Abruf
        $this->RegisterPropertyBoolean('UseSymconLocation', true);
        $this->RegisterPropertyString('Location', '{"latitude":0,"longitude":0}');
        $this->RegisterPropertyString('LocationName', '');
        $this->RegisterPropertyString('Model', 'best_match');
        $this->RegisterPropertyInteger('Interval', 15);
        $this->RegisterPropertyString('WindUnit', 'kmh');

        // Messwerte einer DWD-Station
        $this->RegisterPropertyBoolean('UseStation', false);
        $this->RegisterPropertyInteger('StationMaxDistance', 10);

        // Variablen
        $this->RegisterPropertyBoolean('ShowDetails', false);
        $this->RegisterPropertyBoolean('ShowSun', true);
        $this->RegisterPropertyBoolean('ShowForecast', true);
        $this->RegisterPropertyBoolean('ShowWarnings', true);
        $this->RegisterPropertyInteger('WarningMinLevel', 1);

        // Kachel
        $this->RegisterPropertyBoolean('UseTile', true);
        $this->RegisterPropertyInteger('TileTheme', 0);
        $this->RegisterPropertyBoolean('TileShowDetails', true);
        $this->RegisterPropertyBoolean('TileShowWarnings', true);
        $this->RegisterPropertyBoolean('TileShowHourly', true);
        $this->RegisterPropertyInteger('TileHours', 24);
        $this->RegisterPropertyBoolean('TileShowDaily', true);
        $this->RegisterPropertyInteger('TileDays', 7);

        $this->RegisterAttributeString('TileData', '{}');
        $this->RegisterAttributeInteger('FailCount', 0);
        $this->RegisterAttributeInteger('LastManualUpdate', 0);

        $this->RegisterTimer('Update', 0, 'WETTER_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        // Never delete this line!
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        // Kachel-Visualisierung (HTML-SDK)
        $this->SetVisualizationType($this->ReadPropertyBoolean('UseTile') ? 1 : 0);

        $groups = [
            'base'     => true,
            'details'  => $this->ReadPropertyBoolean('ShowDetails'),
            'sun'      => $this->ReadPropertyBoolean('ShowSun'),
            'forecast' => $this->ReadPropertyBoolean('ShowForecast'),
            'warnings' => $this->ReadPropertyBoolean('ShowWarnings'),
            'station'  => $this->ReadPropertyBoolean('UseStation'),
        ];
        foreach ($this->VariableTable() as [$ident, $name, $type, $kind, $icon, $position, $group]) {
            $keep = $groups[$group];
            $this->MaintainVariable($ident, $this->Translate($name), $type, $keep ? $this->Presentation($kind, $icon) : '', $position, $keep);
        }

        $this->WriteAttributeInteger('FailCount', 0);

        if ($this->Coordinates() === null) {
            $this->SetTimerInterval('Update', 0);
            $this->SetStatus(104);
            $this->PushTile(['theme' => $this->ReadPropertyInteger('TileTheme'), 'error' => $this->Translate('Please set a location')]);
            return;
        }

        $this->SetTimerInterval('Update', $this->IntervalMinutes() * 60 * 1000);
        $this->SetStatus(102);

        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->Update();
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED && $this->Coordinates() !== null) {
            $this->Update();
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'TileRefresh':
                // Schaltfläche der Kachel: höchstens alle 30 Sekunden neu abfragen
                if (time() - $this->ReadAttributeInteger('LastManualUpdate') < 30) {
                    return;
                }
                $this->WriteAttributeInteger('LastManualUpdate', time());
                $this->Update();
                break;

            case 'ToggleLocation':
                // Formular: eigene Koordinaten nur zeigen, wenn nicht der Symcon-Standort gilt
                $this->UpdateFormField('Location', 'visible', !(bool) $Value);
                break;

            default:
                throw new Exception('Ungültiger Ident: ' . $Ident);
        }
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        $symcon = $this->GetSymconLocation();
        $caption = $symcon === null
            ? $this->Translate('Use the location of Symcon (not set – Core Instances → Location)')
            : sprintf($this->Translate('Use the location of Symcon (%s, %s)'), number_format($symcon[0], 4, ',', ''), number_format($symcon[1], 4, ',', ''));
        $this->InjectProperty($form['elements'], 'UseSymconLocation', 'caption', $caption);
        $this->InjectProperty($form['elements'], 'Location', 'visible', !$this->ReadPropertyBoolean('UseSymconLocation'));

        // zuletzt genutzte Messstation anzeigen
        $station = json_decode($this->GetBuffer('Station'), true);
        if ($this->ReadPropertyBoolean('UseStation') && is_array($station)) {
            $this->InjectProperty($form['elements'], 'StationInfo', 'caption', $this->StationLabel($station) . ($station['id'] !== '' ? ' (DWD ' . $station['id'] . ')' : ''));
            $this->InjectProperty($form['elements'], 'StationInfo', 'visible', true);
        }
        return (string) json_encode($form);
    }

    /**
     * Fragt Wetter, Vorhersage und Warnungen sofort ab.
     */
    public function Update(): bool
    {
        $location = $this->Coordinates();
        if ($location === null) {
            $this->SetStatus(104);
            return false;
        }
        [$lat, $lon] = $location;

        $error = '';
        $forecast = $this->HttpGetJson($this->ForecastUrl($lat, $lon), $error);
        if ($forecast === null) {
            $this->HandleFailure('Open-Meteo: ' . $error);
            return false;
        }

        $alerts = null;
        if ($this->WarningsWanted()) {
            $alerts = $this->HttpGetJson(self::ALERTS_URL . '?' . http_build_query(['lat' => $lat, 'lon' => $lon]), $error);
            if ($alerts === null) {
                $this->SendDebug('Warnungen', 'Bright Sky: ' . $error . ' – letzte Warnungen bleiben erhalten', 0);
            }
        }

        $station = null;
        if ($this->ReadPropertyBoolean('UseStation')) {
            $station = $this->HttpGetJson(self::STATION_URL . '?' . http_build_query([
                'lat'      => $lat,
                'lon'      => $lon,
                'max_dist' => $this->StationMaxDistance() * 1000,
            ]), $error);
            if ($station === null) {
                $this->SendDebug('Station', 'Bright Sky: ' . $error . ' – Modellwerte werden verwendet', 0);
            }
        }

        return $this->Process($forecast, $alerts, $station);
    }

    /**
     * Liefert die zuletzt abgerufenen Daten (aktuell, stündlich, täglich, Warnungen) als JSON.
     */
    public function GetForecast(): string
    {
        $data = $this->GetBuffer('Forecast');
        return $data !== '' ? $data : '{}';
    }

    /**
     * Liefert die aktiven Unwetterwarnungen als JSON-Liste.
     */
    public function GetWarnings(): string
    {
        $data = json_decode($this->GetForecast(), true);
        return (string) json_encode(is_array($data) ? ($data['warnings'] ?? []) : []);
    }

    // ------------------------------------------------------------------
    // Verarbeitung
    // ------------------------------------------------------------------

    /**
     * Verarbeitet die Antworten von Open-Meteo und Bright Sky.
     *
     * @param array      $forecast Antwort von Open-Meteo
     * @param array|null $alerts   Antwort von Bright Sky (null = nicht abgefragt oder fehlgeschlagen)
     * @param array|null $station  Messwerte der DWD-Station von Bright Sky (null = nicht genutzt oder fehlgeschlagen)
     */
    private function Process(array $forecast, ?array $alerts, ?array $station = null): bool
    {
        $current = $forecast['current'] ?? null;
        $daily = $forecast['daily'] ?? [];
        $hourly = $forecast['hourly'] ?? [];
        if (!is_array($current) || !isset($current['temperature_2m']) || !is_array($daily) || !is_array($hourly)) {
            $this->HandleFailure('Open-Meteo: ' . $this->Translate('unexpected response'));
            return false;
        }
        $offset = (int) ($forecast['utc_offset_seconds'] ?? 0);
        $now = time();

        // Aktuelles Wetter
        $code = (int) ($current['weather_code'] ?? 0);
        $values = [
            'Temperature'         => round((float) $current['temperature_2m'], 1),
            'ApparentTemperature' => round((float) ($current['apparent_temperature'] ?? 0), 1),
            'Humidity'            => (int) round((float) ($current['relative_humidity_2m'] ?? 0)),
            'WeatherCode'         => $code,
            'Precipitation'       => round((float) ($current['precipitation'] ?? 0), 1),
            'WindSpeed'           => round((float) ($current['wind_speed_10m'] ?? 0), 1),
            'WindGusts'           => round((float) ($current['wind_gusts_10m'] ?? 0), 1),
            'WindDirection'       => (int) round((float) ($current['wind_direction_10m'] ?? 0)) % 360,
            'DataTime'            => (int) ($current['time'] ?? $now),
            'DewPoint'            => round((float) ($current['dew_point_2m'] ?? 0), 1),
            'CloudCover'          => (int) round((float) ($current['cloud_cover'] ?? 0)),
            'Pressure'            => round((float) ($current['pressure_msl'] ?? 0), 1),
            'Visibility'          => round((float) ($current['visibility'] ?? 0) / 1000, 1),
            'UVIndex'             => round((float) ($current['uv_index'] ?? 0), 1),
        ];

        // Gemessene Werte der DWD-Station haben Vorrang vor dem Wettermodell
        $stationInfo = $this->ApplyStation($station, $values);

        // Tageswerte (Index 0 = heute am Standort)
        $day = static function (string $key, int $index) use ($daily): mixed {
            return $daily[$key][$index] ?? null;
        };
        $values += [
            'Sunrise'                   => (int) ($day('sunrise', 0) ?? 0),
            'Sunset'                    => (int) ($day('sunset', 0) ?? 0),
            'SunshineDuration'          => round((float) ($day('sunshine_duration', 0) ?? 0) / 3600, 1),
            'TodayMax'                  => round((float) ($day('temperature_2m_max', 0) ?? 0), 1),
            'TodayMin'                  => round((float) ($day('temperature_2m_min', 0) ?? 0), 1),
            'TodayPrecipitation'        => round((float) ($day('precipitation_sum', 0) ?? 0), 1),
            'TodayPrecipProbability'    => (int) ($day('precipitation_probability_max', 0) ?? 0),
            'TomorrowCode'              => (int) ($day('weather_code', 1) ?? 0),
            'TomorrowMax'               => round((float) ($day('temperature_2m_max', 1) ?? 0), 1),
            'TomorrowMin'               => round((float) ($day('temperature_2m_min', 1) ?? 0), 1),
            'TomorrowPrecipProbability' => (int) ($day('precipitation_probability_max', 1) ?? 0),
        ];

        // Stundenwerte ab der laufenden Stunde
        $hours = [];
        foreach ((array) ($hourly['time'] ?? []) as $i => $time) {
            if ((int) $time + 3600 <= $now) {
                continue;
            }
            $hours[] = [
                'time'   => (int) $time,
                'temp'   => round((float) ($hourly['temperature_2m'][$i] ?? 0), 1),
                'code'   => (int) ($hourly['weather_code'][$i] ?? 0),
                'day'    => (bool) ($hourly['is_day'][$i] ?? true),
                'prob'   => (int) ($hourly['precipitation_probability'][$i] ?? 0),
                'precip' => round((float) ($hourly['precipitation'][$i] ?? 0), 1),
                'wind'   => round((float) ($hourly['wind_speed_10m'][$i] ?? 0), 1),
            ];
        }

        // Regen in den nächsten zwei Stunden (z. B. für Markise, Bewässerung)
        $rainSoon = false;
        foreach ($hours as $hour) {
            if ($hour['time'] > $now + 7200) {
                break;
            }
            if ($hour['precip'] >= 0.1) {
                $rainSoon = true;
                break;
            }
        }
        $values['RainSoon'] = $rainSoon;

        // Warnungen
        $warnings = $this->ProcessAlerts($alerts, $offset);
        $values['WarningLevel'] = $warnings['level'];
        $values['WarningCount'] = count($warnings['list']);
        $values['WarningText'] = $warnings['text'];
        $values['StationName'] = $stationInfo !== null
            ? $this->StationLabel($stationInfo)
            : $this->Translate('no current measured values – model values are used');

        foreach ($values as $ident => $value) {
            $this->SetValueIfChanged($ident, $value);
        }

        // Tage für Skripte und Kachel
        $days = [];
        foreach ((array) ($daily['time'] ?? []) as $i => $time) {
            $days[] = [
                'date'   => gmdate('Y-m-d', (int) $time + $offset),
                'code'   => (int) ($daily['weather_code'][$i] ?? 0),
                'min'    => round((float) ($daily['temperature_2m_min'][$i] ?? 0), 1),
                'max'    => round((float) ($daily['temperature_2m_max'][$i] ?? 0), 1),
                'precip' => round((float) ($daily['precipitation_sum'][$i] ?? 0), 1),
                'prob'   => (int) ($daily['precipitation_probability_max'][$i] ?? 0),
                'sun'    => round((float) ($daily['sunshine_duration'][$i] ?? 0) / 3600, 1),
                'uv'     => round((float) ($daily['uv_index_max'][$i] ?? 0), 1),
                'wind'   => round((float) ($daily['wind_speed_10m_max'][$i] ?? 0), 1),
                'gusts'  => round((float) ($daily['wind_gusts_10m_max'][$i] ?? 0), 1),
                'sunrise' => (int) ($daily['sunrise'][$i] ?? 0),
                'sunset' => (int) ($daily['sunset'][$i] ?? 0),
            ];
        }

        $isDay = (bool) ($current['is_day'] ?? true);
        $this->SetBuffer('Forecast', (string) json_encode([
            'updated'   => $now,
            'timezone'  => (string) ($forecast['timezone'] ?? ''),
            'windUnit'  => trim(self::WIND_UNITS[$this->WindUnit()]),
            'current'   => array_slice($values, 0, 14, true) + ['isDay' => $isDay, 'condition' => $this->Condition($code)],
            'hourly'    => $hours,
            'daily'     => $days,
            'warnings'  => $warnings['list'],
            'station'   => $stationInfo,
        ]));

        $this->PushTile($this->BuildTile($values, $isDay, $hours, $days, $warnings, $offset, $stationInfo));

        $this->WriteAttributeInteger('FailCount', 0);
        if ($this->GetStatus() !== 102) {
            $this->SetStatus(102);
        }
        return true;
    }

    /**
     * Wertet die Bright-Sky-Warnungen aus. Ohne neue Daten bleiben die letzten Warnungen erhalten.
     *
     * @return array{level:int,text:string,list:array,outside:bool}
     */
    private function ProcessAlerts(?array $alerts, int $offset): array
    {
        if (!$this->WarningsWanted()) {
            return ['level' => 0, 'text' => '', 'list' => [], 'outside' => false];
        }
        if ($alerts === null) {
            $previous = json_decode($this->GetBuffer('Warnings'), true);
            if (is_array($previous)) {
                // abgelaufene Warnungen trotzdem entfernen
                $previous['list'] = array_values(array_filter($previous['list'], static function (array $w): bool {
                    return $w['to'] === 0 || $w['to'] > time();
                }));
                return $this->SummarizeWarnings($previous['list'], (bool) $previous['outside']);
            }
            return ['level' => 0, 'text' => '', 'list' => [], 'outside' => false];
        }

        $outside = !is_array($alerts['location'] ?? null);
        $german = $this->Language() === 'de';
        $minLevel = $this->ReadPropertyInteger('WarningMinLevel');
        $list = [];
        foreach ((array) ($alerts['alerts'] ?? []) as $alert) {
            if (!is_array($alert) || ($alert['status'] ?? 'actual') !== 'actual' || ($alert['response_type'] ?? '') === 'allclear') {
                continue;
            }
            $level = self::SEVERITY[(string) ($alert['severity'] ?? '')] ?? 0;
            $to = $this->ParseTime($alert['expires'] ?? null);
            if ($level < $minLevel || ($to !== 0 && $to <= time())) {
                continue;
            }
            $text = static function (string $key) use ($alert, $german): string {
                $value = (string) ($alert[$key . ($german ? '_de' : '_en')] ?? '');
                if ($value === '') {
                    $value = (string) ($alert[$key . ($german ? '_en' : '_de')] ?? '');
                }
                return mb_substr(trim($value), 0, 1500);
            };
            $from = $this->ParseTime($alert['onset'] ?? null) ?: $this->ParseTime($alert['effective'] ?? null);
            $list[] = [
                'level'       => $level,
                'event'       => $text('event'),
                'headline'    => $text('headline'),
                'description' => $text('description'),
                'instruction' => $text('instruction'),
                'from'        => $from,
                'to'          => $to,
                'period'      => $this->Period($from, $to, $offset),
            ];
        }
        usort($list, static function (array $a, array $b): int {
            return [$b['level'], $a['from']] <=> [$a['level'], $b['from']];
        });

        $this->SetBuffer('Warnings', (string) json_encode(['list' => $list, 'outside' => $outside]));
        return $this->SummarizeWarnings($list, $outside);
    }

    private function SummarizeWarnings(array $list, bool $outside): array
    {
        $level = 0;
        $lines = [];
        foreach ($list as $warning) {
            $level = max($level, (int) $warning['level']);
            $lines[] = $warning['headline'] . ($warning['period'] !== '' ? ' (' . $warning['period'] . ')' : '');
        }
        if ($outside) {
            $text = $this->Translate('No DWD warning region (only available in Germany)');
        } else {
            $text = count($lines) > 0 ? implode("\n", $lines) : $this->Translate('No warnings');
        }
        return ['level' => $level, 'text' => $text, 'list' => $list, 'outside' => $outside];
    }

    /**
     * Baut die Daten für die Kachel.
     */
    private function BuildTile(array $values, bool $isDay, array $hours, array $days, array $warnings, int $offset, ?array $station): array
    {
        $german = $this->Language() === 'de';
        $weekdays = $german ? ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'] : ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

        $hourRows = [];
        foreach (array_slice($hours, 0, $this->ReadPropertyInteger('TileHours')) as $i => $hour) {
            $hourRows[] = [
                $i === 0 ? $this->Translate('Now') : gmdate('H', $hour['time'] + $offset),
                $hour['code'],
                $hour['day'] ? 1 : 0,
                // „Jetzt“ zeigt denselben Wert wie oben (bei DWD-Station den gemessenen)
                $i === 0 ? $values['Temperature'] : $hour['temp'],
                $hour['prob'],
            ];
        }

        $dayRows = [];
        foreach (array_slice($days, 0, $this->ReadPropertyInteger('TileDays')) as $i => $d) {
            $label = $i === 0 ? $this->Translate('Today') : $weekdays[(int) gmdate('w', strtotime($d['date'] . ' 12:00 UTC'))];
            $dayRows[] = [$label, $d['code'], $d['min'], $d['max'], $d['prob'], $d['precip']];
        }

        $name = trim($this->ReadPropertyString('LocationName'));
        if ($name === '') {
            $name = IPS_GetName($this->InstanceID);
        }
        $unit = trim(self::WIND_UNITS[$this->WindUnit()]);

        return [
            'theme' => $this->ReadPropertyInteger('TileTheme'),
            'lang'  => $german ? 'de' : 'en',
            'name'  => $name,
            // geschütztes Leerzeichen, damit „0,3 km“ nicht auseinanderbricht
            'station' => $station !== null ? str_replace(' km', "\u{00A0}km", $this->StationLabel($station)) : '',
            'now'   => [
                'temp'   => $values['Temperature'],
                'feels'  => $values['ApparentTemperature'],
                'code'   => $values['WeatherCode'],
                'day'    => $isDay ? 1 : 0,
                'cond'   => $this->Condition($values['WeatherCode']),
                'hum'    => $values['Humidity'],
                'wind'   => $values['WindSpeed'],
                'gust'   => $values['WindGusts'],
                'dir'    => $this->DirectionName($values['WindDirection']),
                'deg'    => $values['WindDirection'],
                'precip' => $values['Precipitation'],
                'uv'     => $values['UVIndex'],
                'press'  => $values['Pressure'],
            ],
            'today' => [
                'max'  => $values['TodayMax'],
                'min'  => $values['TodayMin'],
                'prob' => $values['TodayPrecipProbability'],
                'rise' => $values['Sunrise'] > 0 ? gmdate('H:i', $values['Sunrise'] + $offset) : '',
                'set'  => $values['Sunset'] > 0 ? gmdate('H:i', $values['Sunset'] + $offset) : '',
            ],
            'unit'  => $unit,
            'hours' => $hourRows,
            'days'  => $dayRows,
            'warn'  => array_map(static function (array $w): array {
                return [$w['level'], $w['headline'], $w['period'], $w['description'], $w['instruction']];
            }, $warnings['list']),
            'warnOutside' => $warnings['outside'],
            'show'  => [
                'details'  => $this->ReadPropertyBoolean('TileShowDetails'),
                'warnings' => $this->ReadPropertyBoolean('TileShowWarnings'),
                'hourly'   => $this->ReadPropertyBoolean('TileShowHourly'),
                'daily'    => $this->ReadPropertyBoolean('TileShowDaily'),
            ],
            'updated' => date('H:i'),
            'i18n'  => [
                'feels'     => $this->Translate('Feels like'),
                'humidity'  => $this->Translate('Humidity'),
                'wind'      => $this->Translate('Wind'),
                'gusts'     => $this->Translate('Gusts'),
                'precip'    => $this->Translate('Precipitation'),
                'uv'        => $this->Translate('UV index'),
                'pressure'  => $this->Translate('Pressure'),
                'sun'       => $this->Translate('Sun'),
                'hourly'    => $this->Translate('Next hours'),
                'daily'     => $this->Translate('Next days'),
                'noWarn'    => $this->Translate('No weather warnings'),
                'more'      => $this->Translate('Details'),
                'less'      => $this->Translate('Less'),
                'refresh'   => $this->Translate('Update now'),
                'source'    => $station !== null || (!$warnings['outside'] && $this->WarningsWanted()) ? 'Open-Meteo · DWD' : 'Open-Meteo',
                'levels'    => ['', $this->Translate('Weather warning'), $this->Translate('Warning of markedly severe weather'), $this->Translate('Severe weather warning'), $this->Translate('Warning of extreme weather')],
            ],
        ];
    }

    private function HandleFailure(string $message): void
    {
        $count = $this->ReadAttributeInteger('FailCount') + 1;
        $this->WriteAttributeInteger('FailCount', $count);
        $this->SendDebug('Fehler', $message . ' (' . $count . '. Fehlversuch)', 0);
        // Einzelne Aussetzer still überbrücken, erst ab dem dritten Fehlschlag melden
        if ($count >= 3) {
            if ($this->GetStatus() !== 201) {
                $this->SetStatus(201);
                $this->LogMessage($this->Translate('Weather data could not be loaded') . ': ' . $message, KL_WARNING);
            }
            $this->PushTileError($this->Translate('Weather data could not be loaded'));
        }
    }

    // ------------------------------------------------------------------
    // Abruf
    // ------------------------------------------------------------------

    private function ForecastUrl(float $lat, float $lon): string
    {
        $query = [
            'latitude'        => $lat,
            'longitude'       => $lon,
            'current'         => 'temperature_2m,relative_humidity_2m,apparent_temperature,dew_point_2m,is_day,precipitation,weather_code,cloud_cover,pressure_msl,wind_speed_10m,wind_direction_10m,wind_gusts_10m,uv_index,visibility',
            'hourly'          => 'temperature_2m,precipitation_probability,precipitation,weather_code,is_day,wind_speed_10m',
            'daily'           => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum,precipitation_probability_max,sunrise,sunset,sunshine_duration,uv_index_max,wind_speed_10m_max,wind_gusts_10m_max',
            'timezone'        => 'auto',
            'timeformat'      => 'unixtime',
            'forecast_days'   => 7,
            'forecast_hours'  => 48,
            'wind_speed_unit' => $this->WindUnit(),
        ];
        $model = $this->ReadPropertyString('Model');
        if ($model !== 'best_match' && in_array($model, self::MODELS, true)) {
            $query['models'] = $model;
        }
        return self::FORECAST_URL . '?' . http_build_query($query);
    }

    /**
     * Ruft eine URL per HTTPS ab und liefert das dekodierte JSON (null bei Fehler).
     */
    private function HttpGetJson(string $url, string &$error): ?array
    {
        $error = '';
        $this->SendDebug('Abruf', $url, 0);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 3,
            // nur HTTPS, auch bei Weiterleitungen; Zertifikat wird immer geprüft
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_MAXFILESIZE     => 5 * 1024 * 1024,
            CURLOPT_ENCODING        => '',
            CURLOPT_CONNECTTIMEOUT  => 10,
            CURLOPT_TIMEOUT         => 20,
            CURLOPT_HTTPHEADER      => ['Accept: application/json'],
            CURLOPT_USERAGENT       => 'IP-Symcon Wetter-Modul (github.com/cfaf2002/Wetter_Symcon)',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($body === false) {
            $error = 'cURL ' . curl_errno($ch) . ': ' . curl_error($ch);
            $this->SendDebug('Fehler', $error, 0);
            return null;
        }
        $this->SendDebug('Antwort', 'HTTP ' . $code . ' (' . strlen((string) $body) . ' Bytes): ' . substr((string) $body, 0, 800), 0);

        $json = json_decode((string) $body, true);
        if ($code !== 200) {
            $error = 'HTTP ' . $code . (is_array($json) && isset($json['reason']) ? ' – ' . (string) $json['reason'] : '');
            return null;
        }
        if (!is_array($json)) {
            $error = $this->Translate('unexpected response');
            return null;
        }
        return $json;
    }

    // ------------------------------------------------------------------
    // Standort
    // ------------------------------------------------------------------

    /**
     * @return array{0:float,1:float}|null
     */
    private function Coordinates(): ?array
    {
        if ($this->ReadPropertyBoolean('UseSymconLocation')) {
            return $this->GetSymconLocation();
        }
        $location = json_decode($this->ReadPropertyString('Location'), true);
        if (!is_array($location) || !isset($location['latitude'], $location['longitude'])) {
            return null;
        }
        return $this->ValidLocation((float) $location['latitude'], (float) $location['longitude']);
    }

    /**
     * @return array{0:float,1:float}|null
     */
    private function GetSymconLocation(): ?array
    {
        try {
            $ids = IPS_GetInstanceListByModuleID(self::LOCATION_GUID);
            if (count($ids) === 0) {
                return null;
            }
            $config = json_decode(IPS_GetConfiguration($ids[0]), true);
            if (!is_array($config)) {
                return null;
            }
            if (isset($config['Location'])) {
                $location = is_array($config['Location']) ? $config['Location'] : json_decode((string) $config['Location'], true);
                if (isset($location['latitude'], $location['longitude'])) {
                    return $this->ValidLocation((float) $location['latitude'], (float) $location['longitude']);
                }
            }
            if (isset($config['Latitude'], $config['Longitude'])) {
                return $this->ValidLocation((float) $config['Latitude'], (float) $config['Longitude']);
            }
        } catch (Throwable $e) {
            $this->SendDebug('Standort', $e->getMessage(), 0);
        }
        return null;
    }

    /**
     * Prüft die Koordinaten und rundet sie auf vier Stellen (etwa 10 m – genug für Wetterdaten).
     *
     * @return array{0:float,1:float}|null
     */
    private function ValidLocation(float $lat, float $lon): ?array
    {
        if (($lat == 0.0 && $lon == 0.0) || abs($lat) > 90 || abs($lon) > 180) {
            return null;
        }
        return [round($lat, 4), round($lon, 4)];
    }

    // ------------------------------------------------------------------
    // Variablen und Darstellungen
    // ------------------------------------------------------------------

    /**
     * @return array<int, array{0:string,1:string,2:int,3:string,4:string,5:int,6:string}>
     */
    private function VariableTable(): array
    {
        return [
            ['Temperature', 'Temperature', VARIABLETYPE_FLOAT, 'temp', 'temperature-half', 10, 'base'],
            ['ApparentTemperature', 'Feels like', VARIABLETYPE_FLOAT, 'temp', 'temperature-half', 11, 'base'],
            ['Humidity', 'Humidity', VARIABLETYPE_INTEGER, 'percent', 'droplet', 12, 'base'],
            ['WeatherCode', 'Weather', VARIABLETYPE_INTEGER, 'weather', 'cloud-sun', 13, 'base'],
            ['Precipitation', 'Precipitation (current)', VARIABLETYPE_FLOAT, 'mm', 'cloud-rain', 14, 'base'],
            ['WindSpeed', 'Wind speed', VARIABLETYPE_FLOAT, 'wind', 'wind', 15, 'base'],
            ['WindGusts', 'Wind gusts', VARIABLETYPE_FLOAT, 'wind', 'wind', 16, 'base'],
            ['WindDirection', 'Wind direction', VARIABLETYPE_INTEGER, 'degree', 'compass', 17, 'base'],
            ['DataTime', 'Time of data', VARIABLETYPE_INTEGER, 'timestamp', '', 18, 'base'],

            ['DewPoint', 'Dew point', VARIABLETYPE_FLOAT, 'temp', 'droplet', 30, 'details'],
            ['CloudCover', 'Cloud cover', VARIABLETYPE_INTEGER, 'percent', 'cloud', 31, 'details'],
            ['Pressure', 'Air pressure', VARIABLETYPE_FLOAT, 'hpa', 'gauge', 32, 'details'],
            ['Visibility', 'Visibility', VARIABLETYPE_FLOAT, 'km', 'eye', 33, 'details'],
            ['UVIndex', 'UV index', VARIABLETYPE_FLOAT, 'plain', 'sun', 34, 'details'],

            ['Sunrise', 'Sunrise', VARIABLETYPE_INTEGER, 'timestamp', '', 40, 'sun'],
            ['Sunset', 'Sunset', VARIABLETYPE_INTEGER, 'timestamp', '', 41, 'sun'],
            ['SunshineDuration', 'Sunshine duration today', VARIABLETYPE_FLOAT, 'hours', 'sun', 42, 'sun'],

            ['TodayMax', 'Today maximum', VARIABLETYPE_FLOAT, 'temp', 'temperature-arrow-up', 50, 'forecast'],
            ['TodayMin', 'Today minimum', VARIABLETYPE_FLOAT, 'temp', 'temperature-arrow-down', 51, 'forecast'],
            ['TodayPrecipitation', 'Precipitation today', VARIABLETYPE_FLOAT, 'mm', 'cloud-rain', 52, 'forecast'],
            ['TodayPrecipProbability', 'Precipitation probability today', VARIABLETYPE_INTEGER, 'percent', 'umbrella', 53, 'forecast'],
            ['TomorrowCode', 'Weather tomorrow', VARIABLETYPE_INTEGER, 'weather', 'cloud-sun', 54, 'forecast'],
            ['TomorrowMax', 'Tomorrow maximum', VARIABLETYPE_FLOAT, 'temp', 'temperature-arrow-up', 55, 'forecast'],
            ['TomorrowMin', 'Tomorrow minimum', VARIABLETYPE_FLOAT, 'temp', 'temperature-arrow-down', 56, 'forecast'],
            ['TomorrowPrecipProbability', 'Precipitation probability tomorrow', VARIABLETYPE_INTEGER, 'percent', 'umbrella', 57, 'forecast'],
            ['RainSoon', 'Rain in the next 2 hours', VARIABLETYPE_BOOLEAN, 'rain', 'umbrella', 58, 'forecast'],

            ['WarningLevel', 'Warning level', VARIABLETYPE_INTEGER, 'warnlevel', 'triangle-exclamation', 70, 'warnings'],
            ['WarningCount', 'Number of warnings', VARIABLETYPE_INTEGER, 'count', 'triangle-exclamation', 71, 'warnings'],
            ['WarningText', 'Warnings', VARIABLETYPE_STRING, 'multiline', 'triangle-exclamation', 72, 'warnings'],

            ['StationName', 'Measuring station', VARIABLETYPE_STRING, 'text', 'tower-broadcast', 80, 'station'],
        ];
    }

    private function Presentation(string $kind, string $icon): array|string
    {
        $value = VARIABLE_PRESENTATION_VALUE_PRESENTATION;
        switch ($kind) {
            case 'timestamp':
                return '~UnixTimestamp';
            case 'temp':
                return ['PRESENTATION' => $value, 'ICON' => $icon, 'SUFFIX' => ' °C', 'DIGITS' => 1];
            case 'percent':
                return ['PRESENTATION' => $value, 'ICON' => $icon, 'SUFFIX' => ' %', 'DIGITS' => 0];
            case 'mm':
                return ['PRESENTATION' => $value, 'ICON' => $icon, 'SUFFIX' => ' mm', 'DIGITS' => 1];
            case 'wind':
                $unit = $this->WindUnit();
                return ['PRESENTATION' => $value, 'ICON' => $icon, 'SUFFIX' => self::WIND_UNITS[$unit], 'DIGITS' => $unit === 'kmh' ? 0 : 1];
            case 'degree':
                return ['PRESENTATION' => $value, 'ICON' => $icon, 'SUFFIX' => ' °', 'DIGITS' => 0];
            case 'hpa':
                return ['PRESENTATION' => $value, 'ICON' => $icon, 'SUFFIX' => ' hPa', 'DIGITS' => 0];
            case 'km':
                return ['PRESENTATION' => $value, 'ICON' => $icon, 'SUFFIX' => ' km', 'DIGITS' => 1];
            case 'hours':
                return ['PRESENTATION' => $value, 'ICON' => $icon, 'SUFFIX' => ' h', 'DIGITS' => 1];
            case 'plain':
                return ['PRESENTATION' => $value, 'ICON' => $icon, 'DIGITS' => 1];
            case 'text':
                return ['PRESENTATION' => $value, 'ICON' => $icon];
            case 'count':
                return ['PRESENTATION' => $value, 'ICON' => $icon, 'DIGITS' => 0];
            case 'multiline':
                return ['PRESENTATION' => $value, 'ICON' => $icon, 'MULTILINE' => true];
            case 'weather':
                $options = [];
                foreach (self::WMO as $code => [$caption, $symbol]) {
                    $options[] = ['Value' => $code, 'Caption' => $this->Translate($caption), 'IconActive' => true, 'IconValue' => $symbol, 'Color' => -1];
                }
                return ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'ICON' => $icon, 'OPTIONS' => (string) json_encode($options)];
            case 'rain':
                return [
                    'PRESENTATION' => $value,
                    'ICON'         => $icon,
                    'OPTIONS'      => (string) json_encode([
                        // Wertanzeige: jede Option braucht ColorActive/ColorValue, sonst zeigt die Symcon-App „Invalid Configuration“
                        ['Value' => false, 'Caption' => $this->Translate('no'), 'IconActive' => false, 'IconValue' => '', 'ColorActive' => false, 'ColorValue' => -1],
                        ['Value' => true, 'Caption' => $this->Translate('yes'), 'IconActive' => true, 'IconValue' => 'cloud-rain', 'ColorActive' => true, 'ColorValue' => 0x4B8EF0],
                    ]),
                ];
            case 'warnlevel':
                return [
                    'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                    'ICON'         => $icon,
                    'OPTIONS'      => (string) json_encode([
                        ['Value' => 0, 'Caption' => $this->Translate('none'), 'IconActive' => true, 'IconValue' => 'circle-check', 'Color' => 0x34B36B],
                        ['Value' => 1, 'Caption' => $this->Translate('Weather warning'), 'IconActive' => true, 'IconValue' => 'triangle-exclamation', 'Color' => 0xF2C744],
                        ['Value' => 2, 'Caption' => $this->Translate('Warning of markedly severe weather'), 'IconActive' => true, 'IconValue' => 'triangle-exclamation', 'Color' => 0xF08A24],
                        ['Value' => 3, 'Caption' => $this->Translate('Severe weather warning'), 'IconActive' => true, 'IconValue' => 'triangle-exclamation', 'Color' => 0xE5484D],
                        ['Value' => 4, 'Caption' => $this->Translate('Warning of extreme weather'), 'IconActive' => true, 'IconValue' => 'triangle-exclamation', 'Color' => 0x9B4DCA],
                    ]),
                ];
        }
        return '';
    }

    // ------------------------------------------------------------------
    // Kachel
    // ------------------------------------------------------------------

    /**
     * Liefert das HTML der Kachel für die Kachel-Visualisierung.
     */
    public function GetVisualizationTile(): string
    {
        $html = (string) file_get_contents(__DIR__ . '/tile.html');
        $data = json_decode($this->ReadAttributeString('TileData'), true);
        $json = (string) json_encode(is_array($data) && $data !== [] ? $data : null, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return str_replace('/*INITIAL_DATA*/null', $json, $html);
    }

    private function PushTile(array $data): void
    {
        $json = (string) json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if ($json === $this->ReadAttributeString('TileData')) {
            return; // unverändert: nichts an die Visualisierung senden
        }
        $this->WriteAttributeString('TileData', $json);
        if ($this->ReadPropertyBoolean('UseTile')) {
            $this->UpdateVisualizationValue($json);
        }
    }

    /**
     * Zeigt einen Fehler in der Kachel an, behält aber die letzten Werte.
     */
    private function PushTileError(string $message): void
    {
        $data = json_decode($this->ReadAttributeString('TileData'), true) ?: [];
        $data['theme'] = $this->ReadPropertyInteger('TileTheme');
        $data['error'] = $message;
        $this->PushTile($data);
    }

    // ------------------------------------------------------------------
    // Hilfsfunktionen
    // ------------------------------------------------------------------

    /**
     * Übernimmt die Messwerte der DWD-Station in $values (nur frische, vorhandene Werte).
     *
     * @return array{name:string,id:string,distance:float,time:int}|null Angaben zur Station oder null
     */
    private function ApplyStation(?array $response, array &$values): ?array
    {
        // nicht genutzt oder Abruf fehlgeschlagen: Modellwerte bleiben stehen
        if ($response === null) {
            return null;
        }
        $weather = $response['weather'] ?? null;
        if (!is_array($weather)) {
            return null;
        }
        $time = $this->ParseTime($weather['timestamp'] ?? null);
        if ($time === 0 || time() - $time > self::STATION_MAX_AGE) {
            $this->SendDebug('Station', 'Messwerte zu alt oder ohne Zeitstempel – Modellwerte werden verwendet', 0);
            return null;
        }

        $source = null;
        foreach ((array) ($response['sources'] ?? []) as $s) {
            if (is_array($s) && (int) ($s['id'] ?? 0) === (int) ($weather['source_id'] ?? -1)) {
                $source = $s;
                break;
            }
        }

        $pick = static function (array $keys) use ($weather): ?float {
            foreach ($keys as $key) {
                if (isset($weather[$key]) && is_numeric($weather[$key])) {
                    return (float) $weather[$key];
                }
            }
            return null;
        };
        $factor = ['kmh' => 1.0, 'ms' => 1 / 3.6, 'kn' => 1 / 1.852][$this->WindUnit()];

        $temperature = $pick(['temperature']);
        if ($temperature !== null) {
            // gefühlte Temperatur: Abstand des Modells auf den Messwert übertragen
            $values['ApparentTemperature'] = round($temperature + ($values['ApparentTemperature'] - $values['Temperature']), 1);
            $values['Temperature'] = round($temperature, 1);
        }
        if (($v = $pick(['relative_humidity'])) !== null) {
            $values['Humidity'] = (int) round($v);
        }
        if (($v = $pick(['dew_point'])) !== null) {
            $values['DewPoint'] = round($v, 1);
        }
        if (($v = $pick(['pressure_msl'])) !== null) {
            $values['Pressure'] = round($v, 1);
        }
        if (($v = $pick(['wind_speed_10', 'wind_speed_30', 'wind_speed_60'])) !== null) {
            $values['WindSpeed'] = round($v * $factor, 1);
        }
        if (($v = $pick(['wind_gust_speed_10', 'wind_gust_speed_30', 'wind_gust_speed_60'])) !== null) {
            $values['WindGusts'] = round($v * $factor, 1);
        }
        if (($v = $pick(['wind_direction_10', 'wind_direction_30', 'wind_direction_60'])) !== null) {
            $values['WindDirection'] = (int) round($v) % 360;
        }
        if (($v = $pick(['precipitation_10', 'precipitation_30'])) !== null) {
            $values['Precipitation'] = round($v, 1);
        }
        if (($v = $pick(['cloud_cover'])) !== null) {
            $values['CloudCover'] = (int) round($v);
        }
        if (($v = $pick(['visibility'])) !== null) {
            $values['Visibility'] = round($v / 1000, 1);
        }
        $values['DataTime'] = $time;

        $info = [
            'name'     => trim((string) ($source['station_name'] ?? '')),
            'id'       => (string) ($source['dwd_station_id'] ?? $source['wmo_station_id'] ?? ''),
            'distance' => round((float) ($source['distance'] ?? 0) / 1000, 1),
            'time'     => $time,
        ];
        $this->SetBuffer('Station', (string) json_encode($info));
        return $info;
    }

    private function StationLabel(array $station): string
    {
        $name = $station['name'] !== '' ? $station['name'] : $this->Translate('DWD station');
        return sprintf($this->Translate('Measured: %s · %s km'), $name, number_format((float) $station['distance'], 1, $this->Language() === 'de' ? ',' : '.', ''));
    }

    private function StationMaxDistance(): int
    {
        return max(1, min(50, $this->ReadPropertyInteger('StationMaxDistance')));
    }

    private function WarningsWanted(): bool
    {
        return $this->ReadPropertyBoolean('ShowWarnings') || ($this->ReadPropertyBoolean('UseTile') && $this->ReadPropertyBoolean('TileShowWarnings'));
    }

    private function IntervalMinutes(): int
    {
        return max(5, min(180, $this->ReadPropertyInteger('Interval')));
    }

    private function WindUnit(): string
    {
        $unit = $this->ReadPropertyString('WindUnit');
        return isset(self::WIND_UNITS[$unit]) ? $unit : 'kmh';
    }

    private function Language(): string
    {
        // „language-code“ ist in locale.json für Deutsch mit „de“ übersetzt
        return $this->Translate('language-code') === 'de' ? 'de' : 'en';
    }

    private function Condition(int $code): string
    {
        return $this->Translate(self::WMO[$code][0] ?? 'Unknown');
    }

    private function DirectionName(int $degrees): string
    {
        $names = $this->Language() === 'de'
            ? ['N', 'NO', 'O', 'SO', 'S', 'SW', 'W', 'NW']
            : ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
        return $names[(int) round(($degrees % 360) / 45) % 8];
    }

    private function ParseTime(mixed $value): int
    {
        if (!is_string($value) || $value === '') {
            return 0;
        }
        $time = strtotime($value);
        return $time === false ? 0 : $time;
    }

    /**
     * Zeitraum einer Warnung in Ortszeit, z. B. „06.10. 14:00 – 22:00“.
     */
    private function Period(int $from, int $to, int $offset): string
    {
        if ($from === 0) {
            return '';
        }
        $start = gmdate('d.m. H:i', $from + $offset);
        if ($to === 0) {
            return $this->Translate('from') . ' ' . $start;
        }
        $sameDay = gmdate('Ymd', $from + $offset) === gmdate('Ymd', $to + $offset);
        return $start . ' – ' . gmdate($sameDay ? 'H:i' : 'd.m. H:i', $to + $offset);
    }

    private function SetValueIfChanged(string $ident, mixed $value): void
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if (!is_int($id) || $id <= 0) {
            return;
        }
        if (GetValue($id) !== $value) {
            $this->SetValue($ident, $value);
        }
    }

    private function InjectProperty(array &$elements, string $name, string $key, mixed $value): void
    {
        foreach ($elements as &$element) {
            if (($element['name'] ?? '') === $name) {
                $element[$key] = $value;
            }
            if (isset($element['items']) && is_array($element['items'])) {
                $this->InjectProperty($element['items'], $name, $key, $value);
            }
        }
    }
}
