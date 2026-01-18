# Blaze PowerZone Connect (IP-Symcon)

Dieses Repository enthält zwei Module:

- **Blaze PowerZone Connect** (Device, Type 3)
- **Blaze PowerZone Connect Configurator** (Discovery/Configurator, Type 4)

## Architektur / Datenfluss

### Ziel
Push/Subscribe statt Polling, aber mit Watchdog-Polling als Fallback.

### Instanzen
- **Configurator** scannt IPs im Subnetz auf **TCP/7621** und erstellt eine Device-Instanz.
- **Device** ist **ohne IO in der Konfiguration** nutzbar:
  - Device erstellt/verwaltet **automatisch** einen **Client Socket** als Parent
  - Host/Port/Open werden im Device konfiguriert und in den Parent übertragen
  - Device sendet ASCII-Befehle über `SendDataToParent()` an den Client Socket.

### Push/Subscribe
- `SUBSCRIBE REG <freq>`
- optional `SUBSCRIBE DYN <freq>` für Metering

Hinweis: IPS View hat kein natives VU-Meter-Widget. Praktisch sind Diagramme/Gauges über Profile.

## Funktionen / Optionen
- **Zones** werden unter einer eigenen Kategorie angelegt.
- **Mute-Profil**: „Unmute“ (grün) / „Mute“ (rot).
- **Input-Filter** (Device-Konfiguration):
  - SPDIF Inputs
  - Dante Inputs
  - Noise Generator
  - Mixes (dynamischer Scan ab IN-500 / MIX-1)

## Installation
1. Repository in Module Control hinzufügen, aktualisieren.
2. **Discovery Instanz**: „Blaze PowerZone Connect Configurator“ erstellen.
3. Subnetz setzen, „Scan“.
4. Gerät über „Erstellen“ anlegen.
5. Im Device prüfen: Host/Port/Open stimmen. Danach „Discover“/„Subscribe“.
   - Bei Änderungen an Input-Filtern oder Mixes: „Discover“ erneut ausführen.

## Troubleshooting
- Wenn ein Modul einmal defekt geladen wurde: Repo entfernen → SymBox 10–15s stromlos → Repo neu hinzufügen.
- Wenn „Online“ false bleibt: Prüfen, ob TCP/7621 erreichbar ist (Firewall/VLAN).
