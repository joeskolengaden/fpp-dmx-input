<?
// GET/POST JSON API for the trigger-rules list, in the same spirit as
// core's api/channel/output/co-other used by the "Other" channel-output
// page: GET returns the current array, POST replaces it wholesale. The
// C++ plugin only reads this file at startup (see loadConfig() in
// src/FPPDMXInput.cpp), so a save here still needs an FPPD restart to
// take effect - same as every other setting on this plugin's pages.
header('Content-Type: application/json');

$configFile = "/home/fpp/media/config/plugin.fpp-dmx-input-triggers.json";

function dmxLoadTriggers($configFile) {
    if (!file_exists($configFile)) {
        return array("triggers" => array());
    }
    $data = json_decode(file_get_contents($configFile), true);
    if (!is_array($data) || !isset($data['triggers'])) {
        return array("triggers" => array());
    }
    return $data;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body) || !isset($body['triggers']) || !is_array($body['triggers'])) {
        http_response_code(400);
        echo json_encode(array("error" => "expected {\"triggers\": [...]}"));
        return;
    }

    $clean = array();
    foreach ($body['triggers'] as $t) {
        $start = max(1, intval($t['startChannel'] ?? 1));
        $end = max($start, intval($t['endChannel'] ?? $start));
        // args is a real JSON array (matching what fpp.js's
        // CommandToJSON()/ShowCommandEditor() widget produces and what
        // CommandManager::run()'s std::vector<std::string> args expects) -
        // not a comma-joined string, so an argument value containing a
        // literal comma (e.g. a playlist name) can't corrupt another arg.
        $args = array();
        if (isset($t['args']) && is_array($t['args'])) {
            foreach ($t['args'] as $a) {
                $args[] = strval($a);
            }
        }
        $valueMin = min(255, max(0, intval($t['valueMin'] ?? 1)));
        $valueMax = min(255, max($valueMin, intval($t['valueMax'] ?? 255)));
        $clean[] = array(
            "enabled" => !empty($t['enabled']),
            "startChannel" => $start,
            "endChannel" => $end,
            "valueMin" => $valueMin,
            "valueMax" => $valueMax,
            "command" => strval($t['command'] ?? ""),
            "args" => $args,
            "cooldownMs" => max(0, intval($t['cooldownMs'] ?? 1000)),
        );
    }

    $out = array("triggers" => $clean);
    if (file_put_contents($configFile, json_encode($out, JSON_PRETTY_PRINT)) === false) {
        http_response_code(500);
        echo json_encode(array("error" => "could not write config file"));
        return;
    }
    echo json_encode($out);
    return;
}

echo json_encode(dmxLoadTriggers($configFile));
