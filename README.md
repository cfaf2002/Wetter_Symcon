# Wetter für IP-Symcon

[![IP-Symcon ab 8.1](https://img.shields.io/badge/IP--Symcon-ab_8.1-0b6fb3.svg)](https://www.symcon.de)
[![Optimiert für Symcon 9.0](https://img.shields.io/badge/optimiert_f%C3%BCr-Symcon_9.0-0b6fb3.svg)](https://www.symcon.de/de/service/dokumentation/installation/migrationen/v81-v90-q1-2026/)
[![Modul-Version 1.0 (Build 1)](https://img.shields.io/badge/Modul--Version-1.0_(Build_1)-informational.svg)](library.json)
[![Tests](https://github.com/cfaf2002/Wetter_Symcon/actions/workflows/tests.yml/badge.svg)](https://github.com/cfaf2002/Wetter_Symcon/actions/workflows/tests.yml)
[![PHP 8.3 und 8.5](https://img.shields.io/badge/PHP-8.3_%7C_8.5-777bb4.svg?logo=php&logoColor=white)](https://www.php.net)
[![SDK: IPSModuleStrict](https://img.shields.io/badge/SDK-IPSModuleStrict-success.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/)
[![Variablen: Darstellungen](https://img.shields.io/badge/Variablen-Darstellungen-success.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/darstellungen/)
[![Kachel-Visualisierung: HTML-SDK](https://img.shields.io/badge/Kachel--Visualisierung-HTML--SDK-orange.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/html-sdk/)
[![Farbschema: Symcon-Design, Dunkel, Hell](https://img.shields.io/badge/Farbschema-Symcon--Design_%7C_Dunkel_%7C_Hell-blueviolet.svg)](STYLEGUIDE.md)
![Sprachen: Deutsch | Englisch](https://img.shields.io/badge/Sprachen-Deutsch_%7C_Englisch-blueviolet.svg)
[![Lizenz: MIT](https://img.shields.io/badge/Lizenz-MIT-green.svg)](LICENSE)
[![Wetterdaten: Open-Meteo](https://img.shields.io/badge/Wetterdaten-Open--Meteo_(CC_BY_4.0)-lightgrey.svg)](https://open-meteo.com)
[![Warnungen: DWD über Bright Sky](https://img.shields.io/badge/Warnungen-DWD_%C3%BCber_Bright_Sky-lightgrey.svg)](https://brightsky.dev)
![Ohne API-Schlüssel](https://img.shields.io/badge/API--Schl%C3%BCssel-nicht_n%C3%B6tig-lightgrey.svg)

IP-Symcon-Modul für aktuelles Wetter und Vorhersage von [Open-Meteo](https://open-meteo.com) sowie amtliche Wetterwarnungen des Deutschen Wetterdienstes über [Bright Sky](https://brightsky.dev) – ohne Konto und ohne API-Schlüssel, mit eigener Kachel für die Kachel-Visualisierung.

> Kein offizielles Produkt von Open-Meteo, Bright Sky oder dem Deutschen Wetterdienst. Beide Schnittstellen sind öffentlich und für die private, nicht kommerzielle Nutzung frei.

Autor: Armin Frohwerk · Lizenz: MIT

## Inhalt

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen und Technik](#2-voraussetzungen-und-technik)
3. [Installation](#3-installation)
4. [Einrichtung](#4-einrichtung)
5. [Kachel](#5-kachel)
6. [Variablen und Darstellungen](#6-variablen-und-darstellungen)
7. [PHP-Befehle](#7-php-befehle)
8. [Sicherheit und Geschwindigkeit](#8-sicherheit-und-geschwindigkeit)
9. [Entwicklung und Tests](#9-entwicklung-und-tests)
10. [Datenquellen](#10-datenquellen)
11. [Changelog](#11-changelog)
12. [Lizenz](#12-lizenz)

## 1. Funktionsumfang

- **Aktuelles Wetter:** Temperatur, gefühlte Temperatur, Luftfeuchte, Wetterlage, Niederschlag, Wind, Böen, Windrichtung
- **Details (zuschaltbar):** Taupunkt, Bewölkung, Luftdruck, Sichtweite, UV-Index
- **Sonne (zuschaltbar):** Sonnenaufgang, Sonnenuntergang, Sonnenscheindauer heute
- **Vorhersage (zuschaltbar):** Höchst-/Tiefstwerte und Regenwahrscheinlichkeit für heute und morgen, Wetterlage morgen und der Schalter **„Regen in den nächsten 2 Stunden“** – praktisch für Markise, Bewässerung oder Fenster-Hinweise
- **Unwetterwarnungen des DWD:** höchste Warnstufe (1 gelb bis 4 violett), Anzahl und Text der aktiven Warnungen; einstellbar, ab welcher Stufe gewarnt wird
- **Kachel:** aktuelles Wetter, Kurzwerte, Warnungen mit aufklappbaren Details, Stundenleiste und 7-Tage-Übersicht mit Temperaturbalken; passt sich der Kachelgröße an
- Standort aus Symcon (Kern-Instanzen → Location) oder eigener Standort auf der Karte
- Wahl des Wettermodells (automatisch, DWD ICON, ECMWF, GFS, Météo-France, MET Norway) und der Windeinheit (km/h, m/s, Knoten)

## 2. Voraussetzungen und Technik

- IP-Symcon ab 8.1, optimiert für 9.0 (PHP 8.3 und 8.5)
- Internetzugang (HTTPS zu `api.open-meteo.com` und `api.brightsky.dev`)
- Basisklasse `IPSModuleStrict`, alle Variablen mit Darstellungen (keine eigenen Profile)
- Kachel über das HTML-SDK (`tile.html`)
- Warnungen gibt es nur für Standorte in Deutschland; außerhalb zeigt das Modul einen Hinweis und arbeitet sonst normal weiter

## 3. Installation

Im Objektbaum unter *Kern-Instanzen → Modules* die URL hinzufügen:

```
https://github.com/cfaf2002/Wetter_Symcon
```

Danach eine Instanz **„Wetter“** anlegen (Hersteller „Open-Meteo / DWD“).

## 4. Einrichtung

| Einstellung | Bedeutung |
| :-- | :-- |
| Standort von Symcon verwenden | nimmt die Koordinaten aus *Kern-Instanzen → Location*; ausgeschaltet erscheint eine Karte für einen eigenen Standort |
| Name in der Kachel | Überschrift der Kachel; leer = Name der Instanz |
| Wettermodell | *Automatisch* wählt das beste Modell für den Standort (in Deutschland meist DWD ICON) |
| Abfrageintervall | 5–180 Minuten, Standard 15 |
| Einheit der Windgeschwindigkeit | km/h, m/s oder Knoten |
| Variablen | Details, Sonne und Vorhersage einzeln zuschaltbar; abgeschaltete Variablen werden entfernt |
| Wetterwarnungen (DWD) | Variablen für Warnungen und Mindeststufe |
| Kachel | eigene Kachel, Farbschema und welche Bereiche angezeigt werden |

Ein Ausfall des Abrufs wird zweimal still überbrückt; erst ab dem dritten Fehlschlag in Folge geht die Instanz auf *Fehler* (Status 201) und die Kachel zeigt einen Hinweis. Die letzten Werte bleiben stehen.

## 5. Kachel

| Kachelgröße | Inhalt |
| :-- | :-- |
| klein (1×1) | Symbol, Temperatur, Wetterlage, schwerste Warnung |
| mittel (2×2) | zusätzlich Kurzwerte, Stundenleiste, schwerste Warnung mit Zahl der weiteren |
| hoch (2×4) | zusätzlich alle Warnungen und die Tagesübersicht |
| breit (4×2) | zweispaltig: links aktuelles Wetter und Warnungen, rechts die Tage, unten die Stunden |

**Farbschema der Kachel:** *Symcon-Design* (Farben der Visualisierung, kein eigener Hintergrund), *Dunkel*, *Hell* und *Wetter* – ein Himmel passend zu Wetterlage und Tageszeit, darauf immer helle Schrift.

Warnungen lassen sich mit „Details“ aufklappen (Beschreibung und Handlungsempfehlung des DWD). Die Schaltfläche unten rechts fragt sofort neu ab (höchstens alle 30 Sekunden). Die Wettersymbole sind eigene SVG-Grafiken; Sonne und Regen bewegen sich leicht, ruhen aber, wenn die Kachel nicht sichtbar ist oder das System „Bewegung reduzieren“ verlangt.

## 6. Variablen und Darstellungen

| Ident | Name | Typ | Gruppe |
| :-- | :-- | :-- | :-- |
| `Temperature` | Temperatur | Float, °C | immer |
| `ApparentTemperature` | Gefühlt | Float, °C | immer |
| `Humidity` | Luftfeuchte | Integer, % | immer |
| `WeatherCode` | Wetterlage | Integer, Aufzählung (WMO-Code mit Text und Symbol) | immer |
| `Precipitation` | Niederschlag (aktuell) | Float, mm | immer |
| `WindSpeed` / `WindGusts` | Windgeschwindigkeit / Windböen | Float, gewählte Einheit | immer |
| `WindDirection` | Windrichtung | Integer, ° (woher) | immer |
| `DataTime` | Datenzeitpunkt | Integer, Zeitstempel | immer |
| `DewPoint`, `CloudCover`, `Pressure`, `Visibility`, `UVIndex` | Taupunkt, Bewölkung, Luftdruck (hPa, Meereshöhe), Sichtweite (km), UV-Index | | Details |
| `Sunrise`, `Sunset`, `SunshineDuration` | Sonnenaufgang, Sonnenuntergang, Sonnenscheindauer heute (h) | | Sonne |
| `TodayMax`, `TodayMin`, `TodayPrecipitation`, `TodayPrecipProbability` | heute: Höchst-, Tiefstwert, Niederschlag, Regenwahrscheinlichkeit | | Vorhersage |
| `TomorrowCode`, `TomorrowMax`, `TomorrowMin`, `TomorrowPrecipProbability` | morgen: Wetterlage, Höchst-, Tiefstwert, Regenwahrscheinlichkeit | | Vorhersage |
| `RainSoon` | Regen in den nächsten 2 Stunden | Boolean | Vorhersage |
| `WarningLevel` | Warnstufe | Integer, Aufzählung 0–4 in den DWD-Farben | Warnungen |
| `WarningCount` | Anzahl Warnungen | Integer | Warnungen |
| `WarningText` | Warnungen | String, mehrzeilig | Warnungen |

Variablen werden nur geschrieben, wenn sich ihr Wert ändert – Ereignisse auf „Änderung“ lösen also nur bei echten Änderungen aus.

## 7. PHP-Befehle

```php
// Sofort abfragen (true bei Erfolg)
WETTER_Update(int $InstanzID): bool;

// Letzte Daten als JSON: current, hourly (48 h), daily (7 Tage), warnings
$daten = json_decode(WETTER_GetForecast(12345), true);
echo $daten['daily'][1]['max'];          // Höchstwert morgen

// Aktive DWD-Warnungen als JSON-Liste (level, event, headline, description, instruction, from, to, period)
$warnungen = json_decode(WETTER_GetWarnings(12345), true);
```

## 8. Sicherheit und Geschwindigkeit

- Nur HTTPS mit Zertifikatsprüfung, auch bei Weiterleitungen; Zeitlimit 20 s, Antworten höchstens 5 MB
- Keine Zugangsdaten nötig; die Koordinaten werden auf vier Nachkommastellen (rund 10 m) gerundet übertragen
- Pro Abruf genau zwei Anfragen (Open-Meteo mit allen Werten in einem Aufruf, Bright Sky für die Warnungen); bei 15 Minuten sind das rund 100 Anfragen am Tag – weit unter der freien Grenze von Open-Meteo
- Variablen und Kachel werden nur bei Änderungen aktualisiert
- Kachel: Daten werden nur als Text bzw. SVG-Attribute gesetzt, nie als HTML; die Startdaten sind maskiert eingebettet; keine externen Dateien oder Schriften

## 9. Entwicklung und Tests

```
php tests/structure.php                 # Strukturprüfung nach Hausstil
php tests/run.php ../SymconStubs        # Funktionstests mit nachgebauten Antworten
php tests/stubs.php ../SymconStubs      # Ladetest mit den offiziellen Symcon-Stubs
```

Die Funktionstests prüfen u. a. Rundung und Einheiten, das Erkennen von Regen, das Filtern und Sortieren der Warnungen (Test-, Entwarnungs- und abgelaufene Meldungen), das Verhalten außerhalb Deutschlands und bei Ausfällen, die Maskierung der Kacheldaten, vollständige Darstellungen und Typangaben sowie die Übersetzungen. Der Workflow unter *Actions* führt alles für PHP 8.3 und 8.5 aus.

## 10. Datenquellen

- Wetter und Vorhersage: [Open-Meteo.com](https://open-meteo.com), Daten unter [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/); frei für nicht kommerzielle Nutzung
- Warnungen: [Deutscher Wetterdienst](https://www.dwd.de), bereitgestellt über die freie Schnittstelle [Bright Sky](https://brightsky.dev)

Die Kachel nennt beide Quellen in der Fußzeile.

## 11. Changelog

| Version | Build | Datum | Beschreibung |
| :-- | --: | :-- | :-- |
| 1.0 | 1 | 06.10.2026 | Erste Version: aktuelles Wetter, Vorhersage, DWD-Warnungen, Kachel mit vier Farbschemas |

## 12. Lizenz

MIT – siehe [LICENSE](LICENSE). Copyright (c) 2026 Armin Frohwerk.
