<?
// Accepts a config file previously produced by export.php and writes both
// the inputs and triggers config files - same sanitization as inputs.php/
// triggers.php's own POST handlers (not just trusting the uploaded file
// verbatim), since it could be hand-edited or from an older export.
header('Content-Type: application/json');

$inputsFile = "/home/fpp/media/config/plugin.fpp-dmx-input-inputs.json";
$triggersFile = "/home/fpp/media/config/plugin.fpp-dmx-input-triggers.json";

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body) || !isset($body['fpp-dmx-input-config'])) {
    http_response_code(400);
    echo json_encode(array("error" => "not a fpp-dmx-input config export"));
    return;
}

$cleanInputs = array();
if (isset($body['inputs']) && is_array($body['inputs'])) {
    foreach ($body['inputs'] as $i) {
        $cleanInputs[] = array(
            "label" => strval($i['label'] ?? "DMX"),
            "device" => strval($i['device'] ?? "ttyS1"),
            "enabled" => !empty($i['enabled']),
            "startChannel" => max(1, min(512, intval($i['startChannel'] ?? 1))),
            "channelCount" => max(1, min(512, intval($i['channelCount'] ?? 512))),
            "expireMS" => max(1, intval($i['expireMS'] ?? 5000)),
        );
    }
}

$cleanTriggers = array();
if (isset($body['triggers']) && is_array($body['triggers'])) {
    foreach ($body['triggers'] as $t) {
        $start = max(1, intval($t['startChannel'] ?? 1));
        $end = max($start, intval($t['endChannel'] ?? $start));
        $args = array();
        if (isset($t['args']) && is_array($t['args'])) {
            foreach ($t['args'] as $a) {
                $args[] = strval($a);
            }
        }
        $valueMin = min(255, max(0, intval($t['valueMin'] ?? 1)));
        $valueMax = min(255, max($valueMin, intval($t['valueMax'] ?? 255)));
        $cleanTriggers[] = array(
            "enabled" => !empty($t['enabled']),
            "startChannel" => $start,
            "endChannel" => $end,
            "valueMin" => $valueMin,
            "valueMax" => $valueMax,
            "continuous" => !empty($t['continuous']),
            "command" => strval($t['command'] ?? ""),
            "args" => $args,
            "cooldownMs" => max(0, intval($t['cooldownMs'] ?? 1000)),
        );
    }
}

$okInputs = file_put_contents($inputsFile, json_encode(array("inputs" => $cleanInputs), JSON_PRETTY_PRINT));
$okTriggers = file_put_contents($triggersFile, json_encode(array("triggers" => $cleanTriggers), JSON_PRETTY_PRINT));

if ($okInputs === false || $okTriggers === false) {
    http_response_code(500);
    echo json_encode(array("error" => "could not write config file(s)"));
    return;
}

echo json_encode(array(
    "ok" => true,
    "inputs" => $cleanInputs,
    "triggers" => $cleanTriggers,
));
