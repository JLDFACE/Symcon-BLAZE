# Blaze PowerZone Connect (IP-Symcon)

Dieses Repository enthält zwei Module:

- **Blaze PowerZone Connect** (Device, Type 3)
- **Blaze PowerZone Connect Configurator** (Discovery/Configurator, Type 4)

## Architektur / Datenfluss

- **Configurator** scannt IPs im Subnetz auf **TCP/7621** und legt beim Erstellen eine Kette an:
  - **Client Socket** (Host/Port/Open)
  - **Device** als Child des Client Socket
- **Device** nutzt den Parent (Client Socket) für ASCII-Kommandos:
  - `SUBSCRIBE REG <freq>` für Register-Updates (Push)
  - optional `SUBSCRIBE DYN <freq>` für Metering (Push)
  - Watchdog-Polling (Slow/Fast) bleibt aktiv, ist aber nicht der Primärpfad.

## Features (Device)

- **Power** (Boolean schaltbar) + **PowerState** (String)
- Pro Zone:
  - **Gain** (dB, Float)
  - **Source** (Dropdown, Integer; Names via Input-Namen)
  - **Mute** (Boolean, Pflicht)
- Optional: **Metering** (DYN) → dynamische Variablen unter Kategorie „Metering“ (Regex-Filter)
- **Stabile UI**: Pending-Source bleibt bis Istwert erreicht ist
- **Diagnose**: Online, LastError, LastOKTimestamp, ErrorCounter
- **Konservativ/SymBox-sicher**: keine PHP8-Typen, kein strict_types, keine UI-Refresh-APIs

## Installation

1. Repository in **Module Control** hinzufügen.
2. Module aktualisieren.
3. Unter **Discovery Instanzen**: **Blaze PowerZone Connect Configurator** anlegen.
4. Subnetz setzen (z. B. `192.168.1.0/24`) und **Scan**.
5. Gerät(e) über **Erstellen** anlegen.

Hinweis: Nach „Scan“ kann es nötig sein, das Konfigurationsformular kurz zu schließen und erneut zu öffnen (keine Live-Refresh-APIs).

## Metering in IPS View

IPS View hat kein natives VU-Meter-Widget. Praktisch:
- **Diagramm** auf Meter-Variablen (Historie aktivieren)
- **Gauge/Skala** über Profil Min/Max (im Modul konfigurierbar)
- Optional später: HTMLBox VU (nicht Bestandteil dieses Moduls)

## Changelog

- 1.1: Konservative IO-Architektur (Device als Child des Client Socket), ScanResult als Attribute, keine Float-Properties.
