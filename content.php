<?
// Note: command names are NOT fetched here via file_get_contents() to
// api/commands - that would be a same-server loopback HTTP call made
// *during* this page's own PHP render, which on a single/low-worker
// Apache setup (like this BeagleBone's) can deadlock the process waiting
// for a worker to serve its own nested request. The datalist below is
// populated client-side instead, via JS fetch() after the page has
// already loaded and this request has completed.
$DMXDevices = Array();
foreach (scandir("/dev/") as $fileName) {
    if ((preg_match("/^ttyS[0-9]+/", $fileName)) ||
        (preg_match("/^ttyO[0-9]+/", $fileName)) ||
        (preg_match("/^ttyUSB[0-9]+/", $fileName)) ||
        (preg_match("/^ttyACM[0-9]+/", $fileName))) {
        $DMXDevices[$fileName] = $fileName;
    }
}

// A plain local file read (not the api/commands self-HTTP-call pattern
// avoided elsewhere on this page) - safe to do during render, no loopback
// deadlock risk. Same devices-with-an-enabled-output check the C++ side
// (getEnabledOutputDevices() in src/FPPDMXInput.cpp) does at startup,
// done here too so the warning shows immediately while configuring,
// before a restart, instead of only after one in fppd.log.
$DMXOutputDevices = Array();
$coOtherFile = "/home/fpp/media/config/co-other.json";
if (file_exists($coOtherFile)) {
    $coOther = json_decode(file_get_contents($coOtherFile), true);
    if (is_array($coOther) && isset($coOther['channelOutputs'])) {
        foreach ($coOther['channelOutputs'] as $out) {
            if (!empty($out['enabled']) && isset($out['device'])) {
                $DMXOutputDevices[$out['device']] = true;
            }
        }
    }
}
?>

<div id="global" class="settings">

<fieldset>
<legend>Inputs</legend>
Each row is one of this board's two physical DMX ports. Changing anything
below auto-saves (like FPP's other settings pages) but still needs an FPPD
restart to take effect - you'll be prompted.

<div class='fppTableWrapper' style="margin-top:10px;">
<div class='fppTableContents'>
<table style="width:100%;">
<thead>
<tr class='tblheader'>
<th>#</th><th>On</th><th>Label</th><th>Device</th><th>Start Ch.</th>
<th>Channel Count</th><th>Expire (ms)</th>
</tr>
</thead>
<tbody>
<?
for ($i = 0; $i < 2; $i++) {
    $defaultLabel = ($i == 0) ? "DMX1" : "DMX2";
    $defaultDevice = ($i == 0) ? "ttyS1" : "ttyS2";
?>
<tr id="dmxInputRow<?= $i ?>">
<td><?= $i + 1 ?></td>
<td><? PrintSettingCheckbox("Input " . ($i + 1), "enabled$i", 1, 0, "1", "0", "fpp-dmx-input", "", 0); ?></td>
<td><? PrintSettingTextSaved("label$i", 1, 0, 12, 10, "fpp-dmx-input", $defaultLabel); ?></td>
<td><? PrintSettingSelect("Device", "device$i", 1, 0, $defaultDevice, $DMXDevices, "fpp-dmx-input"); ?>
<br><span id="dmxInputClashMsg<?= $i ?>" style="display:none;color:#c0392b;font-weight:bold;"></span>
</td>
<td><? PrintSettingTextSaved("startChannel$i", 1, 0, 6, 6, "fpp-dmx-input", "1"); ?></td>
<td><? PrintSettingTextSaved("channelCount$i", 1, 0, 6, 6, "fpp-dmx-input", "512"); ?></td>
<td><? PrintSettingTextSaved("expireMS$i", 1, 0, 6, 6, "fpp-dmx-input", "5000"); ?></td>
</tr>
<?
}
?>
</tbody>
</table>
</div>
</div>
<p style="margin-top:8px;">
A UART already claimed by a DMX <b>output</b> (Channel Outputs -&gt; Other)
can't also be opened here for input - the two are mutually exclusive on the
same port. Disable that port's DMX-Open output first, and set that port's
DE/RE jumper to GND so the RS-485 transceiver stays in listen mode instead
of drive mode.
</p>
</fieldset>
<br>

<fieldset>
<legend>Triggers - run an FPP Command from a DMX channel range</legend>
Watches a range of DMX channels. When any channel in the range rises from
below its threshold to at/above it (e.g. a console fader or button going
up), the configured FPP Command runs once - not repeatedly while held, and
not more often than the cooldown allows. Click "Configure" to pick the
command and its arguments using the same editor the "Run FPP Command"
button (bottom of every page) uses. Add as many as you need; nothing takes
effect until you click Save, and (like everything else on this page) an
FPPD restart afterward.

<div class='fppTableWrapper' style="margin-top:10px;">
<div class='fppTableContents'>
<table id="dmxTriggerEditTable" class="fppSelectableRowTable" style="width:100%;">
<thead>
<tr class='tblheader'>
<th>#</th><th>On</th><th>Start Ch.</th><th>End Ch.</th><th>Threshold</th>
<th>Command</th><th>Cooldown (ms)</th><th></th>
</tr>
</thead>
<tbody id="dmxTriggerEditBody"></tbody>
</table>
</div>
</div>

<div class="form-actions" style="margin-top:10px;">
<button id="btnAddTrigger" class="buttons btn-outline-success" type="button" onClick="DMXAddTriggerRow();"><i class="fas fa-plus"></i> Add</button>
<input id="btnSaveTriggers" class="buttons btn-success ml-1" type="button" value="Save" onClick="DMXSaveTriggers();">
</div>
</fieldset>
<br>

<fieldset>
<legend>Live Status</legend>
See the <a href="plugin.php?plugin=fpp-dmx-input&page=status.php">DMX Input - Status</a>
page (under the Status menu) for packets/bytes/errors received per input,
and how many times each trigger has fired.
</fieldset>

</div>

<script>
// Devices with an enabled core output right now, embedded once from PHP
// at page load (see the co-other.json read near the top of this file).
// Not re-fetched on every check - Channel Outputs -> Other is a separate
// page, so this can only go stale by leaving this page and changing it
// there, which means reloading this page anyway.
var dmxOutputDevices = <?= json_encode(array_keys($DMXOutputDevices)) ?>;

// Runs on page load AND on every change to an input's Device/Enabled
// field (bound below) so the warning tracks what's actually selected
// right now, not just what was saved when the page was rendered -
// PrintSettingSelect/Checkbox auto-save on change without a page reload,
// so a purely server-side, render-time-only check would go stale the
// moment someone picks a different device.
function DMXCheckInputClash(i) {
    var deviceEl = document.getElementById('device' + i);
    var enabledEl = document.getElementById('enabled' + i);
    var msgEl = document.getElementById('dmxInputClashMsg' + i);
    var rowEl = document.getElementById('dmxInputRow' + i);
    if (!deviceEl || !enabledEl || !msgEl || !rowEl) {
        return;
    }
    var device = deviceEl.value;
    var enabled = enabledEl.checked;
    var clashed = enabled && dmxOutputDevices.indexOf(device) !== -1;

    if (clashed) {
        msgEl.innerHTML = '&#9888; /dev/' + dmxEscapeAttr(device) + ' is also an enabled output on ' +
            '<a href="channeloutputs.php#tab-other">Channel Outputs &rarr; Other</a> - this input ' +
            'will be refused, not opened.';
        msgEl.style.display = '';
        rowEl.style.background = 'rgba(192,57,43,0.15)';
    } else {
        msgEl.style.display = 'none';
        rowEl.style.background = '';
    }
}

function DMXCheckAllInputClashes() {
    DMXCheckInputClash(0);
    DMXCheckInputClash(1);
}

$('#device0, #enabled0').on('change', function() { DMXCheckInputClash(0); });
$('#device1, #enabled1').on('change', function() { DMXCheckInputClash(1); });
DMXCheckAllInputClashes();

// Triggers list editor: same GET-whole-array / edit locally / POST-whole-
// array-back pattern core's own "Other" channel-output page uses for
// co-other.json, via triggers.php (this plugin's own small JSON API,
// not a core endpoint) so any number of rows can be added or removed
// instead of a fixed set of slots.
//
// The command+args for each row is configured through fpp.js's own
// ShowCommandEditor() popup (the same widget "Run FPP Command" and
// Presets use) rather than a free-typed field - that gets every command's
// real per-argument form (dropdowns for enums, checkboxes for bools,
// range-checked numbers, live-populated lists for things like effect or
// playlist names) instead of a place to typo a comma-separated string.
// Each row keeps its full {command, args} object in jQuery .data('cmd'),
// not in the visible DOM, since only a short summary is displayed.

function dmxEscapeAttr(s) {
    return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
}

function DMXCommandSummary(cmd) {
    if (!cmd || !cmd.command) {
        return "<i>(not configured)</i>";
    }
    var s = dmxEscapeAttr(cmd.command);
    if (cmd.args && cmd.args.length) {
        s += " (" + cmd.args.map(dmxEscapeAttr).join(", ") + ")";
    }
    return s;
}

function DMXTriggerRowHtml(t, num) {
    t = t || {};
    var checked = t.enabled ? 'checked' : '';
    return "<tr>" +
        "<td>" + num + "</td>" +
        "<td><input type='checkbox' class='dmxT_enabled' " + checked + "></td>" +
        "<td><input type='text' size=6 maxlength=6 class='dmxT_start' value='" + (t.startChannel || 1) + "'></td>" +
        "<td><input type='text' size=6 maxlength=6 class='dmxT_end' value='" + (t.endChannel || 1) + "'></td>" +
        "<td><input type='text' size=4 maxlength=3 class='dmxT_threshold' value='" + (t.threshold || 1) + "'></td>" +
        "<td><span class='dmxT_cmdSummary'>" + DMXCommandSummary({ command: t.command, args: t.args }) + "</span>" +
            "&nbsp;<button type='button' class='buttons' onClick='DMXConfigureTriggerCommand(this);'>Configure</button></td>" +
        "<td><input type='text' size=6 maxlength=8 class='dmxT_cooldown' value='" + (t.cooldownMs != null ? t.cooldownMs : 1000) + "'></td>" +
        "<td><button type='button' class='buttons btn-outline-danger' onClick='$(this).closest(\"tr\").remove(); DMXRenumberTriggerRows();'><i class='fas fa-trash'></i></button></td>" +
        "</tr>";
}

function DMXRenumberTriggerRows() {
    $('#dmxTriggerEditBody > tr').each(function(i) {
        $(this).find('td:first').text(i + 1);
    });
}

function DMXAddTriggerRow() {
    var num = $('#dmxTriggerEditBody > tr').length + 1;
    var row = $(DMXTriggerRowHtml({}, num));
    row.data('cmd', { command: '', args: [] });
    $('#dmxTriggerEditBody').append(row);
}

function DMXConfigureTriggerCommand(btn) {
    var row = $(btn).closest('tr');
    var current = row.data('cmd') || { command: '', args: [] };
    ShowCommandEditor(row, current, 'DMXTriggerCommandSaved', '', {
        title: 'Configure Trigger Command',
        saveButton: 'Accept',
        cancelButton: 'Cancel',
        showPresetSelect: false
    });
}

// Global callback fpp.js's command editor popup calls on Save - see
// commandEditor.php's CommandEditorSave(), which does
// window[commandEditorCallback](commandEditorTarget, data).
function DMXTriggerCommandSaved(target, data) {
    var row = $(target);
    row.data('cmd', data);
    row.find('.dmxT_cmdSummary').html(DMXCommandSummary(data));
}

function DMXPopulateTriggerTable(data) {
    var trigs = (data && data.triggers) || [];
    $('#dmxTriggerEditBody').empty();
    for (var i = 0; i < trigs.length; i++) {
        var row = $(DMXTriggerRowHtml(trigs[i], i + 1));
        row.data('cmd', { command: trigs[i].command, args: trigs[i].args || [] });
        $('#dmxTriggerEditBody').append(row);
    }
}

function DMXGetTriggers() {
    $.getJSON('plugin.php?plugin=fpp-dmx-input&page=triggers.php&nopage=1', function(data) {
        DMXPopulateTriggerTable(data);
    });
}

function DMXSaveTriggers() {
    var triggers = [];
    var dataError = false;
    $('#dmxTriggerEditBody > tr').each(function() {
        var $row = $(this);
        var start = parseInt($row.find('.dmxT_start').val());
        var end = parseInt($row.find('.dmxT_end').val());
        if (isNaN(start) || start < 1 || start > 512 || isNaN(end) || end < start || end > 512) {
            DialogError("Save Triggers", "Invalid channel range on row " + ($row.index() + 1));
            dataError = true;
            return false;
        }
        var cmd = $row.data('cmd') || { command: '', args: [] };
        triggers.push({
            enabled: $row.find('.dmxT_enabled').is(':checked'),
            startChannel: start,
            endChannel: end,
            threshold: parseInt($row.find('.dmxT_threshold').val()) || 1,
            command: cmd.command || '',
            args: cmd.args || [],
            cooldownMs: parseInt($row.find('.dmxT_cooldown').val()) || 0
        });
    });
    if (dataError) {
        return;
    }

    $.ajax({
        url: 'plugin.php?plugin=fpp-dmx-input&page=triggers.php&nopage=1',
        type: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ triggers: triggers })
    }).done(function(data) {
        DMXPopulateTriggerTable(data);
        $.jGrowl("Triggers Saved", { themeState: 'success' });
        SetRestartFlag(1);
    }).fail(function() {
        DialogError("Save Triggers", "Save Failed");
    });
}

DMXGetTriggers();
</script>
