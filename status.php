<?
// Polled directly by the JS below (same-origin, via plugin.php&nopage=1) so
// the browser doesn't have to fetch cross-port :32322 itself, which would
// hit CORS since libhttpserver doesn't send Access-Control-Allow-Origin.
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $json = @file_get_contents("http://127.0.0.1:32322/DMXInput/status");
    echo $json !== false ? $json : 'null';
    return;
}

// Same reasoning, for the "Simulate" tool's POST: proxied server-side
// (PHP to :32322, not a browser fetch) so it isn't subject to the same
// CORS restriction. Body is passed through as-is to the C++ side's own
// render_POST(), which does the actual validation.
if (isset($_GET['simulate'])) {
    header('Content-Type: application/json');
    $body = file_get_contents('php://input');
    $ctx = stream_context_create(array('http' => array(
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => $body,
        'ignore_errors' => true,
    )));
    $json = @file_get_contents("http://127.0.0.1:32322/DMXInput/status", false, $ctx);
    echo $json !== false ? $json : '{"error":"could not reach plugin"}';
    return;
}
?>

<div id="global" class="settings">
<fieldset>
<legend>DMX Input Status</legend>

<div id="dmxStatusError" style="display:none;">
Could not reach the plugin's status endpoint on port 32322. Either fppd
hasn't loaded the plugin yet (check that it's enabled and restart fppd),
or no inputs are configured/enabled.
</div>

<div id="dmxClashWarning" style="display:none;padding:10px;margin-bottom:10px;border:2px solid #c0392b;border-radius:4px;background:rgba(192,57,43,0.15);">
<b>&#9888; Port conflict:</b> <span id="dmxClashList"></span> configured as
input here AND enabled as a core channel output (Channel Outputs &rarr;
Other) on the same device - the input was refused, not opened, to avoid
two things driving/reading the same UART at once. Disable the conflicting
output there, or change this input's device, then restart FPPD.
</div>

<table id="dmxStatusTable" class="fppSettingsTable" style="width:100%;text-align:left;display:none;">
<tr>
<th>Label</th><th>Device</th><th>Enabled</th><th>Port Open</th>
<th>Channels</th><th>Signal</th><th>Last Frame</th>
<th>Packets</th><th>Bytes</th><th>Errors</th>
</tr>
<tbody id="dmxStatusBody"></tbody>
</table>
<p id="dmxStatusHint" style="display:none;">
Updates every 2 seconds without reloading the page. A high or climbing
Errors count with no real Signal usually means noise on a floating/undriven
Rx line - check the DE/RE jumper is set to GND and a real DMX source is
actually wired in.
</p>

</fieldset>
<br>

<fieldset>
<legend>Simulate / Test Input</legend>
Injects one channel value straight into FPP's channel data and the trigger
engine, bypassing the UART entirely - use this to prove a trigger actually
fires (watch its Times Fired count below) without needing a real DMX
source connected. Channel is a position in FPP's own channel space, so if
an input has been remapped away from 1-512 (see its Start Ch. on the
config page), use that remapped number here to test it.
<div style="margin-top:10px;">
Channel: <input type="text" id="dmxSimChannel" size="6" maxlength="6" value="1">
&nbsp; Value (0-255): <input type="text" id="dmxSimValue" size="6" maxlength="3" value="255">
&nbsp; <button class="buttons" type="button" onClick="dmxSimulateSend();">Send</button>
<span id="dmxSimResult" style="margin-left:10px;"></span>
</div>
</fieldset>
<br>

<fieldset>
<legend>Trigger Status</legend>
<table id="dmxTriggerTable" class="fppSettingsTable" style="width:100%;text-align:left;display:none;">
<tr>
<th>Trigger</th><th>Enabled</th><th>Channels</th><th>Value Range</th><th>Command</th><th>Times Fired</th><th>Last Result</th>
</tr>
<tbody id="dmxTriggerBody"></tbody>
</table>
</fieldset>
<br>

<fieldset>
<legend>Live Channel Values</legend>
Each cell is one DMX channel; brighter = higher value (0-255). A
<span style="border-bottom:3px solid #3498db;">blue underline</span> marks
a channel an enabled trigger is watching; a cell
<span style="box-shadow:0 0 6px 2px #f1c40f;padding:0 3px;">flashes yellow</span>
the instant that trigger actually fires - not on a timer, only on a real
fire, so you can watch a trigger catch a value in real time. Hover a cell
for its exact channel number and value. Only shown for enabled inputs.
<div id="dmxMeters" style="margin-top:10px;"></div>
</fieldset>
</div>

<style>
@keyframes dmxFireFlash {
    0% { box-shadow: 0 0 10px 3px #f1c40f; }
    100% { box-shadow: 0 0 0 0 transparent; }
}
.dmx-flash { animation: dmxFireFlash 1.5s ease-out; }
.dmx-trig-watched { border-bottom: 3px solid #3498db !important; }
</style>

<script>
function dmxEscapeHtml(s) {
    return String(s).replace(/[&<>"]/g, function(c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
}

function dmxRefreshStatus() {
    // If this page's own table is no longer in the DOM, the user has
    // navigated elsewhere (via FPP's AJAX page-swap) - stop polling
    // instead of hitting the server forever in the background.
    if (!document.getElementById('dmxStatusTable')) {
        clearInterval(window.dmxStatusInterval);
        return;
    }
    fetch('plugin.php?plugin=fpp-dmx-input&page=status.php&nopage=1&ajax=1', { cache: 'no-store' })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            var errorDiv = document.getElementById('dmxStatusError');
            var clashDiv = document.getElementById('dmxClashWarning');
            var table = document.getElementById('dmxStatusTable');
            var hint = document.getElementById('dmxStatusHint');
            var body = document.getElementById('dmxStatusBody');
            var trigTable = document.getElementById('dmxTriggerTable');
            var trigBody = document.getElementById('dmxTriggerBody');

            var ports = data && data.inputs;
            if (!ports) {
                errorDiv.style.display = '';
                clashDiv.style.display = 'none';
                table.style.display = 'none';
                hint.style.display = 'none';
                trigTable.style.display = 'none';
                document.getElementById('dmxMeters').innerHTML = '';
                return;
            }
            errorDiv.style.display = 'none';
            table.style.display = '';
            hint.style.display = '';

            var clashed = ports.filter(function(p) { return p.outputClash; });
            if (clashed.length) {
                var verb = clashed.length > 1 ? ' are' : ' is';
                document.getElementById('dmxClashList').textContent =
                    clashed.map(function(p) { return p.label + ' (/dev/' + p.device + ')'; }).join(', ') + verb;
                clashDiv.style.display = '';
            } else {
                clashDiv.style.display = 'none';
            }

            var rows = '';
            for (var i = 0; i < ports.length; i++) {
                var p = ports[i];
                var lastFrame = p.lastFrameAgeMS < 0 ? 'never' : (p.lastFrameAgeMS + ' ms ago');
                var openCell = p.opened ? 'Yes' :
                    (p.outputClash ? "<span style='color:#c0392b;font-weight:bold;'>No - port conflict</span>" : 'No');
                rows += '<tr>' +
                    '<td>' + dmxEscapeHtml(p.label) + '</td>' +
                    '<td>/dev/' + dmxEscapeHtml(p.device) + '</td>' +
                    '<td>' + (p.enabled ? 'Yes' : 'No') + '</td>' +
                    '<td>' + openCell + '</td>' +
                    '<td>' + p.startChannel + '-' + (p.startChannel + p.channelCount - 1) + '</td>' +
                    '<td style="color:' + (p.signalOk ? 'green' : 'red') + ';font-weight:bold;">' +
                        (p.signalOk ? 'OK' : 'No Signal') + '</td>' +
                    '<td>' + lastFrame + '</td>' +
                    '<td>' + p.packetsReceived + '</td>' +
                    '<td>' + p.bytesReceived + '</td>' +
                    '<td>' + p.errorPackets + '</td>' +
                    '</tr>';
            }
            body.innerHTML = rows;

            var trigs = data.triggers || [];
            var enabledTrigs = trigs.filter(function(t) { return t.enabled; });
            if (enabledTrigs.length === 0) {
                trigTable.style.display = 'none';
            } else {
                trigTable.style.display = '';
                var trows = '';
                for (var j = 0; j < trigs.length; j++) {
                    var t = trigs[j];
                    if (!t.enabled) continue;
                    var resultCell = !t.hasResult ? '<i>never fired</i>' :
                        ('<span style="color:' + (t.lastResultError ? '#c0392b' : 'green') + ';">' +
                            dmxEscapeHtml(t.lastResultMsg || (t.lastResultError ? 'error' : 'ok')) + '</span>');
                    trows += '<tr>' +
                        '<td>' + (j + 1) + '</td>' +
                        '<td>' + (t.enabled ? 'Yes' : 'No') + '</td>' +
                        '<td>' + t.startChannel + '-' + t.endChannel + '</td>' +
                        '<td>' + t.valueMin + '-' + t.valueMax + '</td>' +
                        '<td>' + dmxEscapeHtml(t.command || '(none)') + '</td>' +
                        '<td>' + t.fireCount + '</td>' +
                        '<td>' + resultCell + '</td>' +
                        '</tr>';
                }
                trigBody.innerHTML = trows;
            }

            dmxRenderMeters(ports, trigs);
        })
        .catch(function() {
            document.getElementById('dmxStatusError').style.display = '';
            document.getElementById('dmxClashWarning').style.display = 'none';
            document.getElementById('dmxStatusTable').style.display = 'none';
            document.getElementById('dmxStatusHint').style.display = 'none';
            document.getElementById('dmxTriggerTable').style.display = 'none';
            document.getElementById('dmxMeters').innerHTML = '';
        });
}

// A pure rgb(v,v,v) mapping puts value=0 at pure black, which is
// invisible against this page's own dark theme background - an idle
// meter would look like nothing rendered at all rather than "all zero".
// Interpolating from a dim-but-visible blue-gray up to a bright amber
// keeps the grid's structure visible at rest, while still reading as
// "off" vs "on" at a glance.
function dmxMeterColor(v) {
    var t = v / 255;
    var r = Math.round(40 + t * (255 - 40));
    var g = Math.round(40 + t * (200 - 40));
    var b = Math.round(60 + t * (60 - 60));
    return 'rgb(' + r + ',' + g + ',' + b + ')';
}

// Which trigger(s) - by label, for the tooltip - watch each channel,
// built fresh every poll from the current enabled trigger list. A
// channel can be watched by more than one trigger (that's exactly what
// the overlap warning on the config page flags), so this is channel ->
// array of trigger descriptions, not a single value.
function dmxTriggerChannelMap(triggers) {
    var map = {};
    for (var i = 0; i < triggers.length; i++) {
        var t = triggers[i];
        if (!t.enabled) {
            continue;
        }
        for (var ch = t.startChannel; ch <= t.endChannel; ch++) {
            (map[ch] = map[ch] || []).push((t.command || '(not configured)') + ' [' + t.valueMin + '-' + t.valueMax + ']');
        }
    }
    return map;
}

// Compares this poll's trigger fireCounts against the previous poll's
// (window.dmxPrevFireCounts, keyed by array index - stable within a
// session since the trigger list only changes on Save, which reloads the
// page) to find which triggers fired since the last check, then returns
// the set of channels those triggers cover, to flash. Real per-fire
// detection, not a fixed-interval animation - a trigger that hasn't
// fired stays dark even while its watched channel sits lit up in-band.
function dmxJustFiredChannels(triggers) {
    var prev = window.dmxPrevFireCounts || {};
    var fired = {};
    var next = {};
    for (var i = 0; i < triggers.length; i++) {
        var t = triggers[i];
        next[i] = t.fireCount;
        if (t.enabled && prev[i] != null && t.fireCount > prev[i]) {
            for (var ch = t.startChannel; ch <= t.endChannel; ch++) {
                fired[ch] = true;
            }
        }
    }
    window.dmxPrevFireCounts = next;
    return fired;
}

// One compact grid of small cells per enabled input, color = value
// (0-255, see dmxMeterColor), a blue underline for channels an enabled
// trigger watches, and a one-shot flash (dmxJustFiredChannels(), a real
// CSS @keyframes animation that plays once on a freshly-rendered element
// and needs no JS cleanup) for channels whose trigger just fired - so a
// trigger's config, its target channel, and it actually catching a value
// are all visible together instead of only inferable from a table of
// bare numbers. Grid is rebuilt each refresh rather than patched
// cell-by-cell - simpler, and 512 divs every 2s is cheap for a browser.
function dmxRenderMeters(ports, triggers) {
    var container = document.getElementById('dmxMeters');
    var enabledPorts = ports.filter(function(p) { return p.enabled; });
    if (enabledPorts.length === 0) {
        container.innerHTML = '<i>No inputs enabled.</i>';
        return;
    }
    var trigMap = dmxTriggerChannelMap(triggers || []);
    var justFired = dmxJustFiredChannels(triggers || []);

    var html = '';
    for (var i = 0; i < enabledPorts.length; i++) {
        var p = enabledPorts[i];
        var values = p.values || [];
        html += '<div style="margin-bottom:14px;">' +
            '<b>' + dmxEscapeHtml(p.label) + '</b> (channels ' + p.startChannel + '-' +
            (p.startChannel + p.channelCount - 1) + ')<br>' +
            '<div style="display:grid;grid-template-columns:repeat(32,1fr);gap:1px;max-width:520px;margin-top:4px;">';
        for (var c = 0; c < values.length; c++) {
            var v = values[c];
            var ch = p.startChannel + c;
            var watchers = trigMap[ch];
            var cls = watchers ? 'dmx-trig-watched' : '';
            if (justFired[ch]) {
                cls += ' dmx-flash';
            }
            var title = 'Ch ' + ch + ': ' + v + (watchers ? ('\nWatched by: ' + watchers.join(', ')) : '');
            html += '<div title="' + dmxEscapeAttrTitle(title) + '" class="' + cls + '" style="height:14px;background:' +
                dmxMeterColor(v) + ';border:1px solid #333;"></div>';
        }
        html += '</div></div>';
    }
    container.innerHTML = html;
}

function dmxEscapeAttrTitle(s) {
    return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;');
}

function dmxSimulateSend() {
    var channel = parseInt(document.getElementById('dmxSimChannel').value);
    var value = parseInt(document.getElementById('dmxSimValue').value);
    var resultEl = document.getElementById('dmxSimResult');
    if (isNaN(channel) || channel < 1 || isNaN(value) || value < 0 || value > 255) {
        resultEl.textContent = 'Channel must be 1 or greater, value 0-255.';
        resultEl.style.color = '#c0392b';
        return;
    }
    fetch('plugin.php?plugin=fpp-dmx-input&page=status.php&nopage=1&simulate=1', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ channel: channel, value: value })
    })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data && data.ok) {
                resultEl.textContent = 'Sent: channel ' + data.channel + ' = ' + data.value;
                resultEl.style.color = 'green';
                dmxRefreshStatus(); // don't wait for the next 2s poll
            } else {
                resultEl.textContent = (data && data.error) || 'Failed';
                resultEl.style.color = '#c0392b';
            }
        })
        .catch(function() {
            resultEl.textContent = 'Failed to reach the plugin';
            resultEl.style.color = '#c0392b';
        });
}

// FPP's UI swaps plugin pages in via AJAX (jQuery .html(), which executes
// embedded <script> tags) rather than always doing a hard navigation, so
// this script can re-run several times in the same document without a
// reload. Keying the interval id off window (not a local var) means each
// re-run clears whatever poller a previous run left behind instead of
// leaking another one alongside it.
if (window.dmxStatusInterval) {
    clearInterval(window.dmxStatusInterval);
}
dmxRefreshStatus();
window.dmxStatusInterval = setInterval(dmxRefreshStatus, 2000);
</script>
