<?
// GET/POST JSON API for the input-port list, same pattern as triggers.php
// (which mirrors core's api/channel/output/co-other): GET returns the
// current array, POST replaces it wholesale. The C++ plugin only reads
// this file at startup, so a save here still needs an FPPD restart.
header('Content-Type: application/json');

$configFile = "/home/fpp/media/config/plugin.fpp-dmx-input-inputs.json";

// Matches src/FPPDMXInput.cpp's loadConfig() fallback exactly (same two
// disabled slots) so a fresh install's UI reflects what the C++ side is
// actually running with, rather than showing an empty table while the
// plugin itself has two ports ready to enable.
function dmxDefaultInputs() {
    return array("inputs" => array(
        array("label" => "DMX1", "device" => "ttyS1", "enabled" => false,
              "startChannel" => 1, "channelCount" => 512, "expireMS" => 5000),
        array("label" => "DMX2", "device" => "ttyS2", "enabled" => false,
              "startChannel" => 1, "channelCount" => 512, "expireMS" => 5000),
    ));
}

function dmxLoadInputs($configFile) {
    if (!file_exists($configFile)) {
        return dmxDefaultInputs();
    }
    $data = json_decode(file_get_contents($configFile), true);
    if (!is_array($data) || !isset($data['inputs']) || count($data['inputs']) === 0) {
        return dmxDefaultInputs();
    }
    return $data;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body) || !isset($body['inputs']) || !is_array($body['inputs'])) {
        http_response_code(400);
        echo json_encode(array("error" => "expected {\"inputs\": [...]}"));
        return;
    }

    $clean = array();
    foreach ($body['inputs'] as $i) {
        $clean[] = array(
            "label" => strval($i['label'] ?? "DMX"),
            "device" => strval($i['device'] ?? "ttyS1"),
            "enabled" => !empty($i['enabled']),
            // startChannel is a position in FPP's own channel space, not a
            // DMX-universe offset - with multiple inputs, each one's data
            // gets remapped to wherever it needs to land (e.g. a second
            // universe starting at channel 513, or channel 5000 in a big
            // show), so it's only floored at 1, not capped at 512.
            // channelCount is capped, since that's a real DMX-512 universe.
            "startChannel" => max(1, intval($i['startChannel'] ?? 1)),
            "channelCount" => max(1, min(512, intval($i['channelCount'] ?? 512))),
            "expireMS" => max(1, intval($i['expireMS'] ?? 5000)),
        );
    }

    $out = array("inputs" => $clean);
    if (file_put_contents($configFile, json_encode($out, JSON_PRETTY_PRINT)) === false) {
        http_response_code(500);
        echo json_encode(array("error" => "could not write config file"));
        return;
    }
    echo json_encode($out);
    return;
}

echo json_encode(dmxLoadInputs($configFile));
