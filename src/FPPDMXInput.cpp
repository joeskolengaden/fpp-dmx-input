/*
 *   fpp-dmx-input - DMX-512 receive plugin for FPP 5.x
 *
 *   Reads DMX universes from one or more UARTs wired as RS-485 receivers
 *   (RO -> BBB Rx pin, DE/RE jumpered to GND) and feeds them into FPP's
 *   live channel data via Sequence::SetBridgeData() - the same mechanism
 *   the built-in E1.31/ArtNet/DDP bridges use. Written for FPP 5.4, which
 *   has no native "Channel Input" feature (that shipped later, in 8.2),
 *   but whose plugin hooks (addControlCallbacks, Sequence::SetBridgeData,
 *   registerApis) have been stable since 2019-2020.
 *
 *   Feature set mirrors FPP's own native DMX-input (added in 8.2's
 *   e131bridge.cpp): multiple simultaneous inputs, per-input enable,
 *   per-input start channel/count, expiring bridge data, and
 *   packets/bytes/error stats - plus a few things native doesn't do:
 *   overlap detection across configured inputs, edge-triggered
 *   signal-acquired/signal-lost logging, a consecutive-framing-error
 *   warning aimed at catching wiring/DE-RE faults, and a JSON status API.
 *
 *   Config: /home/fpp/media/config/plugin.fpp-dmx-input (standard FPP
 *   plugin ini file, key=value, auto-loaded by FPPPlugin::reloadSettings()
 *   into the inherited `settings` map). Two fixed input slots, matching
 *   the two physical DMX ports on these boards, indexed 0/1:
 *     label0/label1        - display name (default "DMX1"/"DMX2")
 *     device0/device1      - tty device under /dev, no path
 *     enabled0/enabled1    - "1"/"0", default "0" (off - see note below)
 *     startChannel0/1      - first channel (1-based), default 1
 *     channelCount0/1      - channel count, default 512
 *     expireMS0/1          - bridge-data expiry, default 5000
 *   Both inputs default disabled: on these boards a UART used for a DMX
 *   *output* (co-other.json) shouldn't simultaneously be opened here for
 *   input - enabling an input requires disabling that port's output
 *   config first, so it must be a deliberate opt-in, not a surprise
 *   default. addControlCallbacks() actively enforces this at startup
 *   (refuses to open a device that's also an enabled core output),
 *   rather than relying on SerialOpen()'s TIOCEXCL: since fppd runs as
 *   root, TIOCEXCL's exclusivity check is bypassed (CAP_SYS_ADMIN), so
 *   both sides would otherwise get a real fd on the same UART with
 *   neither side erroring - confirmed by direct test, not assumption.
 *   On a half-duplex RS-485 port that's not just a software footgun:
 *   the output side continuously drives DE/RE to transmit, so a
 *   DE/RE-jumpered-to-GND input can't get a genuine external signal
 *   through (the transceiver mostly just echoes our own TX back on RO),
 *   and the DE/RE line itself ends up contested between the two.
 *

 *   Triggers: any number of rules (soft cap 64), each watching a channel
 *   range and running an FPP Command (via the same CommandManager::run()
 *   the "Run FPP Command" page and Presets use) when any channel in that
 *   range enters [valueMin, valueMax] from outside that band - edge-
 *   triggered so a fader held up doesn't refire every frame, debounced by
 *   a per-rule cooldown. A single "above X" trigger is just valueMax=255;
 *   arbitrary bands (e.g. splitting one fader into several zones, each
 *   running a different command) are the general case this supports.
 *   Stored as a JSON array (not fixed ini keys, since the count is
 *   user-defined) at /home/fpp/media/config/plugin.fpp-dmx-input-
 *   triggers.json, edited through content.php's add/remove-row table -
 *   the same GET-whole-array/edit-locally/POST-whole-array-back pattern
 *   core's own "Other" channel-output page (co-other.json) uses. `args`
 *   is a real JSON array (not a comma-joined string), matching what
 *   CommandManager::run()'s std::vector<std::string> args expects and
 *   what fpp.js's CommandToJSON()/ShowCommandEditor() widget produces -
 *   the same typed, per-argument popup editor "Run FPP Command" uses,
 *   which content.php's "Configure" button opens directly, rather than a
 *   free-typed field a user could easily get wrong:
 *     { "triggers": [ { "enabled": true, "startChannel": 1,
 *         "endChannel": 1, "valueMin": 1, "valueMax": 255,
 *         "command": "Start Playlist",
 *         "args": ["MyShow", "false", "false", "0"],
 *         "cooldownMs": 1000 }, ... ] }
 *
 *   Status API: GET /DMXInput/status -> {"inputs":[...], "triggers":[...]}.
 *   inputs: label, device, enabled, opened, startChannel, channelCount,
 *   packetsReceived, bytesReceived, errorPackets, signalOk, lastFrameAgeMS,
 *   values (live per-channel byte values, for the status page's meters).
 *   triggers: enabled, startChannel, endChannel, valueMin, valueMax,
 *   command, fireCount.
 */

#include <algorithm>
#include <cstdint>
#include <cstring>
#include <memory>
#include <sstream>
#include <string>
#include <termios.h>
#include <unistd.h>
#include <deque>

#include <httpserver.hpp>
#include <jsoncpp/json/json.h>

#include "Plugin.h"
#include "Sequence.h"
#include "common.h"
#include "settings.h"
#include "log.h"
#include "channeloutput/serialutil.h"
#include "commands/Commands.h"
#include <atomic>
#include <map>
#include <mutex>
#include <set>
#include <vector>

// FPP's own httpAPI.cpp starts libhttpserver with max_threads(5) in
// non-blocking mode, so render_GET() below runs on a real worker thread,
// concurrently with handleRead() on fppd's main epoll thread. The fields
// both sides touch are atomic so the status page can't read a torn value
// (a real risk for the uint64_t counters on 32-bit ARM, where a 64-bit
// load/store is two 32-bit ops). label/device/startChannel/channelCount/
// enabled are set once at construction and never touched again, so they
// don't need to be atomic.
struct DMXInputPort {
    std::string label = "DMX";
    std::string device = "ttyO4";
    bool enabled = true;
    int startChannel = 1;
    int channelCount = 512;
    int expireMS = 5000;

    std::atomic<int> fd { -1 };
    std::atomic<bool> outputClash { false }; // set once in addControlCallbacks()
    int rxIndex = 0;     // handleRead()-only, never read cross-thread
    uint64_t lastByteMS = 0; // handleRead()-only, never read cross-thread

    std::atomic<uint64_t> packetsReceived { 0 };
    std::atomic<uint64_t> bytesReceived { 0 };
    std::atomic<uint64_t> errorPackets { 0 };
    std::atomic<uint64_t> lastFrameMS { 0 };
    int consecutiveErrors = 0; // handleRead()-only, never read cross-thread
    std::atomic<bool> signalOk { false };

    // Latest known value of each configured channel, for the status page's
    // live meters - a plain mutex rather than per-byte atomics, since this
    // is read as a whole array (not element-by-element) by an infrequent
    // (2s-polled) HTTP request, so lock contention against handleRead()'s
    // much more frequent writes is negligible; per-byte atomics would only
    // add overhead here without a real concurrency benefit. Sized once in
    // loadConfig() to channelCount.
    std::mutex liveValuesMutex;
    std::vector<uint8_t> liveValues;
};

// A user-configured rule: watch a channel range, and when any channel in
// it enters [valueMin, valueMax] from outside that band (edge-triggered,
// not level-triggered - a fader held up doesn't refire every frame), run
// an FPP Command via the same CommandManager::run() the "Run FPP Command"
// UI page and Presets use. `cooldownMs` debounces a single physical
// fader/button so one push fires once, not once per frame while held.
// A single-value "threshold" trigger is just this with valueMax=255 - a
// fader's whole "above X" range is one band, same as before this was
// generalized to arbitrary bands (e.g. splitting one fader into several
// zones, each running a different command).
struct TriggerRule {
    bool enabled = false;
    int startChannel = 1;
    int endChannel = 1;
    int valueMin = 1;
    int valueMax = 255;
    std::string command;
    std::vector<std::string> args;
    int cooldownMs = 1000;

    // Edge mode (the default) fires once on entering [valueMin,valueMax].
    // Continuous mode instead fires on every value CHANGE (still governed
    // by cooldownMs, so it's not literally once-per-byte), substituting
    // any "$VALUE" placeholder in args with the current channel value
    // first - for tracking a fader live (e.g. into a volume/brightness
    // command) rather than a one-shot action.
    bool continuous = false;

    // Indexed by (channel - startChannel), sized once in loadConfig() to
    // exactly the configured range - a flat array beats a map here since
    // the range is small and bounded, avoiding a tree lookup (and a
    // separate find() + operator[] pair, i.e. two traversals) per channel
    // per trigger on every incoming DMX read. handleRead()-only, never
    // read cross-thread.
    std::vector<uint8_t> lastValues;
    uint64_t lastFireMS = 0; // handleRead()-only, never read cross-thread
    std::atomic<uint64_t> fireCount { 0 };
};

class FPPDMXInputPlugin : public FPPPlugin, public httpserver::http_resource {
public:
    FPPDMXInputPlugin() :
        FPPPlugin("fpp-dmx-input") {
        loadConfig();
    }

    virtual ~FPPDMXInputPlugin() {
        for (auto& p : ports) {
            if (p.fd >= 0) {
                SerialClose(p.fd);
            }
        }
    }

    virtual void addControlCallbacks(std::map<int, std::function<bool(int)>>& callbacks) override {
        // Checked once here, not cached from loadConfig() time, so it
        // reflects the actual co-other.json on disk at the point we're
        // about to open devices - matches the fact that neither this nor
        // core's own output config live-reloads without an FPPD restart
        // anyway, so both are read fresh on the same restart.
        std::set<std::string> outputDevices = getEnabledOutputDevices();

        for (auto& p : ports) {
            if (!p.enabled) {
                continue;
            }

            // A UART already claimed by a core channel output (of ANY
            // type that binds to a /dev device - DMX-Open, GenericSerial,
            // Renard, etc. - the specific type doesn't matter, only that
            // it shares the same device node) can't safely also be used
            // here for input. Root bypasses SerialOpen()'s own TIOCEXCL
            // exclusivity check (fppd runs as root), so both sides would
            // silently get a real fd on the same UART with no error from
            // either - confirmed by direct test, not theory: fppd stayed
            // up, neither side logged a failure, and /proc/<pid>/fd showed
            // two live descriptors on one device. On an RS-485 port that's
            // not just a software inconvenience: the output side is
            // continuously driving DE/RE to transmit, so genuine external
            // input can't get through (most transceivers just echo our
            // own TX back on RO), and if the DE/RE jumper is set to GND
            // for real listen mode, that directly fights the output logic
            // trying to drive the same DE/RE line high - the same class
            // of pin contention behind the DE/RE overheating reported
            // earlier. So this refuses to open, rather than warning and
            // proceeding into a state that's electrically broken.
            if (outputDevices.count(p.device)) {
                p.outputClash = true;
                LogErr(VB_PLUGIN,
                       "fpp-dmx-input: not opening /dev/%s for input '%s' - it's also configured as an enabled channel output (Channel Outputs -> Other). Disable that output first.\n",
                       p.device.c_str(), p.label.c_str());
                continue;
            }

            std::string devPath = "/dev/" + p.device;
            // "8N2" = 8 data bits, no parity, 2 stop bits - DMX-512 framing.
            // SerialOpen() (linked in from libfpp) already handles the
            // non-standard 250000 baud rate via the termios2/BOTHER path.
            p.fd = SerialOpen(devPath.c_str(), 250000, "8N2", false);
            if (p.fd < 0) {
                LogErr(VB_PLUGIN, "fpp-dmx-input: could not open %s for input '%s'\n",
                       devPath.c_str(), p.label.c_str());
                continue;
            }

            // FPP 5.x's SerialOpen() doesn't set BRKINT for input mode
            // (added upstream later, specifically for DMX-style break
            // detection). Add it ourselves on the fd SerialOpen already
            // configured: with BRKINT set, a real UART break condition
            // shows up as a single NUL byte in the read stream instead of
            // being dropped/mangled, which is how frame boundaries below
            // are found.
            struct termios tty;
            if (tcgetattr(p.fd, &tty) == 0) {
                tty.c_iflag |= BRKINT;
                tcsetattr(p.fd, TCSANOW, &tty);
            }

            LogInfo(VB_PLUGIN, "fpp-dmx-input: '%s' listening on %s, DMX channels %d-%d\n",
                    p.label.c_str(), devPath.c_str(), p.startChannel, p.startChannel + p.channelCount - 1);

            DMXInputPort* pp = &p; // stable: ports vector isn't resized after loadConfig()
            std::function<bool(int)> fn = [this, pp](int) {
                this->handleRead(*pp);
                return false;
            };
            callbacks[p.fd] = fn;
        }
    }

    // Runs every output frame; used only to detect a port going stale
    // between UART reads (edge-triggered "signal lost" logging). Does not
    // touch seqData itself - channel injection happens directly from
    // handleRead() via Sequence::SetBridgeData().
    virtual void modifyChannelData(int ms, uint8_t* seqData) override {
        uint64_t now = (uint64_t)GetTimeMS();
        for (auto& p : ports) {
            if (!p.enabled || p.fd < 0 || !p.signalOk || p.lastFrameMS == 0) {
                continue;
            }
            if ((now - p.lastFrameMS) > (uint64_t)p.expireMS) {
                p.signalOk = false;
                LogWarn(VB_PLUGIN, "fpp-dmx-input: '%s' on /dev/%s lost signal (no frame for over %dms)\n",
                        p.label.c_str(), p.device.c_str(), p.expireMS);
            }
        }
    }

    virtual const std::shared_ptr<httpserver::http_response> render_GET(const httpserver::http_request& req) override {
        Json::Value root;
        Json::Value inputs(Json::arrayValue);
        uint64_t now = (uint64_t)GetTimeMS();
        for (auto& p : ports) {
            Json::Value j;
            j["label"] = p.label;
            j["device"] = p.device;
            j["enabled"] = p.enabled;
            j["opened"] = p.fd >= 0;
            j["outputClash"] = p.outputClash.load();
            j["startChannel"] = p.startChannel;
            j["channelCount"] = p.channelCount;
            j["packetsReceived"] = (Json::UInt64)p.packetsReceived;
            j["bytesReceived"] = (Json::UInt64)p.bytesReceived;
            j["errorPackets"] = (Json::UInt64)p.errorPackets;
            j["signalOk"] = p.signalOk.load();
            j["lastFrameAgeMS"] = p.lastFrameMS == 0 ? -1 : (Json::Int64)(now - p.lastFrameMS);

            Json::Value values(Json::arrayValue);
            {
                std::lock_guard<std::mutex> lock(p.liveValuesMutex);
                for (uint8_t v : p.liveValues) {
                    values.append(v);
                }
            }
            j["values"] = values;

            inputs.append(j);
        }
        root["inputs"] = inputs;

        Json::Value trigs(Json::arrayValue);
        for (auto& t : triggers) {
            Json::Value j;
            j["enabled"] = t.enabled;
            j["startChannel"] = t.startChannel;
            j["endChannel"] = t.endChannel;
            j["valueMin"] = t.valueMin;
            j["valueMax"] = t.valueMax;
            j["continuous"] = t.continuous;
            j["command"] = t.command;
            j["fireCount"] = (Json::UInt64)t.fireCount;
            trigs.append(j);
        }
        root["triggers"] = trigs;

        Json::StreamWriterBuilder wbuilder;
        std::string out = Json::writeString(wbuilder, root);
        return std::shared_ptr<httpserver::http_response>(new httpserver::string_response(out, 200));
    }

    // Test/simulate endpoint: injects one synthetic channel value straight
    // into SetBridgeData() + evaluateTriggers(), bypassing the UART/epoll
    // path entirely - lets the status page's "Simulate" tool prove a
    // trigger actually fires without needing a real DMX source connected,
    // which otherwise has no way to be verified from the UI at all.
    // POST body: {"channel": 1-512, "value": 0-255}.
    virtual const std::shared_ptr<httpserver::http_response> render_POST(const httpserver::http_request& req) override {
        Json::Value body;
        Json::CharReaderBuilder rbuilder;
        std::string errs;
        std::string content = req.get_content();
        std::istringstream iss(content);
        if (!Json::parseFromStream(rbuilder, iss, &body, &errs) ||
            !body.isMember("channel") || !body.isMember("value")) {
            return std::shared_ptr<httpserver::http_response>(
                new httpserver::string_response("{\"error\":\"expected {channel,value}\"}", 400));
        }

        int channel = std::min(512, std::max(1, body["channel"].asInt()));
        uint8_t value = (uint8_t)std::min(255, std::max(0, body["value"].asInt()));
        uint64_t ts = (uint64_t)GetTimeMS();

        sequence->SetBridgeData(&value, channel - 1, 1, ts + 5000);
        evaluateTriggers(channel, &value, 1, ts);

        // Also reflected in whichever enabled input's live meter covers
        // this channel, so the simulated value is visible there too, not
        // just in the trigger fire count.
        for (auto& p : ports) {
            if (p.enabled && channel >= p.startChannel && channel < p.startChannel + p.channelCount) {
                updateLiveValues(p, channel - p.startChannel, &value, 1);
            }
        }

        Json::Value resp;
        resp["ok"] = true;
        resp["channel"] = channel;
        resp["value"] = value;
        Json::StreamWriterBuilder wbuilder;
        return std::shared_ptr<httpserver::http_response>(
            new httpserver::string_response(Json::writeString(wbuilder, resp), 200));
    }

    virtual void registerApis(httpserver::webserver* m_ws) override {
        m_ws->register_resource("/DMXInput/status", this, true);
    }

private:
    void loadConfig() {
        // Any number of inputs (soft cap 8 - generous vs. the handful of
        // real UARTs a BeagleBone actually exposes), same JSON-array/
        // add-remove-row pattern as triggers, rather than a fixed pair of
        // numbered ini keys - a board with more than two usable serial
        // ports, or a user who only wants one, isn't forced into exactly
        // two slots.
        ports.clear();
        Json::Value iroot;
        bool haveFile = FileExists(inputsConfigPath()) && LoadJsonFromFile(inputsConfigPath(), iroot) &&
            iroot.isMember("inputs") && iroot["inputs"].isArray() && iroot["inputs"].size() > 0;
        if (haveFile) {
            int count = 0;
            for (auto& ij : iroot["inputs"]) {
                if (++count > 8) {
                    LogWarn(VB_PLUGIN, "fpp-dmx-input: more than 8 inputs configured, ignoring the rest\n");
                    break;
                }
                ports.emplace_back();
                DMXInputPort& p = ports.back();
                p.label = ij.get("label", "DMX").asString();
                p.device = ij.get("device", "ttyS1").asString();
                p.enabled = ij.get("enabled", false).asBool();
                p.startChannel = std::max(1, ij.get("startChannel", 1).asInt());
                p.channelCount = std::min(512, std::max(1, ij.get("channelCount", 512).asInt()));
                p.expireMS = std::max(1, ij.get("expireMS", 5000).asInt());
                p.liveValues.assign(p.channelCount, 0);
            }
        } else {
            // No config file yet (fresh install): seed the two slots that
            // match this board's two physical DMX ports, both off by
            // default - matches the pre-dynamic-inputs default exactly.
            static const char* defaultDevice[2] = { "ttyS1", "ttyS2" };
            static const char* defaultLabel[2] = { "DMX1", "DMX2" };
            for (int i = 0; i < 2; i++) {
                ports.emplace_back();
                DMXInputPort& p = ports.back();
                p.label = defaultLabel[i];
                p.device = defaultDevice[i];
                p.enabled = false;
                p.startChannel = 1;
                p.channelCount = 512;
                p.expireMS = 5000;
                p.liveValues.assign(p.channelCount, 0);
            }
        }
        checkOverlaps();

        // Same JSON-array/add-remove-row pattern as inputs above, in its
        // own file since the two lists are edited/saved independently in
        // the UI - matches the GET-whole-array/edit-locally/POST-whole-
        // array-back pattern core's own "Other" channel-output page
        // (co-other.json) uses for its own add/remove-row table.
        triggers.clear();
        Json::Value root;
        if (FileExists(triggersConfigPath()) && LoadJsonFromFile(triggersConfigPath(), root) &&
            root.isMember("triggers") && root["triggers"].isArray()) {
            int count = 0;
            for (auto& tj : root["triggers"]) {
                if (++count > 64) {
                    LogWarn(VB_PLUGIN, "fpp-dmx-input: more than 64 triggers configured, ignoring the rest\n");
                    break;
                }
                triggers.emplace_back();
                TriggerRule& t = triggers.back();
                t.enabled = tj.get("enabled", false).asBool();
                t.startChannel = std::max(1, tj.get("startChannel", 1).asInt());
                t.endChannel = std::max(t.startChannel, tj.get("endChannel", t.startChannel).asInt());
                t.valueMin = std::min(255, std::max(0, tj.get("valueMin", 1).asInt()));
                t.valueMax = std::min(255, std::max(t.valueMin, tj.get("valueMax", 255).asInt()));
                t.command = tj.get("command", "").asString();
                t.args.clear();
                if (tj.isMember("args") && tj["args"].isArray()) {
                    for (auto& a : tj["args"]) {
                        t.args.push_back(a.asString());
                    }
                }
                t.cooldownMs = std::max(0, tj.get("cooldownMs", 1000).asInt());
                t.continuous = tj.get("continuous", false).asBool();
                t.lastValues.assign(t.endChannel - t.startChannel + 1, 0);
            }
        }
    }

    static std::string inputsConfigPath() {
        return std::string(FPP_DIR_CONFIG) + "/plugin.fpp-dmx-input-inputs.json";
    }

    static std::string triggersConfigPath() {
        return std::string(FPP_DIR_CONFIG) + "/plugin.fpp-dmx-input-triggers.json";
    }

    // Devices (e.g. "ttyS1") bound to an enabled entry in core's own
    // "Other" channel-output config (Channel Outputs -> Other), regardless
    // of output type - DMX-Open, GenericSerial, Renard, etc. all bind to a
    // /dev device the same way, so the type doesn't matter, only whether
    // it's the same device node addControlCallbacks() is about to open.
    static std::set<std::string> getEnabledOutputDevices() {
        std::set<std::string> devices;
        std::string path = std::string(FPP_DIR_CONFIG) + "/co-other.json";
        Json::Value root;
        if (!FileExists(path) || !LoadJsonFromFile(path, root) ||
            !root.isMember("channelOutputs") || !root["channelOutputs"].isArray()) {
            return devices;
        }
        for (auto& out : root["channelOutputs"]) {
            if (out.get("enabled", 0).asInt() != 0 && out.isMember("device")) {
                devices.insert(out["device"].asString());
            }
        }
        return devices;
    }

    // Copies n freshly-decoded bytes into p.liveValues starting at
    // offsetFromStart (0-based, i.e. channel p.startChannel+offsetFromStart)
    // for the status page's live meters. Called from handleRead() right
    // alongside SetBridgeData(), same offset math, so liveValues always
    // matches what was actually written into FPP's channel data.
    static void updateLiveValues(DMXInputPort& p, int offsetFromStart, const uint8_t* values, int n) {
        std::lock_guard<std::mutex> lock(p.liveValuesMutex);
        int copyN = std::min(n, (int)p.liveValues.size() - offsetFromStart);
        if (copyN > 0) {
            std::memcpy(p.liveValues.data() + offsetFromStart, values, copyN);
        }
    }

    // Substitutes any "$VALUE" arg with the current channel value (for
    // continuous-mode triggers tracking a live fader) and runs the
    // command. Builds a temporary copy rather than mutating t.args, since
    // that's the saved config and must stay literal ("$VALUE", not
    // whatever the last value happened to be) for the next substitution.
    static void runTriggerCommand(TriggerRule& t, uint8_t v) {
        if (!t.continuous) {
            CommandManager::INSTANCE.run(t.command, t.args);
            return;
        }
        std::vector<std::string> args = t.args;
        std::string vs = std::to_string((int)v);
        for (auto& a : args) {
            if (a == "$VALUE") {
                a = vs;
            }
        }
        CommandManager::INSTANCE.run(t.command, args);
    }

    // Called from handleRead() with the global (1-based) FPP channel number
    // of values[0], how many channel bytes were just decoded, and the
    // timestamp handleRead() already fetched for this read (reused here
    // instead of calling GetTimeMS() again) so it can check each
    // configured trigger's range against whatever slice of the universe
    // this particular read covered.
    void evaluateTriggers(int globalStartChannel, const uint8_t* values, int count, uint64_t ts) {
        if (triggers.empty()) {
            return;
        }
        int rangeEnd = globalStartChannel + count - 1;
        for (auto& t : triggers) {
            if (!t.enabled || t.command.empty()) {
                continue;
            }
            int lo = std::max(t.startChannel, globalStartChannel);
            int hi = std::min(t.endChannel, rangeEnd);
            for (int ch = lo; ch <= hi; ch++) {
                uint8_t v = values[ch - globalStartChannel];
                int idx = ch - t.startChannel;
                uint8_t prev = t.lastValues[idx];
                t.lastValues[idx] = v;

                bool fire;
                if (t.continuous) {
                    // Tracks the live value, not a band - a fader moving
                    // at all is what matters here, not where it crossed.
                    fire = (v != prev);
                } else {
                    bool nowIn = v >= t.valueMin && v <= t.valueMax;
                    bool wasIn = prev >= t.valueMin && prev <= t.valueMax;
                    fire = nowIn && !wasIn;
                }
                if (fire) {
                    if (ts - t.lastFireMS < (uint64_t)t.cooldownMs) {
                        continue;
                    }
                    t.lastFireMS = ts;
                    t.fireCount++;
                    LogInfo(VB_PLUGIN,
                            "fpp-dmx-input: trigger channel %d=%d fired command '%s'\n",
                            ch, (int)v, t.command.c_str());
                    runTriggerCommand(t, v);
                }
            }
        }
    }

    void checkOverlaps() {
        for (size_t i = 0; i < ports.size(); i++) {
            if (!ports[i].enabled) {
                continue;
            }
            int aStart = ports[i].startChannel;
            int aEnd = aStart + ports[i].channelCount - 1;
            for (size_t j = i + 1; j < ports.size(); j++) {
                if (!ports[j].enabled) {
                    continue;
                }
                int bStart = ports[j].startChannel;
                int bEnd = bStart + ports[j].channelCount - 1;
                if (aStart <= bEnd && bStart <= aEnd) {
                    LogWarn(VB_PLUGIN,
                            "fpp-dmx-input: inputs '%s' and '%s' write overlapping channel ranges (%d-%d and %d-%d)\n",
                            ports[i].label.c_str(), ports[j].label.c_str(), aStart, aEnd, bStart, bEnd);
                }
            }
        }
    }

    void handleRead(DMXInputPort& p) {
        uint8_t buf[513];
        int sz = read(p.fd, buf, sizeof(buf));
        while (sz > 0) {
            uint64_t ts = (uint64_t)GetTimeMS();
            uint64_t diff = ts - p.lastByteMS;
            p.lastByteMS = ts;

            if (buf[0] == 0 && diff > 3) {
                // BRKINT-injected marker byte -> a real UART break just
                // happened -> mark-after-break start of a new DMX frame.
                // buf[1] is the DMX start code, channel data follows.
                int n = std::min(sz - 1, p.channelCount);
                if (n > 0) {
                    sequence->SetBridgeData(&buf[1], p.startChannel - 1, n, ts + p.expireMS);
                    evaluateTriggers(p.startChannel, &buf[1], n, ts);
                    updateLiveValues(p, 0, &buf[1], n);
                }
                p.rxIndex = sz - 1;
                p.packetsReceived++;
                p.bytesReceived += sz;
                p.lastFrameMS = ts;
                p.consecutiveErrors = 0;
                if (!p.signalOk) {
                    p.signalOk = true;
                    LogInfo(VB_PLUGIN, "fpp-dmx-input: '%s' on /dev/%s signal acquired\n",
                            p.label.c_str(), p.device.c_str());
                }
            } else if (diff < 3) {
                // continuation of the frame already in progress
                int n = sz;
                if (p.rxIndex >= p.channelCount) {
                    n = 0;
                } else if (p.rxIndex + sz > p.channelCount) {
                    n = p.channelCount - p.rxIndex;
                }
                if (n > 0) {
                    sequence->SetBridgeData(buf, p.startChannel - 1 + p.rxIndex, n, ts + p.expireMS);
                    evaluateTriggers(p.startChannel + p.rxIndex, buf, n, ts);
                    updateLiveValues(p, p.rxIndex, buf, n);
                }
                p.rxIndex += sz;
                p.bytesReceived += sz;
            } else {
                // gap large enough to imply a break, but no marker byte
                // seen - a framing/sync error. Discard and wait for the
                // next real break to resynchronize.
                p.errorPackets++;
                p.consecutiveErrors++;
                if (p.consecutiveErrors == 20) {
                    LogWarn(VB_PLUGIN,
                            "fpp-dmx-input: '%s' on /dev/%s has seen %d consecutive framing errors - check wiring / DE-RE jumper\n",
                            p.label.c_str(), p.device.c_str(), p.consecutiveErrors);
                }
            }

            sz = read(p.fd, buf, sizeof(buf));
        }
    }

    std::deque<DMXInputPort> ports; // not vector: atomic members make
                                     // DMXInputPort non-movable, and deque
                                     // never relocates existing elements
    std::deque<TriggerRule> triggers; // same reason
};

extern "C" {
FPPPlugin* createPlugin() {
    return new FPPDMXInputPlugin();
}
}
