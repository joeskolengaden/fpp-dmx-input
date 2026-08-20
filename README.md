# fpp-dmx-input

A C++ plugin that adds DMX-512 **receive** to FPP 5.x, which has no built-in
"Channel Input" feature (that was added natively in FPP 8.2). It reads a
UART wired as an RS-485 receiver and feeds the decoded universe straight
into FPP's live channel data through `Sequence::SetBridgeData()` — the same
call FPP's own E1.31/ArtNet/DDP bridges use, with the same "stale data
expires and stops overriding output" behavior.

On top of receiving DMX, it can also **trigger any FPP Command** — start a
playlist, run an effect, adjust volume, anything in FPP's own command list —
from a DMX channel crossing a threshold, configured through the same
Command Editor popup the "Run FPP Command" button uses.

## How it works

```mermaid
flowchart LR
    Console["DMX Console /\nLighting Desk"] -->|DMX-512 signal| XCVR["RS-485\nTransceiver"]
    XCVR -->|UART Rx| UART["BeagleBone UART\n/dev/ttyS1 or ttyS2"]
    UART -->|fppd epoll loop| Plugin["fpp-dmx-input\nhandleRead()"]
    Plugin -->|SetBridgeData| Channels["FPP live\nchannel data"]
    Plugin -->|channel crosses\nthreshold| Trig["Trigger rules"]
    Trig -->|CommandManager::run| Cmd["Any FPP Command\n(Start Playlist, Effects, ...)"]
    Channels --> Outputs["FPP Outputs\n(pixels, DMX, relays, ...)"]
```

- `addControlCallbacks()` opens the configured serial device via FPP's own
  `SerialOpen()` (from `channeloutput/serialutil.h`, linked in from
  `libfpp.so`), which already knows how to set DMX's non-standard 250000
  baud rate. It then adds `BRKINT` on the fd itself, since FPP 5.x's
  `SerialOpen()` doesn't set that for input mode — `BRKINT` is what makes a
  real UART break condition show up as a single `0x00` byte in the read
  stream, which is how DMX frame boundaries are found.
- The registered fd is added to fppd's own `epoll` loop, so the plugin's
  read callback fires whenever new bytes are waiting — no polling thread.
- Each full frame gets copied into FPP's channel data at the configured
  start channel via `sequence->SetBridgeData(...)`, and checked against any
  configured trigger ranges.
- The status page's Simulate tool calls the exact same `SetBridgeData()` +
  trigger-evaluation code, just fed a value from a `POST` instead of a
  UART read - it's not a separate mock path, so a trigger firing there is
  the real thing firing, not a simulation of one.

### Trigger firing

```mermaid
sequenceDiagram
    participant Console as DMX Console
    participant Plugin as fpp-dmx-input
    participant FPP as FPP Core
    Console->>Plugin: DMX frame (break + channel data)
    Plugin->>FPP: SetBridgeData(channels)
    Plugin->>Plugin: evaluateTriggers()
    alt a channel rises above its threshold
        Note over Plugin: edge-triggered + cooldown -\na held fader fires once, not every frame
        Plugin->>FPP: CommandManager::run(command, args)
        FPP-->>FPP: executes it (Start Playlist, Effect, ...)
    end
```

## Features

- **Any number of inputs** (soft cap 8) — add/remove ports as needed rather
  than being fixed to a pair. Each has its own device, label, start
  channel, channel count, and bridge-data expiry.
- **Any number of triggers** (soft cap 64) — each watches a channel range
  and runs an FPP Command. Two modes:
  - **Edge** (default) — fires once when a channel enters a value band
    (min-max, not just a single threshold — split one fader into zones if
    you want) from outside it.
  - **Continuous** — fires on every value change instead (still
    cooldown-limited), substituting a literal `$VALUE` in the command's
    arguments with the current channel value, for tracking a fader live
    (e.g. into a volume/brightness command) rather than a one-shot action.

  Configured through FPP's own real Command Editor popup (typed,
  per-argument form fields — dropdowns for enums, checkboxes for bools,
  live-populated lists for things like playlist/effect names) rather than
  a free-typed string.
- **Simulate/test tool** — inject one channel value straight into the
  bridge-data + trigger pipeline from the status page, bypassing the UART
  entirely. Lets you prove a trigger actually fires without a real DMX
  source connected.
- **Live status page** — packets/bytes/errors received per input, signal
  state, trigger fire counts, and a live per-channel value meter grid
  (brightness = value, so a real DMX source lighting up is visible at a
  glance), auto-refreshing every 2 seconds without a full page reload.
- **Port-conflict protection** — refuses to open a device that's also
  configured as an active core channel output, with a warning that updates
  live in the config UI as you change settings (see below).
- **Config export/import** — download the whole input+trigger setup as one
  file, or restore it, for replicating a working setup across boards
  instead of re-entering it by hand.
- **JSON status API** at `GET /DMXInput/status` on fppd's own port (32322)
  for anything else you want to build on top; `POST` to the same URL with
  `{"channel": N, "value": V}` drives the simulate tool.

## A UART can't be an input and a core output at the same time

```mermaid
flowchart TD
    A["fppd starts, plugin loads"] --> B{"Is this device also an\nenabled core channel output?"}
    B -->|Yes| C["Refuse to open it.\nLog the reason.\nShow a warning in the UI."]
    B -->|No| D["Open the UART for input"]
    D --> E["Listen for DMX frames"]
```

This isn't just caution: `SerialOpen()`'s exclusivity check (`TIOCEXCL`) is
bypassed for root, and `fppd` runs as root, so without this check both
sides would silently get a real file descriptor on the same UART — no
error from either side (confirmed by direct test: `fppd` stayed up,
`/proc/<pid>/fd` showed two live descriptors on one device). On a
half-duplex RS-485 port that's not just a software issue: the output side
continuously drives DE/RE to transmit, so a DE/RE-jumpered-to-GND input
can't get a genuine external signal through (most transceivers just echo
their own TX back on RO), and the DE/RE line itself ends up contested
between the two — the same class of pin contention that causes DE/RE
overheating on floating/miswired lines.

The warning shows two places:
- **Content Setup → DMX Input Configuration** — live, updates the instant
  you change a Device or Enabled field, before you've even saved.
- **Status/Control → DMX Input - Status** — reflects fppd's actual runtime
  state, in case the conflicting output was changed after the plugin loaded.

## Hardware wiring

This plugin needs a UART **Rx** pin actually wired to the RS-485
transceiver's RO pin — DMX output-only wiring doesn't give you that. Check
which `/dev/ttyX` device corresponds to which physical port on your board —
BeagleBone kernels use either `ttyO*` (TI legacy) or `ttyS*` (mainline)
naming depending on the image, and `/dev/ttyO*` is usually just a symlink
to the matching `/dev/ttyS*`.

Whichever port you use for input, its DE/RE control must be set to **GND**
(listen mode) — a driver actively transmitting can't simultaneously receive
a real external signal on the same differential pair. If your board has a
DE/RE jumper header, that's the GND position; some transceiver circuits tie
DE/RE to a GPIO instead, in which case that pin needs to be driven low.

## Installation

### Option 1 — FPP's Plugin Manager

Since this plugin isn't in FPP's built-in community plugin list, add it
manually: open the Plugin Manager page, and in the search box
("Find a Plugin or Enter a plugininfo.json URL") paste this **raw**
`pluginInfo.json` URL:

```
https://raw.githubusercontent.com/joeskolengaden/fpp-dmx-input/main/pluginInfo.json
```

That's not the repo page URL — FPP fetches this URL directly as JSON to
learn the plugin's name/description and where to `git clone` it from
(`srcURL` in that file), so it has to resolve to the raw file content, not
a GitHub webpage. Once it loads, click Install as normal; that clones
`https://github.com/joeskolengaden/fpp-dmx-input.git` into
`/home/fpp/media/plugins/fpp-dmx-input/` and runs `scripts/fpp_install.sh`.

### Option 2 — manual install (SSH)

```bash
cd /home/fpp/media/plugins
git clone https://github.com/joeskolengaden/fpp-dmx-input.git
cd fpp-dmx-input
./scripts/fpp_install.sh
```

Either way this builds `libfpp-dmx-input.so` against your FPP install
(`SRCDIR=/opt/fpp/src`). Restart fppd (or reboot) afterward — `fppd` loads
plugins via `dlopen()` at startup, so a running instance won't pick up a
freshly-built or freshly-installed plugin without a restart.

If you had inputs configured before inputs became add/remove-able (they
used to live as `label0`/`device0`/etc. keys in the plain
`plugin.fpp-dmx-input` ini file), that file is no longer read - re-enter
your input(s) once through Content Setup after updating. Triggers aren't
affected by this.

### Updating

```bash
cd /home/fpp/media/plugins/fpp-dmx-input
git pull
make SRCDIR=/opt/fpp/src
sudo /opt/fpp/scripts/fppd_restart
```

## Configuration

Everything is configurable from the FPP web UI once installed — **Content
Setup → DMX Input Configuration** for inputs and triggers (both are
add/remove-row tables, GET-whole-array/edit-locally/POST-whole-array-back,
the same pattern core's own "Other" channel-output page uses),
**Status/Control → DMX Input - Status** for live status and the simulate
tool. No manual file editing needed.

Inputs are stored as a JSON array at
`/home/fpp/media/config/plugin.fpp-dmx-input-inputs.json`:

```json
{ "inputs": [
    { "label": "DMX1", "device": "ttyS1", "enabled": false,
      "startChannel": 1, "channelCount": 512, "expireMS": 5000 }
] }
```

No config file means two default (disabled) slots, `DMX1`/`ttyS1` and
`DMX2`/`ttyS2`, matching the two physical DMX ports most BeagleBone capes
expose — a starting point, not a limit; add or remove rows as needed.

Triggers are stored the same way, at
`/home/fpp/media/config/plugin.fpp-dmx-input-triggers.json`:

```json
{ "triggers": [
    { "enabled": true, "startChannel": 100, "endChannel": 105,
      "valueMin": 50, "valueMax": 255, "continuous": false,
      "command": "Start Playlist",
      "args": ["MyShow", "false", "false", "0"], "cooldownMs": 2000 }
] }
```

`valueMin`/`valueMax` is a band, not a single threshold: an edge-mode
trigger fires when a channel's value enters that band from outside it. A
plain "above X" trigger is just `valueMax: 255`; splitting one fader into
zones (e.g. 0-84 = off, 85-169 = dim, 170-255 = full) means one trigger
per zone with non-overlapping bands. Set `continuous: true` to instead
fire on every value change (still `cooldownMs`-limited) with `$VALUE` in
`args` substituted for the live channel value each time.

## Known limitation

Break detection uses a millisecond-resolution timing heuristic (a >3ms gap
plus a `BRKINT` marker byte = new frame), ported directly from FPP's own
upstream DMX-input implementation (`e131bridge.cpp`, which uses the same
heuristic at microsecond resolution). This is a heuristic, not spec-exact
break/MAB timing — it works because DMX transmitters produce a consistent,
much-larger-than-3ms gap at the start of every frame, but a non-compliant
or very unusual DMX source could in theory confuse it.

## License

MIT
