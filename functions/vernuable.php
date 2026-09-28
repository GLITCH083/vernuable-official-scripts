<?php
/**
 * Vernuable API client — method=bitcotask (motion GIF → x,y)
 * Docs: https://vernuable.my.id/docs
 *
 * @version 1.0.0
 */

if (!function_exists('vernuable_balance')) {

function vernuable_key() {
    static $key = null;
    if ($key === null) {
        $key = saveData("vernuable-Bot", "vernuable-apikey");
    }
    return $key;
}

function vernuable_base() {
    return "https://vernuable.my.id";
}

function vernuable_balance() {
    $key = vernuable_key();
    $url = vernuable_base() . "/res.php?key=" . urlencode($key) . "&action=getbalance&json=1";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => "VernuableOfficialScripts/1.0",
    ]);
    $raw = curl_exec($ch);
    if (PHP_VERSION_ID < 80500) {
        @curl_close($ch);
    }
    $j = json_decode($raw, true);
    if (isset($j["status"]) && (string)$j["status"] === "1") {
        return (float)($j["request"] ?? $j["balance"] ?? 0);
    }
    return null;
}

/**
 * Solve BitcoTasks motion captcha.
 * @param string $gifBytes raw GIF binary
 * @return array|null ['x'=>int,'y'=>int] or null
 */
function vernuable_solve_bitcotask($gifBytes, $timeout = 180, $poll = 2) {
    $key  = vernuable_key();
    $b64  = base64_encode($gifBytes);
    $base = vernuable_base();

    // -------------------------------------------------------------------------
    // 1) Submit task
    // -------------------------------------------------------------------------
    $post = [
        "key"    => $key,
        "method" => "bitcotask",
        "body"   => $b64,
        "json"   => 1,
    ];

    $ch = curl_init($base . "/in.php");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($post),
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => "VernuableOfficialScripts/1.0",
    ]);
    $raw = curl_exec($ch);
    if (PHP_VERSION_ID < 80500) {
        @curl_close($ch);
    }
    $j = json_decode($raw, true);

    // Try alternate field name if first attempt failed
    if (!isset($j["status"]) || (string)$j["status"] !== "1") {
        $post["image"] = $b64;
        unset($post["body"]);

        $ch = curl_init($base . "/in.php");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($post),
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => "VernuableOfficialScripts/1.0",
        ]);
        $raw = curl_exec($ch);
        if (PHP_VERSION_ID < 80500) {
            @curl_close($ch);
        }
        $j = json_decode($raw, true);
    }


    if (!isset($j["status"]) || (string)$j["status"] !== "1") {
        return null;
    }

    $taskId = $j["request"];
    $t0     = time();

    // -------------------------------------------------------------------------
    // 2) Poll for result
    // -------------------------------------------------------------------------
    while ((time() - $t0) < $timeout) {
        sleep($poll);

        $url = $base . "/res.php?key=" . urlencode($key)
             . "&action=get&id=" . urlencode($taskId) . "&json=1";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => "VernuableOfficialScripts/1.0",
        ]);
        $raw = curl_exec($ch);
        if (PHP_VERSION_ID < 80500) {
            @curl_close($ch);
        }
        $out = json_decode($raw, true);

        $st = isset($out["status"]) ? (string)$out["status"] : "";

        // ---------- SUCCESS ----------
        if ($st === "1") {

            // (a) Top-level x/y present — your API returns this
            if (isset($out["x"], $out["y"])
                && is_numeric($out["x"]) && is_numeric($out["y"])) {
                return [
                    "x"     => (float)$out["x"],
                    "y"     => (float)$out["y"],
                    "index" => $out["index"] ?? null,
                    "raw"   => $out,
                ];
            }

            $req = $out["request"] ?? null;

            // (b) request is an array with x/y
            if (is_array($req) && isset($req["x"], $req["y"])) {
                return [
                    "x"     => (float)$req["x"],
                    "y"     => (float)$req["y"],
                    "index" => $req["index"] ?? null,
                    "raw"   => $req,
                ];
            }

            if (is_string($req)) {

                // (c) request is the still-pending task ID — keep polling
                //     (matches an 8+ char hex string with no punctuation)
                if (preg_match('/^[a-f0-9]{8,}$/i', $req)) {
                    continue;
                }

                // (d) request is a JSON-encoded solution string
                //     e.g. '{"x":163,"y":139,"index":2}'
                $decoded = json_decode($req, true);
                if (is_array($decoded) && isset($decoded["x"], $decoded["y"])) {
                    return [
                        "x"     => (float)$decoded["x"],
                        "y"     => (float)$decoded["y"],
                        "index" => $decoded["index"] ?? null,
                        "raw"   => $decoded,
                    ];
                }

                // (e) request is "x|y" pipe-separated
                if (strpos($req, "|") !== false) {
                    list($a, $b) = explode("|", $req, 2);
                    return [
                        "x"   => (float)$a,
                        "y"   => (float)$b,
                        "raw" => $req,
                    ];
                }
            }

            // Unknown shape but status=1 — return what we have
            return ["raw" => $req];
        }

        // ---------- ERROR (real failure, not NOT_READY) ----------
        if ($st === "0"
            && stripos((string)($out["request"] ?? ""), "NOT_READY") === false) {
            return null;
        }

        // Otherwise keep polling (NOT_READY, empty status, etc.)
    }

    // Timeout
    return null;
}

} // end function_exists guard
