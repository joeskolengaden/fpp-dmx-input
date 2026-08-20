<?
// Downloads the whole plugin config (inputs + triggers) as one JSON file,
// for backing up or copying a working setup to another board rather than
// re-entering it by hand through the UI.
$inputsFile = "/home/fpp/media/config/plugin.fpp-dmx-input-inputs.json";
$triggersFile = "/home/fpp/media/config/plugin.fpp-dmx-input-triggers.json";

$inputs = array();
if (file_exists($inputsFile)) {
    $d = json_decode(file_get_contents($inputsFile), true);
    if (is_array($d) && isset($d['inputs'])) {
        $inputs = $d['inputs'];
    }
}

$triggers = array();
if (file_exists($triggersFile)) {
    $d = json_decode(file_get_contents($triggersFile), true);
    if (is_array($d) && isset($d['triggers'])) {
        $triggers = $d['triggers'];
    }
}

$out = array(
    "fpp-dmx-input-config" => 1,
    "inputs" => $inputs,
    "triggers" => $triggers,
);

header('Content-Type: application/json');
header('Content-Disposition: attachment; filename="fpp-dmx-input-config.json"');
echo json_encode($out, JSON_PRETTY_PRINT);
