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
        $DMXDevices[] = $fileName;
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
Each row is one DMX port. Add as many as your board has usable serial
ports for. Nothing takes effect until you click Save, and (like everything
else on this page) an FPPD restart afterward.

<div class='fppTableWrapper' style="margin-top:10px;">
<div class='fppTableContents'>
<table id="dmxInputEditTable" class="fppSelectableRowTable" style="width:100%;">
<thead>
<tr class='tblheader'>
<th>#</th><th>On</th><th>Label</th><th>Device</th><th>Start Ch.</th>
<th>Channel Count</th><th>End Ch.</th><th>Expire (ms)</th><th></th>
</tr>
</thead>
<tbody id="dmxInputEditBody"></tbody>
</table>
</div>
</div>

<div class="form-actions" style="margin-top:10px;">
<button id="btnAddInput" class="buttons btn-outline-success" type="button" onClick="DMXAddInputRow();"><i class="fas fa-plus"></i> Add</button>
<input id="btnSaveInputs" class="buttons btn-success ml-1" type="button" value="Save" onClick="DMXSaveInputs();">
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
Watches a range of DMX channels. In <b>Edge</b> mode (the default), when any
channel in the range enters the Value Min-Max band from outside it (e.g. a
console fader or button moving into that range), the configured FPP
Command runs once - not repeatedly while held, and not more often than the
cooldown allows. For a simple "above X" trigger, leave Value Max at 255;
for a fader split into zones, add one trigger per zone with non-overlapping
Min/Max bands. In <b>Continuous</b> mode, the command instead runs on every
value change (still cooldown-limited) - use this to track a fader live
(e.g. into a volume/brightness command) rather than fire once: put the
literal text <code>$VALUE</code> in whichever argument should receive the
current channel value when configuring the command. Click "Configure" to
pick the command and its arguments using the same editor the "Run FPP
Command" button (bottom of every page) uses. Add as many triggers as you
need; nothing takes effect until you click Save, and (like everything else
on this page) an FPPD restart afterward.

<div class='fppTableWrapper' style="margin-top:10px;">
<div class='fppTableContents'>
<table id="dmxTriggerEditTable" class="fppSelectableRowTable" style="width:100%;">
<thead>
<tr class='tblheader'>
<th>#</th><th>On</th><th>Start Ch.</th><th>End Ch.</th><th>Value Min</th><th>Value Max</th>
<th>Continuous</th><th>Command</th><th>Cooldown (ms)</th><th></th>
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
<legend>Backup / Restore Configuration</legend>
Download the whole input and trigger setup as one file, or restore it from
a previous download - useful for copying a working setup to another board
instead of re-entering it by hand. This overwrites whatever's currently
configured here (not core FPP settings) and still needs a Save/restart-style
reload of this page plus an FPPD restart to take effect.

<div class="form-actions" style="margin-top:10px;">
<a id="btnExportConfig" class="buttons" href="plugin.php?plugin=fpp-dmx-input&page=export.php&nopage=1">
<i class="fas fa-download"></i> Export Config</a>
<input type="file" id="dmxImportFile" accept="application/json" style="display:none;" onChange="DMXImportConfigFile(this);">
<button class="buttons ml-1" type="button" onClick="document.getElementById('dmxImportFile').click();">
<i class="fas fa-upload"></i> Import Config</button>
</div>
</fieldset>

</div>

<script>
// ============================================================
// Inputs list editor - same GET-whole-array / edit locally /
// POST-whole-array-back pattern as Triggers below, via inputs.php.
// ============================================================

var dmxDevices = <?= json_encode($DMXDevices) ?>;

// Devices with an enabled core output right now, embedded once from PHP
// at page load (see the co-other.json read near the top of this file).
// Not re-fetched on every check - Channel Outputs -> Other is a separate
// page, so this can only go stale by leaving this page and changing it
// there, which means reloading this page anyway.
var dmxOutputDevices = <?= json_encode(array_keys($DMXOutputDevices)) ?>;

function dmxEscapeAttr(s) {
    return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
}

function DMXDeviceOptionsHtml(selected) {
    var opts = '';
    for (var i = 0; i < dmxDevices.length; i++) {
        var d = dmxDevices[i];
        opts += "<option value='" + d + "'" + (d === selected ? " selected" : "") + ">" + d + "</option>";
    }
    return opts;
}

function DMXInputRowHtml(inp, num) {
    inp = inp || {};
    var checked = inp.enabled ? 'checked' : '';
    var device = inp.device || (dmxDevices[0] || 'ttyS1');
    var start = inp.startChannel || 1;
    var count = inp.channelCount || 512;
    return "<tr>" +
        "<td>" + num + "</td>" +
        "<td><input type='checkbox' class='dmxI_enabled' " + checked + " onChange='DMXCheckAllInputWarnings();'></td>" +
        "<td><input type='text' size=10 maxlength=32 class='dmxI_label' value='" + dmxEscapeAttr(inp.label || ('DMX' + num)) + "'></td>" +
        "<td><select class='dmxI_device' onChange='DMXCheckAllInputWarnings();'>" + DMXDeviceOptionsHtml(device) + "</select></td>" +
        "<td><input type='text' size=6 maxlength=6 class='dmxI_start' onChange='DMXCheckAllInputWarnings();' value='" + start + "'></td>" +
        "<td><input type='text' size=6 maxlength=6 class='dmxI_count' onChange='DMXCheckAllInputWarnings();' value='" + count + "'></td>" +
        "<td><span class='dmxI_end'>" + (start + count - 1) + "</span></td>" +
        "<td><input type='text' size=6 maxlength=8 class='dmxI_expire' value='" + (inp.expireMS != null ? inp.expireMS : 5000) + "'></td>" +
        "<td><button type='button' class='buttons btn-outline-danger' onClick='var r=$(this).closest(\"tr\"); r.next(\".dmxI_warnRow\").remove(); r.remove(); DMXRenumberInputRows(); DMXCheckAllInputWarnings();'><i class='fas fa-trash'></i></button></td>" +
        "</tr>" +
        "<tr class='dmxI_warnRow' style='display:none;'><td></td><td colspan='8' style='color:#c0392b;font-weight:bold;padding-top:0;'></td></tr>";
}

function DMXRenumberInputRows() {
    $('#dmxInputEditBody > tr:not(.dmxI_warnRow)').each(function(i) {
        $(this).find('td:first').text(i + 1);
    });
}

function DMXAddInputRow() {
    var num = $('#dmxInputEditBody > tr:not(.dmxI_warnRow)').length + 1;
    var row = $(DMXInputRowHtml({}, num));
    $('#dmxInputEditBody').append(row);
    DMXCheckAllInputWarnings();
}

// Checks both kinds of channel conflict across the WHOLE table at once
// (has to - overlap is inherently pairwise, not a single-row property)
// and re-runs on every relevant field change so it tracks what's
// currently in the form, not just what was last saved. A purely
// load-time/server-side check (the only kind that existed before) goes
// stale the instant someone edits a field, with nothing to refresh it
// short of reloading the page.
function DMXCheckAllInputWarnings() {
    var rows = $('#dmxInputEditBody > tr:not(.dmxI_warnRow)');
    var info = [];
    rows.each(function() {
        var $row = $(this);
        var device = $row.find('.dmxI_device').val();
        var enabled = $row.find('.dmxI_enabled').is(':checked');
        var start = parseInt($row.find('.dmxI_start').val());
        var count = parseInt($row.find('.dmxI_count').val());
        info.push({
            row: $row,
            warnRow: $row.next('.dmxI_warnRow'),
            device: device,
            enabled: enabled,
            start: isNaN(start) ? 1 : start,
            end: isNaN(start) || isNaN(count) ? 1 : (start + count - 1)
        });
    });

    info.forEach(function(a) {
        // End Ch. isn't user-editable - it's always startChannel + count -
        // 1, so it's just displayed, recomputed here on every check rather
        // than kept in its own onChange handler.
        a.row.find('.dmxI_end').text(a.end);
        var msgs = [];
        if (a.enabled && dmxOutputDevices.indexOf(a.device) !== -1) {
            msgs.push('&#9888; /dev/' + dmxEscapeAttr(a.device) + ' is also an enabled output on ' +
                '<a href="channeloutputs.php#tab-other">Channel Outputs &rarr; Other</a> - this input ' +
                'will be refused, not opened.');
        }
        if (a.enabled) {
            info.forEach(function(b) {
                if (a === b || !b.enabled) {
                    return;
                }
                if (a.start <= b.end && b.start <= a.end) {
                    msgs.push('&#9888; Channels ' + a.start + '-' + a.end + ' overlap another enabled input\'s ' +
                        b.start + '-' + b.end + ' - both will write the same FPP channels, and whichever ' +
                        'input\'s data arrives last on a given frame wins.');
                }
            });
        }
        if (msgs.length) {
            a.warnRow.find('td:last').html(msgs.join('<br>'));
            a.warnRow.show();
            a.row.css('background', 'rgba(192,57,43,0.15)');
        } else {
            a.warnRow.hide();
            a.row.css('background', '');
        }
    });
}

function DMXPopulateInputTable(data) {
    var inputs = (data && data.inputs) || [];
    $('#dmxInputEditBody').empty();
    for (var i = 0; i < inputs.length; i++) {
        $('#dmxInputEditBody').append(DMXInputRowHtml(inputs[i], i + 1));
    }
    DMXCheckAllInputWarnings();
}

function DMXGetInputs() {
    $.getJSON('plugin.php?plugin=fpp-dmx-input&page=inputs.php&nopage=1', function(data) {
        DMXPopulateInputTable(data);
    });
}

function DMXSaveInputs() {
    var inputs = [];
    var dataError = false;
    $('#dmxInputEditBody > tr:not(.dmxI_warnRow)').each(function(rowIdx) {
        var $row = $(this);
        var start = parseInt($row.find('.dmxI_start').val());
        var count = parseInt($row.find('.dmxI_count').val());
        var expire = parseInt($row.find('.dmxI_expire').val());
        // start is a position in FPP's own channel space (remappable
        // anywhere multiple inputs need to land, not bounded by DMX-512's
        // own 512-channel universe size) - only count, the actual number
        // of channels read off one DMX universe, is capped at 512.
        if (isNaN(start) || start < 1 || isNaN(count) || count < 1 || count > 512) {
            DialogError("Save Inputs", "Invalid channel range on row " + (rowIdx + 1));
            dataError = true;
            return false;
        }
        inputs.push({
            label: $row.find('.dmxI_label').val() || 'DMX',
            device: $row.find('.dmxI_device').val(),
            enabled: $row.find('.dmxI_enabled').is(':checked'),
            startChannel: start,
            channelCount: count,
            expireMS: isNaN(expire) ? 5000 : expire
        });
    });
    if (dataError) {
        return;
    }

    $.ajax({
        url: 'plugin.php?plugin=fpp-dmx-input&page=inputs.php&nopage=1',
        type: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ inputs: inputs })
    }).done(function(data) {
        DMXPopulateInputTable(data);
        $.jGrowl("Inputs Saved", { themeState: 'success' });
        SetRestartFlag(1);
    }).fail(function() {
        DialogError("Save Inputs", "Save Failed");
    });
}

DMXGetInputs();

// ============================================================
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
// ============================================================

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
    var contChecked = t.continuous ? 'checked' : '';
    return "<tr>" +
        "<td>" + num + "</td>" +
        "<td><input type='checkbox' class='dmxT_enabled' " + checked + " onChange='DMXCheckAllTriggerWarnings();'></td>" +
        "<td><input type='text' size=6 maxlength=6 class='dmxT_start' onChange='DMXCheckAllTriggerWarnings();' value='" + (t.startChannel || 1) + "'></td>" +
        "<td><input type='text' size=6 maxlength=6 class='dmxT_end' onChange='DMXCheckAllTriggerWarnings();' value='" + (t.endChannel || 1) + "'></td>" +
        "<td><input type='text' size=4 maxlength=3 class='dmxT_valueMin' onChange='DMXCheckAllTriggerWarnings();' value='" + (t.valueMin != null ? t.valueMin : 1) + "'></td>" +
        "<td><input type='text' size=4 maxlength=3 class='dmxT_valueMax' onChange='DMXCheckAllTriggerWarnings();' value='" + (t.valueMax != null ? t.valueMax : 255) + "'></td>" +
        "<td><input type='checkbox' class='dmxT_continuous' onChange='DMXCheckAllTriggerWarnings();' " + contChecked + "></td>" +
        "<td><span class='dmxT_cmdSummary'>" + DMXCommandSummary({ command: t.command, args: t.args }) + "</span>" +
            "&nbsp;<button type='button' class='buttons' onClick='DMXConfigureTriggerCommand(this);'>Configure</button></td>" +
        "<td><input type='text' size=6 maxlength=8 class='dmxT_cooldown' value='" + (t.cooldownMs != null ? t.cooldownMs : 1000) + "'></td>" +
        "<td><button type='button' class='buttons btn-outline-danger' onClick='var r=$(this).closest(\"tr\"); r.next(\".dmxT_warnRow\").remove(); r.remove(); DMXRenumberTriggerRows(); DMXCheckAllTriggerWarnings();'><i class='fas fa-trash'></i></button></td>" +
        "</tr>" +
        "<tr class='dmxT_warnRow' style='display:none;'><td></td><td colspan='9' style='color:#c0392b;font-weight:bold;padding-top:0;'></td></tr>";
}

function DMXRenumberTriggerRows() {
    $('#dmxTriggerEditBody > tr:not(.dmxT_warnRow)').each(function(i) {
        $(this).find('td:first').text(i + 1);
    });
}

function DMXAddTriggerRow() {
    var num = $('#dmxTriggerEditBody > tr:not(.dmxT_warnRow)').length + 1;
    var row = $(DMXTriggerRowHtml({}, num));
    row.data('cmd', { command: '', args: [] });
    $('#dmxTriggerEditBody').append(row);
}

// Mirrors src/FPPDMXInput.cpp's checkTriggerOverlaps(): two enabled
// triggers whose channel ranges AND value bands can both match (or where
// either is continuous, which ignores its value band and matches any
// change) get flagged - not an error, since watching the same channel
// for different purposes is sometimes intentional, but a real warning:
// this exact situation (a Start Playlist trigger and a Stop Now trigger
// both covering the same channel) is what made a working setup look like
// "the command doesn't work" when both were actually firing correctly.
function DMXCheckAllTriggerWarnings() {
    var rows = $('#dmxTriggerEditBody > tr:not(.dmxT_warnRow)');
    var info = [];
    rows.each(function() {
        var $row = $(this);
        var cmd = $row.data('cmd') || {};
        info.push({
            row: $row,
            warnRow: $row.next('.dmxT_warnRow'),
            enabled: $row.find('.dmxT_enabled').is(':checked'),
            start: parseInt($row.find('.dmxT_start').val()) || 1,
            end: parseInt($row.find('.dmxT_end').val()) || 1,
            valueMin: parseInt($row.find('.dmxT_valueMin').val()),
            valueMax: parseInt($row.find('.dmxT_valueMax').val()),
            continuous: $row.find('.dmxT_continuous').is(':checked'),
            command: cmd.command || '(not configured)'
        });
    });

    info.forEach(function(a) {
        if (!a.enabled) {
            a.warnRow.hide();
            a.row.css('background', '');
            return;
        }
        var msgs = [];
        info.forEach(function(b) {
            if (a === b || !b.enabled) {
                return;
            }
            var chOverlap = a.start <= b.end && b.start <= a.end;
            var valOverlap = a.valueMin <= b.valueMax && b.valueMin <= a.valueMax;
            if (chOverlap && (valOverlap || a.continuous || b.continuous)) {
                msgs.push('&#9888; Can also fire together with "' + dmxEscapeAttr(b.command) + '" (ch ' +
                    b.start + '-' + b.end + ') on the same channel/value - if one command undoes the ' +
                    'other, that looks like neither is working.');
            }
        });
        if (msgs.length) {
            a.warnRow.find('td:last').html(msgs.join('<br>'));
            a.warnRow.show();
            a.row.css('background', 'rgba(192,57,43,0.15)');
        } else {
            a.warnRow.hide();
            a.row.css('background', '');
        }
    });
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
    DMXCheckAllTriggerWarnings();
}

function DMXPopulateTriggerTable(data) {
    var trigs = (data && data.triggers) || [];
    $('#dmxTriggerEditBody').empty();
    for (var i = 0; i < trigs.length; i++) {
        var row = $(DMXTriggerRowHtml(trigs[i], i + 1));
        row.data('cmd', { command: trigs[i].command, args: trigs[i].args || [] });
        $('#dmxTriggerEditBody').append(row);
    }
    DMXCheckAllTriggerWarnings();
}

function DMXGetTriggers() {
    $.getJSON('plugin.php?plugin=fpp-dmx-input&page=triggers.php&nopage=1', function(data) {
        DMXPopulateTriggerTable(data);
    });
}

function DMXSaveTriggers() {
    var triggers = [];
    var dataError = false;
    $('#dmxTriggerEditBody > tr:not(.dmxT_warnRow)').each(function(rowIdx) {
        var $row = $(this);
        var start = parseInt($row.find('.dmxT_start').val());
        var end = parseInt($row.find('.dmxT_end').val());
        // Not capped at 512 - a trigger watches a position in FPP's own
        // channel space, which can be wherever a remapped input's
        // startChannel put it (see Inputs above), not just within one raw
        // DMX universe.
        if (isNaN(start) || start < 1 || isNaN(end) || end < start) {
            DialogError("Save Triggers", "Invalid channel range on row " + (rowIdx + 1));
            dataError = true;
            return false;
        }
        var valueMin = parseInt($row.find('.dmxT_valueMin').val());
        var valueMax = parseInt($row.find('.dmxT_valueMax').val());
        if (isNaN(valueMin) || valueMin < 0 || valueMin > 255 || isNaN(valueMax) || valueMax < valueMin || valueMax > 255) {
            DialogError("Save Triggers", "Invalid value range on row " + (rowIdx + 1) + " (must be 0-255, Min ≤ Max)");
            dataError = true;
            return false;
        }
        var cmd = $row.data('cmd') || { command: '', args: [] };
        triggers.push({
            enabled: $row.find('.dmxT_enabled').is(':checked'),
            startChannel: start,
            endChannel: end,
            valueMin: valueMin,
            valueMax: valueMax,
            continuous: $row.find('.dmxT_continuous').is(':checked'),
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

// ============================================================
// Backup / restore: export.php streams a Content-Disposition download
// (plain link, no JS needed there); import reads the chosen file
// client-side and POSTs its JSON to import.php, which re-validates and
// re-sanitizes it exactly like inputs.php/triggers.php's own POST
// handlers rather than trusting the uploaded file verbatim.
// ============================================================

function DMXImportConfigFile(fileInput) {
    var file = fileInput.files[0];
    if (!file) {
        return;
    }
    var reader = new FileReader();
    reader.onload = function() {
        var parsed;
        try {
            parsed = JSON.parse(reader.result);
        } catch (e) {
            DialogError("Import Config", "That file isn't valid JSON");
            fileInput.value = '';
            return;
        }
        $.ajax({
            url: 'plugin.php?plugin=fpp-dmx-input&page=import.php&nopage=1',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(parsed)
        }).done(function(data) {
            DMXPopulateInputTable({ inputs: data.inputs });
            DMXPopulateTriggerTable({ triggers: data.triggers });
            $.jGrowl("Config Imported", { themeState: 'success' });
            SetRestartFlag(1);
        }).fail(function(xhr) {
            var msg = 'Import Failed';
            try { msg = JSON.parse(xhr.responseText).error || msg; } catch (e) {}
            DialogError("Import Config", msg);
        }).always(function() {
            fileInput.value = '';
        });
    };
    reader.readAsText(file);
}
</script>
