#!/usr/bin/env php
<?php
/**
 * BitcoTasks.com — offerwall PTC via Vernuable bitcotask
 * HAR-accurate captcha flow (v0.0.0)
 *
 * Captcha steps (from real HAR):
 *  1. GET firewall → captcha JS URL
 *  2. GET captcha JS → extract submit path + field names + response keys
 *  3. POST ?action=captcha JSON {t,r} → image (full GIF) + w/h/refZone
 *  4. Vernuable solve → x,y  (x must be >= refZone)
 *  5. POST /captcha2/{short}?action=data&cdata=HEX
 *     body: STATIC_KEY=STATIC_VAL&COORDS_KEY=[x,y]
 *  6. POST firewall action=validate&UEjS={token}
 */

error_reporting(0);
require_once dirname(__DIR__, 2) . "/functions/function.php";
require_once dirname(__DIR__, 2) . "/functions/vernuable.php";

define("HOST", "bitcotasks.com");
define("BASE", "https://bitcotasks.com");
define("MIN_GIF_BYTES", 800);

enableCtrlC();

$UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36";

$_LOG_OPEN = false;
function logStart($title) {
    global $_LOG_OPEN;
    if ($_LOG_OPEN) themeClose();
    themeOpen($title);
    $_LOG_OPEN = true;
}
function logLine($label, $value) {
    global $_LOG_OPEN;
    if (!$_LOG_OPEN) { themeOpen("STATUS"); $_LOG_OPEN = true; }
    themeRow($label, $value);
}
function logEnd() {
    global $_LOG_OPEN;
    if ($_LOG_OPEN) { themeClose(); $_LOG_OPEN = false; }
}

function accountsFile() { return configPath(HOST, "accounts"); }

function loadAccounts() {
    $f = accountsFile();
    if (!file_exists($f)) return [];
    $out = [];
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === "" || $line[0] === "#") continue;
        $p = explode("|", $line);
        $out[] = [
            "name"   => $p[0] ?? "acc",
            "key"    => $p[1] ?? "",
            "sub_id" => $p[2] ?? "",
            "proxy"  => !empty($p[3]) ? $p[3] : null,
        ];
    }
    return $out;
}

function saveAccounts($list) {
    $lines = ["# name|publisher_key|sub_id|proxy"];
    foreach ($list as $a) {
        $lines[] = implode("|", [$a["name"], $a["key"], $a["sub_id"], $a["proxy"] ?? ""]);
    }
    file_put_contents(accountsFile(), implode("\n", $lines) . "\n");
}

function addAccountInteractive() {
    echo WHITE . "  Name: " . RESET;
    $name = trim(fgets(STDIN));
    echo WHITE . "  Publisher key: " . RESET;
    $key = trim(fgets(STDIN));
    echo WHITE . "  sub_id: " . RESET;
    $sub = trim(fgets(STDIN));
    echo WHITE . "  Proxy (host:port:user:pass or empty): " . RESET;
    $proxy = trim(fgets(STDIN));
    if ($key === "" || $sub === "") {
        echo RED . "  key + sub_id required\n" . RESET;
        return;
    }
    $list = loadAccounts();
    $list[] = ["name" => $name ?: "acc", "key" => $key, "sub_id" => $sub, "proxy" => $proxy ?: null];
    saveAccounts($list);
    echo GREEN . "  Saved.\n" . RESET;
}

function askClaims() {
    echo WHITE . "  Claims per account (number, or 0 = unlimited): " . RESET;
    $n = trim(fgets(STDIN));
    if ($n === "" || strtolower($n) === "unlimited" || $n === "u") return 0;
    $v = (int)$n;
    return $v < 0 ? 0 : $v;
}

function httpRequest($url, $method = "GET", $data = null, $headers = [], $proxy = null, $cookieJar = null) {
    global $UA;
    $ch = curl_init($url);
    $h = array_merge([
        "User-Agent: $UA",
        "Accept: application/json, text/javascript, */*; q=0.01",
        "Accept-Language: en-US,en;q=0.9",
        "Origin: " . BASE,
        "Referer: " . BASE . "/",
    ], $headers);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => $h,
        CURLOPT_FOLLOWLOCATION => true,
    ];
    if ($cookieJar) {
        $opts[CURLOPT_COOKIEJAR]  = $cookieJar;
        $opts[CURLOPT_COOKIEFILE] = $cookieJar;
    }
    if ($proxy) {
        $px = parseProxyString($proxy);
        if ($px) $opts[CURLOPT_PROXY] = $px;
    }
    if (strtoupper($method) === "POST") {
        $opts[CURLOPT_POST] = true;
        if (is_array($data)) {
            $opts[CURLOPT_POSTFIELDS] = http_build_query($data);
        } elseif (is_string($data)) {
            $opts[CURLOPT_POSTFIELDS] = $data;
        }
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (PHP_VERSION_ID < 80500) @curl_close($ch);
    return [$code, $body];
}

function makeNotifCookies() {
    $dt = new DateTime("now", new DateTimeZone("GMT"));
    $dt->modify("+30 minutes");
    $exp = $dt->format("D, d M Y H:i:s") . " GMT";
    $randstr = substr(md5($exp . mt_rand() . microtime(true)), 2, 9);
    return [
        "_bitco_notifad"        => "ad_value_" . $randstr,
        "_bitco_notifad_expire" => "expires=" . $exp,
    ];
}

/** Write notif cookies into curl cookie-jar so PHPSESSID is kept (never replace Cookie header). */
function injectNotifCookies($jar) {
    $n = makeNotifCookies();
    $expire = time() + 1800;
    $lines = [];
    if (is_file($jar)) {
        $lines = file($jar, FILE_IGNORE_NEW_LINES);
        // drop old notif lines
        $lines = array_values(array_filter($lines, function ($l) {
            return strpos($l, "_bitco_notifad") === false;
        }));
    }
    if (empty($lines) || (isset($lines[0]) && strpos($lines[0], "Netscape") === false && strpos($lines[0], "#") !== 0)) {
        array_unshift($lines, "# Netscape HTTP Cookie File");
    }
    // domain  flag  path  secure  expires  name  value
    $lines[] = "bitcotasks.com\tFALSE\t/\tFALSE\t$expire\t_bitco_notifad\t" . $n["_bitco_notifad"];
    $lines[] = ".bitcotasks.com\tTRUE\t/\tFALSE\t$expire\t_bitco_notifad\t" . $n["_bitco_notifad"];
    $lines[] = "bitcotasks.com\tFALSE\t/\tFALSE\t$expire\t_bitco_notifad_expire\t" . $n["_bitco_notifad_expire"];
    $lines[] = ".bitcotasks.com\tTRUE\t/\tFALSE\t$expire\t_bitco_notifad_expire\t" . $n["_bitco_notifad_expire"];
    file_put_contents($jar, implode("\n", $lines) . "\n");
    return $n;
}


/** HAR + Python client: GET /getads.php with notif cookies — notification ads endpoint */
function fetchGetAds($proxy, $jar, $referer = null) {
    $j = null;
    for ($round = 1; $round <= 3; $round++) {
        // ensure notif cookies present each round
        injectNotifCookies($jar);
        $ref = $referer ?: (BASE . "/");
        list($code, $body) = httpRequest(BASE . "/getads.php", "GET", null, [
            "Accept: application/json, text/javascript, */*; q=0.01",
            "X-Requested-With: XMLHttpRequest",
            "Referer: $ref",
        ], $proxy, $jar);
        $j = json_decode($body, true);
        $keys = is_array($j) ? implode(",", array_slice(array_keys($j), 0, 8)) : "";
        $rawShow = is_array($j) ? ("keys=$keys") : ("raw=" . substr(preg_replace('/\s+/', ' ', (string)$body), 0, 50));
        if (is_array($j) && !empty($j)) logLine("GetAds", "HTTP $code $rawShow");
        if (is_array($j) && !empty($j)) break;
        usleep(600000);
    }
    if (!is_array($j) || empty($j)) {
        // getads empty — try sponsored silently
        // ofads.js also loads sponsored.php
        list($sc, $sbody) = httpRequest(BASE . "/sponsored.php", "GET", null, [
            "Accept: application/json, text/javascript, */*; q=0.01",
            "X-Requested-With: XMLHttpRequest",
            "Referer: " . ($referer ?: BASE . "/"),
        ], $proxy, $jar);
        $sj = json_decode($sbody, true);
        // quiet sponsored
        if (is_array($sj)) {
            foreach ($sj as $row) {
                if (!is_array($row)) continue;
                foreach (["url", "img", "link"] as $k) {
                    if (empty($row[$k]) || !is_string($row[$k])) continue;
                    $u = $row[$k];
                    if (strpos($u, "http") !== 0) $u = rtrim(BASE, "/") . "/" . ltrim($u, "/");
                    httpRequest($u, "GET", null, ["Referer: " . BASE . "/sponsored.php"], $proxy, $jar);
                }
            }
        }
        httpRequest(BASE . "/files/notifads/", "GET", null, ["Referer: " . BASE . "/"], $proxy, $jar);
        return $j;
    }
    $urls = [];
    foreach (["url", "link", "click_url", "target", "src", "img"] as $k) {
        if (!empty($j[$k]) && is_string($j[$k]) && strpos($j[$k], "http") === 0) $urls[] = $j[$k];
        elseif (!empty($j[$k]) && is_string($j[$k]) && strpos($j[$k], "/") === 0) $urls[] = rtrim(BASE, "/") . $j[$k];
    }
    if (!empty($j["data"]) && is_array($j["data"])) {
        foreach ($j["data"] as $row) {
            if (!is_array($row)) continue;
            foreach (["url", "link", "click_url", "target", "img"] as $k) {
                if (empty($row[$k]) || !is_string($row[$k])) continue;
                $u = $row[$k];
                if (strpos($u, "http") === 0) $urls[] = $u;
                elseif (strpos($u, "/") === 0) $urls[] = rtrim(BASE, "/") . $u;
            }
        }
    }
    // relative notifads images
    if (!empty($j["img"]) && is_string($j["img"]) && strpos($j["img"], "http") !== 0) {
        $urls[] = rtrim(BASE, "/") . "/" . ltrim($j["img"], "/");
    }
    $urls = array_values(array_unique($urls));
    $n = 0;
    foreach ($urls as $u) {
        logLine("GetAdsURL", substr($u, 0, 64));
        list($ac, $abody) = httpRequest($u, "GET", null, [
            "Referer: " . BASE . "/getads.php",
            "Accept: text/html,image/*,*/*",
        ], $proxy, $jar);
        logLine("GetAdsRun", "HTTP $ac " . strlen((string)$abody) . "B");
        // bmcdn / openrtb trackers inside
        if (is_string($abody) && preg_match_all('#https?://(?:cdn\.bmcdn[0-9]*\.com|[^\\s"\']*notif[^\\s"\']*)[^\\s"\']{5,100}#i', $abody, $mm)) {
            $ex = 0;
            foreach (array_unique($mm[0]) as $eu) {
                httpRequest($eu, "GET", null, ["Referer: $u"], $proxy, $jar);
                $ex++;
                if ($ex >= 4) break;
            }
            if ($ex) logLine("GetAdsBmcdn", "$ex tracker(s)");
        }
        usleep(700000);
        $n++;
        if ($n >= 3) break;
    }
    logLine("GetAdsHit", $n ? "$n ad url(s) opened" : "0 urls");
    return $j;
}

function b64ToGif($s) {
    if (!is_string($s) || $s === "") return null;
    $s = trim($s);
    if (stripos($s, "base64,") !== false) {
        $s = explode("base64,", $s, 2)[1];
    }
    $s = preg_replace('/\s+/', '', $s);
    $bin = base64_decode($s, false);
    if ($bin === false || strlen($bin) < 50) return null;
    return $bin;
}

function isValidGif($bin) {
    return is_string($bin) && strlen($bin) >= MIN_GIF_BYTES && substr($bin, 0, 3) === "GIF";
}

/**
 * Parse session captcha JS for submit endpoint + form fields + response keys.
 * HAR: xhr.open("POST","/captcha2/CkHj?action=data&cdata=HEX")
 *      payload = "vRYsH=YfbfOX&hPDgy=" + encodeURIComponent(JSON.stringify(hPDgy))
 *      if (response.ZiFLv) { UEjS = response.ecJQSy }
 */
function parseCaptchaJs($js) {
    $out = [
        "submit_path" => null,
        "static_key"  => null,
        "static_val"  => null,
        "coords_key"  => null,
        "success_key" => null,
        "token_key"   => null,
        "atxr_key"    => null,
        "validate_field" => null,
        "refZone"     => 0,
    ];
    if (!is_string($js) || $js === "") return $out;

    if (preg_match('#xhr\.open\(\s*["\']POST["\']\s*,\s*["\'](/captcha2/[^"\']+)["\']#i', $js, $m)) {
        $out["submit_path"] = $m[1];
    } elseif (preg_match('#["\'](/captcha2/[A-Za-z0-9]+\\?action=data&cdata=[a-f0-9]+)["\']#i', $js, $m)) {
        $out["submit_path"] = $m[1];
    }

    // var payload = "KEY=VAL&COORDSKEY=" + encodeURIComponent(...)
    if (preg_match('#var\s+payload\s*=\s*"([A-Za-z0-9_]+)=([^"&]+)&([A-Za-z0-9_]+)="#i', $js, $m)) {
        $out["static_key"] = $m[1];
        $out["static_val"] = $m[2];
        $out["coords_key"] = $m[3];
    }

    // if (response.SUCCESSKEY) { ... response.TOKENKEY
    if (preg_match('#if\s*\(\s*response\.([A-Za-z0-9_]+)\s*\)#', $js, $m)) {
        $out["success_key"] = $m[1];
    }
    if (preg_match('#response\.([A-Za-z0-9_]+)\s*;\s*\n?\s*captchaResult#', $js, $m)) {
        $out["token_key"] = $m[1];
    } elseif (preg_match('#(?:value|cctoken)\s*=\s*response\.([A-Za-z0-9_]+)#', $js, $m)) {
        $out["token_key"] = $m[1];
    } elseif (preg_match_all('#response\.([A-Za-z0-9_]+)#', $js, $mm)) {
        $keys = array_values(array_unique($mm[1]));
        $keys = array_values(array_filter($keys, function ($k) {
            return !in_array($k, ["message"], true);
        }));
        if (count($keys) >= 2) {
            $out["success_key"] = $out["success_key"] ?: $keys[0];
            $out["token_key"] = $out["token_key"] ?: $keys[1];
        } elseif (count($keys) === 1) {
            $out["success_key"] = $out["success_key"] ?: $keys[0];
        }
    }
    // HAR: document.getElementById("UEjS").value = response.ecJQSy;
    // validate POST field name may change per session
    $out["validate_field"] = "UEjS";
    if (preg_match('#getElementById\(\s*["\']([A-Za-z0-9_]+)["\']\s*\)\s*\.\s*value\s*=\s*response\.([A-Za-z0-9_]+)#', $js, $m)) {
        $out["validate_field"] = $m[1];
        $out["token_key"] = $m[2];
    } elseif (preg_match('#\[\s*["\']name["\']\s*\]\s*=\s*["\']([A-Za-z0-9_]+)["\'].{0,40}response\.([A-Za-z0-9_]+)#s', $js, $m)) {
        $out["validate_field"] = $m[1];
        $out["token_key"] = $m[2];
    } elseif (preg_match('#name=["\']([A-Za-z0-9_]+)["\'].{0,80}response\.([A-Za-z0-9_]+)#s', $js, $m)) {
        $out["validate_field"] = $m[1];
        if (!$out["token_key"]) $out["token_key"] = $m[2];
    }
    return $out;
}

function fetchCaptchaChallenge($capUrl, $proxy, $jar) {
    list($code, $jraw) = httpRequest(
        $capUrl,
        "POST",
        json_encode([
            "t" => (int)(microtime(true) * 1000),
            "r" => mt_rand() / mt_getrandmax(),
        ]),
        [
            "Content-Type: application/json",
            "X-Requested-With: XMLHttpRequest",
        ],
        $proxy,
        $jar
    );
    $j = json_decode($jraw, true);
    if (!is_array($j)) {
        return [null, null, "bad JSON HTTP $code"];
    }
    // HAR: `image` is full GIF, `preload` is tiny blocker — pick largest valid
    $best = null;
    foreach (["image", "preload", "gif", "img"] as $k) {
        if (empty($j[$k]) || !is_string($j[$k])) continue;
        $try = b64ToGif($j[$k]);
        if ($try && (!$best || strlen($try) > strlen($best))) {
            $best = $try;
        }
    }
    return [$best, $j, null];
}


/**
 * Solve motion captcha on any page (firewall OR lead).
 * HAR: same captcha2 flow — JS → GIF → Vernuable → click → validate on $pageUrl
 * Returns true if captcha cleared (or none), false on hard fail.
 */

/**
 * Solve motion captcha on any page (firewall OR lead).
 * Retries up to $maxTries with fresh GIF each time (lead often needs 2-3).
 */
function solvePageCaptcha($pageUrl, $html, $proxy, $jar, $label = "Cap", $maxTries = 5) {
    // Returns: ["ok" => bool, "atxrN" => string]
    $capUrl = null;
    if (preg_match('#(/captcha2/[a-f0-9]{32,}\.js)\?action=captcha#i', $html, $m)) {
        $capUrl = BASE . $m[1] . "?action=captcha";
    } elseif (preg_match('#captcha2/([a-f0-9]{32,})\.js#i', $html, $m)) {
        $capUrl = BASE . "/captcha2/" . $m[1] . ".js?action=captcha";
    } elseif (preg_match('#(https?://[^"\'\s]+/captcha2/[a-f0-9]{32,})#i', $html, $m)) {
        $capUrl = rtrim($m[1], ".js") . ".js?action=captcha";
    }
    if (!$capUrl) {
        return ["ok" => true, "atxrN" => ""];
    }
    // solving...
    if (false) logLine($label . "URL", substr($capUrl, 0, 54));

    $jsUrl = preg_replace('#\?.*$#', '', $capUrl);
    if (substr($jsUrl, -3) !== ".js") $jsUrl .= ".js";
    $jsUrl .= "?action=captcha";
    list($jc, $jsBody) = httpRequest($jsUrl, "GET", null, [
        "Accept: */*",
        "Referer: $pageUrl",
    ], $proxy, $jar);
    $parsed = parseCaptchaJs($jsBody);
    // also parse atxr from JS body here
    if (is_string($jsBody)) {
        if (empty($parsed["atxr_key"])) {
            if (preg_match('#getElementById\([\'"]atxrN[\'"]\)\.value\s*=\s*response\.([A-Za-z0-9_]+)#', $jsBody, $am)) {
                $parsed["atxr_key"] = $am[1];
            } elseif (preg_match('#atxrN.{0,40}response\.([A-Za-z0-9_]+)#is', $jsBody, $am)) {
                $parsed["atxr_key"] = $am[1];
            } elseif (preg_match('#["\']atxrN["\'].{0,60}response\.([A-Za-z0-9_]+)#is', $jsBody, $am)) {
                $parsed["atxr_key"] = $am[1];
            }
        }
    }
    if (empty($parsed["atxr_key"]) && is_string($html)) {
        if (preg_match('#atxrN.{0,40}response\.([A-Za-z0-9_]+)#is', $html, $am)) {
            $parsed["atxr_key"] = $am[1];
        }
    }
    if (empty($parsed["submit_path"]) || empty($parsed["static_key"]) || empty($parsed["coords_key"])) {
        logLine($label, RED . "cannot parse captcha JS" . RESET);
        return ["ok" => false, "atxrN" => ""];
    }
    if (false) logLine($label . "JS", "OK path=" . substr($parsed["submit_path"], 0, 40));
    if (false) logLine($label . "Keys", "ok=" . ($parsed["success_key"] ?: "?") . " tok=" . ($parsed["token_key"] ?: "?") . " atxr=" . ($parsed["atxr_key"] ?: "?"));

    $isLead = (stripos($pageUrl, "/lead/") !== false);
    // parse atxr from page HTML too (lead embeds it)
    if (empty($parsed["atxr_key"]) && is_string($html)) {
        if (preg_match('#getElementById\([\'"]atxrN[\'"]\)\.value\s*=\s*response\.([A-Za-z0-9_]+)#', $html, $am)) {
            $parsed["atxr_key"] = $am[1];
            if (false) logLine($label . "Keys", "atxr from HTML=" . $am[1]);
        }
    }

    for ($try = 1; $try <= $maxTries; $try++) {
        if (false) logLine($label . "Try", "#$try/$maxTries");
        $gif = null;
        $j = null;
        for ($a = 1; $a <= 3; $a++) {
            list($gif, $j, $err) = fetchCaptchaChallenge($capUrl, $proxy, $jar);
            if ($gif && isValidGif($gif)) break;
            usleep(400000);
        }
        if (!$gif || !isValidGif($gif)) {
            logLine($label, RED . "no GIF" . RESET);
            usleep(500000);
            continue;
        }
        $w = (int)($j["w"] ?? 299);
        $h = (int)($j["h"] ?? 190);
        if (false) logLine($label . "GIF", strlen($gif) . "B {$w}x{$h}");

        // vernuable quiet
        $coords = vernuable_solve_bitcotask($gif);
        if (!$coords || !isset($coords["x"], $coords["y"])) {
            logLine($label, RED . "solve fail" . RESET);
            continue;
        }
        $x = (int)$coords["x"];
        $y = (int)$coords["y"];
        $rz = (int)($j["refZone"] ?? $parsed["refZone"] ?? 0);
        if ($rz > 0 && $x < $rz) $x = $rz + 5;
        if (false) logLine($label . "XY", "$x,$y");

        // HAR lead: ~6.7s challenge→click; use 8s+ to avoid "Please take your time"
        $waitMs = $isLead ? (8000 + mt_rand(500, 2000)) : (2800 + mt_rand(400, 900));
        if (false) logLine($label . "Wait", $waitMs . "ms");
        usleep($waitMs * 1000);

        $submitUrl = (strpos($parsed["submit_path"], "http") === 0)
            ? $parsed["submit_path"]
            : rtrim(BASE, "/") . $parsed["submit_path"];
        // HAR body: KEY=VAL&COORDS=%5Bx%2Cy%5D  (urlencoded form, NO charset)
        $coordsJson = json_encode([(int)$x, (int)$y], JSON_UNESCAPED_SLASHES);
        $body = $parsed["static_key"] . "=" . $parsed["static_val"]
            . "&" . $parsed["coords_key"] . "=" . rawurlencode($coordsJson);

        // Try HAR-exact content-type first; on "Invalid content type" try variants
        $ctTries = [
            ["Content-Type: application/x-www-form-urlencoded", "Accept: */*"],
            ["Content-Type: application/x-www-form-urlencoded; charset=UTF-8", "Accept: */*"],
            ["Content-Type: application/x-www-form-urlencoded; charset=UTF-8", "Accept: application/json, text/javascript, */*; q=0.01", "X-Requested-With: XMLHttpRequest"],
        ];
        $sc = 0; $sraw = ""; $sj = []; $skVal = null; $tkVal = null;
        $sk = $parsed["success_key"] ?? null;
        $tk = $parsed["token_key"] ?? null;
        foreach ($ctTries as $ti => $extraH) {
            $hdrs = array_merge($extraH, [
                "Referer: $pageUrl",
                "Origin: " . BASE,
            ]);
            list($sc, $sraw) = httpRequest($submitUrl, "POST", $body, $hdrs, $proxy, $jar);
            $sj = json_decode($sraw, true) ?: [];
            $skVal = ($sk && array_key_exists($sk, $sj)) ? $sj[$sk] : null;
            $tkVal = ($tk && !empty($sj[$tk])) ? $sj[$tk] : null;
            $msg = is_array($sj) ? (string)($sj["message"] ?? "") : "";
            if (!($skVal === true || $skVal === 1 || $skVal === "1" || $skVal === "true")) {
                logLine($label . "Click", "try#" . ($ti+1) . " Succ=false " . substr($msg, 0, 40));
            } else {
                logLine($label . "Click", GREEN . "OK" . RESET);
            }
            if ($skVal === true || $skVal === 1 || $skVal === "1" || $skVal === "true") {
                break;
            }
            if (stripos($msg, "content type") === false) {
                break; // not a CT issue — don't burn more tries
            }
            usleep(400000);
        }
        if (!($skVal === true || $skVal === 1 || $skVal === "1" || $skVal === "true")) {
            if (false) logLine($label . "Raw", substr((string)$sraw, 0, 90));
            usleep(1500000);
            continue;
        }

        // LIVE CAPTCHA JS pattern:
        //   if (response.SUCCESS_KEY) {
        //     document.getElementById("FIELD").value = response.TOKEN_KEY;
        //     cctoken = response.TOKEN_KEY;
        //   }
        // Lead page: atxrN = cctoken = TOKEN_KEY value (same dynamic token as firewall)
        $atxr = "";
        $token = null;
        $dynField = $parsed["validate_field"] ?? null;
        if ($tkVal && is_string($tkVal) && preg_match('/^[a-f0-9]{64}$/i', $tkVal)) {
            $token = $tkVal;
        }
        // Parse: getElementById("FIELD").value = response.TOKEN_KEY
        if (is_string($jsBody) && preg_match('#getElementById\([\'"]([A-Za-z0-9_]+)[\'"]\)\.value\s*=\s*response\.([A-Za-z0-9_]+)#', $jsBody, $gm)) {
            $dynField = $gm[1];
            if (!empty($sj[$gm[2]]) && is_string($sj[$gm[2]]) && preg_match('/^[a-f0-9]{64}$/i', $sj[$gm[2]])) {
                $token = $sj[$gm[2]];
                $tk = $gm[2];
            }
            if (false) logLine($label . "Field", "$dynField <= response." . $gm[2]);
        }
        // PRIMARY: atxrN = cctoken = token
        if ($token) {
            $atxr = $token;
            if (false) logLine($label . "Atxr", "cctoken " . substr($atxr, 0, 24) . "...");
        }
        $atxrCandidates = [];
        if ($token) $atxrCandidates["cctoken"] = $token;
        if (is_array($sj)) {
            foreach ($sj as $k => $v) {
                if (is_string($v) && preg_match('/^[a-f0-9]{64}$/i', $v) && !in_array($v, $atxrCandidates, true)) {
                    $atxrCandidates[$k] = $v;
                }
            }
        }

        // LEAD: HAR never POSTs validate — only start_view then processLead with ctoken
        // Validate on lead returns full HTML and can invalidate the captcha session
        if ($isLead) {
            logLine($label, GREEN . "cleared" . RESET);
            return ["ok" => true, "atxrN" => $atxr, "atxrCandidates" => $atxrCandidates ?? [], "token" => $token, "field" => $dynField];
        }

        // FIREWALL: validate with token
        $token = null;
        if ($tkVal && is_string($tkVal) && preg_match('/^[a-f0-9]{64}$/i', $tkVal)) {
            $token = $tkVal;
        }
        if (!$token) {
            logLine($label, RED . "no token" . RESET);
            continue;
        }
        logLine($label . "Tok", substr($token, 0, 20) . "...");
        usleep(900000);
        $vf = $parsed["validate_field"] ?? "UEjS";
        list($cv, $vraw) = httpRequest($pageUrl, "POST",
            "action=validate&" . $vf . "=" . rawurlencode($token),
            [
                "Content-Type: application/x-www-form-urlencoded; charset=UTF-8",
                "X-Requested-With: XMLHttpRequest",
                "Accept: application/json, text/javascript, */*; q=0.01",
                "Referer: $pageUrl",
                "Origin: " . BASE,
            ],
            $proxy, $jar
        );
        $vj = json_decode($vraw, true) ?: [];
        logLine($label . "Val", "HTTP $cv " . substr((string)$vraw, 0, 48));
        if (isset($vj["status"]) && in_array($vj["status"], ["success", "ok", 200, "200"], true)) {
            logLine($label, GREEN . "cleared" . RESET);
            return ["ok" => true, "atxrN" => $atxr, "token" => $token];
        }
        if ($cv === 200 && (isset($vj["redirect"]) || isset($vj["url"]))) {
            logLine($label, GREEN . "cleared" . RESET);
            return ["ok" => true, "atxrN" => $atxr, "token" => $token];
        }
        logLine($label, YELLOW . "val not ok — retry" . RESET);
        usleep(800000);
    }
    logLine($label, RED . "all tries failed" . RESET);
    return ["ok" => false, "atxrN" => ""];
}

function refreshFirewallToken($fwUrl, $key, $sub, $proxy, $jar, $label = "FwRefresh") {
    list($code, $html) = httpRequest($fwUrl, "GET", null, [
        "Accept: text/html,application/xhtml+xml",
        "Referer: " . BASE . "/",
    ], $proxy, $jar);
    if ($code >= 400 || !is_string($html) || $html === "") {
        logLine($label, RED . "firewall HTTP $code" . RESET);
        return null;
    }
    // Already past captcha?
    if (stripos($html, "captcha2") === false && stripos($html, "action=captcha") === false) {
        logLine($label, GREEN . "firewall already clear" . RESET);
        return ["token" => "", "success_key" => "", "success_val" => true, "validate_field" => "UEjS", "clear" => true];
    }
    $capUrl = null;
    if (preg_match('#(/captcha2/[a-f0-9]{32,}\.js)\?action=captcha#i', $html, $m)) {
        $capUrl = BASE . $m[1] . "?action=captcha";
    } elseif (preg_match('#captcha2/([a-f0-9]{32,})\.js#i', $html, $m)) {
        $capUrl = BASE . "/captcha2/" . $m[1] . ".js?action=captcha";
    }
    if (!$capUrl) {
        logLine($label, RED . "no captcha URL on firewall" . RESET);
        return null;
    }
    logLine($label, YELLOW . "solving FRESH firewall captcha…" . RESET);
    $jsUrl = preg_replace('#\?.*$#', '', $capUrl);
    if (substr($jsUrl, -3) !== ".js") $jsUrl .= ".js";
    $jsUrl .= "?action=captcha";
    list($jc, $jsBody) = httpRequest($jsUrl, "GET", null, [
        "Accept: */*",
        "Referer: $fwUrl",
    ], $proxy, $jar);
    $parsed = parseCaptchaJs($jsBody);
    if (empty($parsed["submit_path"]) || empty($parsed["static_key"]) || empty($parsed["coords_key"])) {
        logLine($label, RED . "JS parse fail" . RESET);
        return null;
    }
    logLine($label . "JS", "OK path=" . substr($parsed["submit_path"], 0, 40));
    if (false) logLine($label . "Keys", "ok=" . ($parsed["success_key"] ?? "?") . " tok=" . ($parsed["token_key"] ?? "?") . " val=" . ($parsed["validate_field"] ?? "UEjS"));

    for ($try = 1; $try <= 4; $try++) {
        if (false) logLine($label . "Try", "#$try/4");
        list($gif, $j, $err) = fetchCaptchaChallenge($capUrl, $proxy, $jar);
        if (!$gif || !isValidGif($gif)) {
            logLine($label, RED . "bad GIF" . RESET);
            usleep(500000);
            continue;
        }
        logLine($label . "GIF", strlen($gif) . "B");
        $coords = vernuable_solve_bitcotask($gif);
        if (!$coords || !isset($coords["x"], $coords["y"])) {
            logLine($label, RED . "Vernuable fail" . RESET);
            continue;
        }
        $x = (int)$coords["x"];
        $y = (int)$coords["y"];
        $rz = (int)($j["refZone"] ?? $parsed["refZone"] ?? 0);
        if ($rz > 0 && $x < $rz) $x = $rz + 5;
        logLine($label . "XY", "$x,$y");
        usleep((2800 + mt_rand(400, 1200)) * 1000);

        $submitUrl = (strpos($parsed["submit_path"], "http") === 0)
            ? $parsed["submit_path"]
            : rtrim(BASE, "/") . $parsed["submit_path"];
        $body = $parsed["static_key"] . "=" . rawurlencode($parsed["static_val"])
            . "&" . $parsed["coords_key"] . "=" . rawurlencode(json_encode([$x, $y]));
        list($sc, $sraw) = httpRequest($submitUrl, "POST", $body, [
            "Content-Type: application/x-www-form-urlencoded; charset=UTF-8",
            "X-Requested-With: XMLHttpRequest",
            "Referer: $fwUrl",
            "Origin: " . BASE,
        ], $proxy, $jar);
        $sj = json_decode($sraw, true) ?: [];
        $sk = $parsed["success_key"] ?? null;
        $tk = $parsed["token_key"] ?? null;
        $skVal = ($sk && array_key_exists($sk, $sj)) ? $sj[$sk] : null;
        $tkVal = ($tk && !empty($sj[$tk])) ? $sj[$tk] : null;
        logLine($label . "Click", "HTTP $sc Succ=" . json_encode($skVal));
        if (!($skVal === true || $skVal === 1 || $skVal === "1" || $skVal === "true")) {
            logLine($label . "Raw", substr((string)$sraw, 0, 70));
            usleep(800000);
            continue;
        }
        $token = null;
        if ($tkVal && is_string($tkVal) && preg_match('/^[a-f0-9]{64}$/i', $tkVal)) {
            $token = $tkVal;
        }
        if (!$token) {
            logLine($label, RED . "no token_key" . RESET);
            continue;
        }
        logLine($label . "Tok", substr($token, 0, 24) . "...");
        logLine($label . "Val", ($sk ?: "?") . "=true + token");

        // Validate on firewall so session is clean
        usleep(900000);
        $vf = $parsed["validate_field"] ?? "UEjS";
        list($cv, $vraw) = httpRequest($fwUrl, "POST",
            "action=validate&" . $vf . "=" . rawurlencode($token),
            [
                "Content-Type: application/x-www-form-urlencoded; charset=UTF-8",
                "X-Requested-With: XMLHttpRequest",
                "Accept: application/json, text/javascript, */*; q=0.01",
                "Referer: $fwUrl",
                "Origin: " . BASE,
            ],
            $proxy, $jar
        );
        $vj = json_decode($vraw, true) ?: [];
        logLine($label . "FwVal", "HTTP $cv " . substr(preg_replace('/\s+/', ' ', (string)$vraw), 0, 60));
        return [
            "token" => $token,
            "success_key" => $sk ?: "",
            "success_val" => true,
            "validate_field" => $vf,
            "token_key" => $tk ?: "",
            "click_json" => $sj,
            "clear" => isset($vj["status"]) && in_array($vj["status"], ["success", "ok", 200, "200"], true),
        ];
    }
    logLine($label, RED . "fresh firewall captcha failed" . RESET);
    return null;
}


/**
 * Load every <script src> / .js / .mjs from a page (like browser).
 * Parse for captcha, getads, atxrN, sponsor/ad URLs, ajax actions.
 * Hit any external sponsor/bmcdn URLs found so notif/ads count.
 */
function loadPageScripts($html, $pageUrl, $proxy, $jar, $label = "JS") {
    $result = [
        "scripts" => 0,
        "atxr_key" => null,
        "getads" => false,
        "sponsors" => [],
        "hints" => [],
    ];
    if (!is_string($html) || $html === "") return $result;

    $urls = [];
    // <script src="...">
    if (preg_match_all('#<script[^>]+src=["\']([^"\']+)["\']#i', $html, $m)) {
        foreach ($m[1] as $u) $urls[] = $u;
    }
    // <link rel=modulepreload / preload as=script>
    if (preg_match_all('#<link[^>]+href=["\']([^"\']+\.(?:js|mjs)[^"\']*)["\']#i', $html, $m)) {
        foreach ($m[1] as $u) $urls[] = $u;
    }
    // inline references to /assets/*.js
    if (preg_match_all('#["\']((?:https?:)?//[^"\']+\.(?:js|mjs)(?:\?[^"\']*)?)["\']#i', $html, $m)) {
        foreach ($m[1] as $u) $urls[] = $u;
    }
    if (preg_match_all('#["\'](/assets[^"\']+\.(?:js|mjs)(?:\?[^"\']*)?)["\']#i', $html, $m)) {
        foreach ($m[1] as $u) $urls[] = $u;
    }
    if (preg_match_all('#["\'](/captcha2/[^"\']+\.js[^"\']*)["\']#i', $html, $m)) {
        foreach ($m[1] as $u) $urls[] = $u;
    }

    $abs = [];
    foreach ($urls as $u) {
        $u = html_entity_decode(trim($u));
        if ($u === "" || strpos($u, "data:") === 0) continue;
        if (strpos($u, "//") === 0) $u = "https:" . $u;
        elseif (strpos($u, "http") !== 0) {
            if (strpos($u, "/") === 0) $u = rtrim(BASE, "/") . $u;
            else $u = rtrim(BASE, "/") . "/" . $u;
        }
        $abs[$u] = true;
    }
    $abs = array_keys($abs);
    // quiet: scripts loaded without spam

    $loaded = 0;
    $bodies = [];
    foreach ($abs as $u) {
        // skip huge third-party analytics if needed — still load bitcotasks + bmcdn + captcha
        $isOurs = (stripos($u, "bitcotasks") !== false || stripos($u, "captcha2") !== false
            || stripos($u, "bmcdn") !== false || stripos($u, "bitmedia") !== false
            || stripos($u, "ofads") !== false || stripos($u, "ofw") !== false
            || stripos($u, "notif") !== false);
        if (!$isOurs && (stripos($u, "google") !== false || stripos($u, "facebook") !== false
            || stripos($u, "analytics") !== false)) {
            continue;
        }
        list($c, $body) = httpRequest($u, "GET", null, [
            "Accept: */*",
            "Referer: $pageUrl",
        ], $proxy, $jar);
        $len = is_string($body) ? strlen($body) : 0;
        if ($c >= 200 && $c < 400 && $len > 20) {
            $loaded++;
            $bodies[] = $body;
            $short = preg_replace('#https?://[^/]+#', '', $u);
            // quiet
        }
    }
    $result["scripts"] = $loaded;

    $all = implode("\n", $bodies) . "\n" . $html;

    // atxrN mapping
    if (preg_match('#getElementById\([\'"]atxrN[\'"]\)\.value\s*=\s*response\.([A-Za-z0-9_]+)#', $all, $m)) {
        $result["atxr_key"] = $m[1];
        // quiet
    }
    // getads reference
    if (stripos($all, "getads.php") !== false) {
        $result["getads"] = true;
        // quiet
    }
    // ajax actions
    foreach (["switch_cat", "init_transaction", "start_view", "proccessLead", "validate"] as $act) {
        if (stripos($all, $act) !== false) $result["hints"][] = $act;
    }
    if ($result["hints"]) {
        // quiet
    }

    // sponsor / ad / bmcdn URLs inside JS
    $sp = [];
    if (preg_match_all('#https?://(?:cdn\.bmcdn[0-9]*\.com|[^"\'\s]*notifads[^"\'\s]*|[^"\'\s]*bitmedia[^"\'\s]*)[^"\'\s]{0,80}#i', $all, $mm)) {
        foreach ($mm[0] as $u) $sp[$u] = true;
    }
    // openrtb / trl / confirm patterns
    if (preg_match_all('#https?://cdn\.bmcdn[0-9]*\.com/[a-z0-9/_\-?=&%.]+#i', $all, $mm)) {
        foreach ($mm[0] as $u) $sp[$u] = true;
    }
    $sp = array_keys($sp);
    $hit = 0;
    foreach ($sp as $u) {
        if (stripos($u, "{") !== false) continue; // template
        httpRequest($u, "GET", null, ["Referer: $pageUrl", "Accept: */*"], $proxy, $jar);
        $hit++;
        if ($hit >= 8) break;
    }
    if ($hit) {
        // quiet
        $result["sponsors"] = array_slice($sp, 0, $hit);
    }

    return $result;
}

function runAccount($acc, $claims = 5) {
    $name  = $acc["name"];
    $key   = $acc["key"];
    $sub   = $acc["sub_id"];
    $proxy = $acc["proxy"] ?? null;
    $jar   = configPath(HOST, "cookie_" . preg_replace("/[^a-z0-9]/i", "_", $name) . ".txt");
    @unlink($jar); // fresh PHPSESSID every run
    $claimsLabel = ($claims === 0) ? "UNLIMITED" : (string)$claims;

    logStart("▶  RUN · $name");
    logLine("Account", GREEN . $name . RESET);
    logLine("sub_id", $sub);
    logLine("Proxy", $proxy ? CYAN . substr($proxy, 0, 40) . RESET : GREY . "DIRECT" . RESET);
    logLine("Claims", YELLOW . $claimsLabel . RESET);
    // HAR: notif cookies from the START of the run (kept in jar with PHPSESSID)
    $n0 = injectNotifCookies($jar);
    if (false) logLine("NotifStart", substr($n0["_bitco_notifad"], 0, 22) . "...");
    // HAR: getads only AFTER offerwall (empty before session)

    $vkey = vernuable_key();
    if ($vkey === "") {
        logLine("Error", RED . "Vernuable API key empty" . RESET);
        logEnd();
        return;
    }
    $bal = vernuable_balance();
    logLine("Vernuable", $bal !== null ? GREEN . "$" . number_format($bal, 5) . RESET : RED . "n/a" . RESET);
    if ($bal !== null && $bal <= 0) {
        logLine("Error", RED . "ZERO_BALANCE" . RESET);
        logEnd();
        return;
    }

    $fwUrl = BASE . "/firewall.php?key=" . urlencode($key) . "&sub_id=" . urlencode($sub);
    list($code, $html) = httpRequest($fwUrl, "GET", null, [], $proxy, $jar);
    if ($code >= 400 || $html === false || $html === "") {
        logLine("Firewall", RED . "HTTP $code" . RESET);
        logEnd();
        return;
    }
    loadPageScripts($html, $fwUrl, $proxy, $jar, "FwJS");

    $capUrl = null;
    $offerPath = null;
    $offerToken = null;
    $fwCaptchaToken = "";
    $fwCaptchaSuccKey = "";
    $fwCaptchaValField = "UEjS";
    $fwAtxrN = "";
    $fwAtxrCandidates = [];

    if (preg_match('#(/captcha2/[a-f0-9]{32,}\.js)\?action=captcha#i', $html, $m)) {
        $capUrl = BASE . $m[1] . "?action=captcha";
    } elseif (preg_match('#captcha2/([a-f0-9]{32,})\.js#i', $html, $m)) {
        $capUrl = BASE . "/captcha2/" . $m[1] . ".js?action=captcha";
    }

    if ($capUrl) {
        logLine("Firewall", YELLOW . "captcha required" . RESET);

        // GET captcha JS (session-specific submit path + field names)
        $jsUrl = explode("?", $capUrl)[0] . "?action=captcha";
        list($jc, $jsBody) = httpRequest($jsUrl, "GET", null, [
            "Accept: */*",
            "Referer: $fwUrl",
        ], $proxy, $jar);
        $parsed = parseCaptchaJs($jsBody);
        if (false) logLine("JS", $jc === 200 ? GREEN . "OK" . RESET : RED . "HTTP $jc" . RESET);
        if (false) logLine("SubmitPath", $parsed["submit_path"]
            ? GREY . substr($parsed["submit_path"], 0, 42) . RESET
            : RED . "NOT FOUND in JS" . RESET);
        if (false) logLine("ValField", (string)($parsed["validate_field"] ?? "UEjS"));
            if (false) logLine("Fields", ($parsed["static_key"] ?: "?") . "=" . ($parsed["static_val"] ?: "?")
            . " & " . ($parsed["coords_key"] ?: "?") . "=[x,y]");

        if (!$parsed["submit_path"] || !$parsed["coords_key"]) {
            logLine("Error", RED . "cannot parse captcha JS submit format" . RESET);
            logEnd();
            return;
        }

        $solved = null;
        $meta = [];
        $maxTries = 4;

        for ($try = 1; $try <= $maxTries; $try++) {
            list($gif, $j, $err) = fetchCaptchaChallenge($capUrl, $proxy, $jar);
            if ($err || !isValidGif($gif)) {
                logLine("Fetch#$try", RED . ($err ?: ("bad GIF " . ($gif ? strlen($gif) : 0) . "B")) . RESET);
                sleep(2);
                continue;
            }
            $meta = $j;
            $refZone = (int)($j["refZone"] ?? 0);
            $w = (int)($j["w"] ?? 0);
            $h = (int)($j["h"] ?? 0);
            logLine("Fetch#$try", GREEN . strlen($gif) . "B" . RESET . " w=$w h=$h refZone=$refZone");

            if (false) logLine("Solve", YELLOW . "Vernuable bitcotask…" . RESET);
            $solved = vernuable_solve_bitcotask($gif, 180, 2);
            if ($solved && isset($solved["x"])) {
                $x = (int)round($solved["x"]);
                $y = (int)round($solved["y"]);
                // HAR: clicks with x < refZone are rejected (green icon zone)
                if ($refZone > 0 && $x < $refZone) {
                    logLine("Solve#$try", YELLOW . "$x,$y in refZone — retry" . RESET);
                    $solved = null;
                    sleep(1);
                    continue;
                }
                if (false) logLine("Solved", GREEN . "$x,$y" . RESET);
                break;
            }
            $detail = is_array($solved) && isset($solved["error"]) ? $solved["error"] : "fail";
            logLine("Solve#$try", RED . substr($detail, 0, 40) . RESET);
            sleep(2);
            $solved = null;
        }

        if (!$solved || !isset($solved["x"])) {
            logLine("Error", RED . "all solve tries failed" . RESET);
            logEnd();
            return;
        }

        $x = (int)round($solved["x"]);
        $y = (int)round($solved["y"]);

        // Exact HAR submit
        $submitUrl = (strpos($parsed["submit_path"], "http") === 0)
            ? $parsed["submit_path"]
            : rtrim(BASE, "/") . $parsed["submit_path"];
        $coordsJson = json_encode([$x, $y]);
        $body = $parsed["static_key"] . "=" . $parsed["static_val"]
            . "&" . $parsed["coords_key"] . "=" . rawurlencode($coordsJson);

        if (false) logLine("Submit", YELLOW . "POST action=data" . RESET);
        list($sc, $sraw) = httpRequest($submitUrl, "POST", $body, [
            "Content-Type: application/x-www-form-urlencoded",
            "X-Requested-With: XMLHttpRequest",
            "Referer: $fwUrl",
        ], $proxy, $jar);
        $sj = json_decode($sraw, true) ?: [];
        if (false) logLine("ClickHTTP", (string)$sc);
        if (false) logLine("ClickBody", substr($body, 0, 42));

        // HAR STRICT: only accept if response[success_key] is truthy (e.g. ZiFLv=true)
        // HAR STRICT: token ONLY from response[token_key] (e.g. ecJQSy) — never random hex
        $ok = false;
        $token = null;
        $sk = $parsed["success_key"] ?? null;
        $tk = $parsed["token_key"] ?? null;
        $skVal = ($sk && array_key_exists($sk, $sj)) ? $sj[$sk] : null;
        $tkVal = ($tk && !empty($sj[$tk])) ? $sj[$tk] : null;

        if (false) logLine("SuccKey", ($sk ?: "?") . "=" . json_encode($skVal));
        if (false) logLine("TokKey", ($tk ?: "?") . "=" . ($tkVal ? substr((string)$tkVal, 0, 20) . "..." : "null"));
        if (false) logLine("ClickRaw", substr((string)$sraw, 0, 65));

        if ($sk && ($skVal === true || $skVal === 1 || $skVal === "1" || $skVal === "true")) {
            $ok = true;
            if ($tkVal && is_string($tkVal) && preg_match('/^[a-f0-9]{64}$/i', $tkVal)) {
                $token = $tkVal;
            }
        }

        if (!$ok) {
            logLine("Click", RED . "rejected (success_key not true)" . RESET);
            logLine("Hint", "coords likely wrong — Vernuable retry next loop");
            logEnd();
            return;
        }
        if (!$token) {
            logLine("Click", RED . "accepted flag but no token_key value" . RESET);
            logEnd();
            return;
        }
        logLine("Click", GREEN . "OK (strict)" . RESET);
        if (false) logLine("Token", substr($token, 0, 24) . "...");
        $fwCaptchaToken = $token; // captcha token (NOT offerwall path)
        $fwCaptchaSuccKey = $sk;
        $fwCaptchaValField = $parsed["validate_field"] ?? "UEjS";
        // Friend tip: collect ALL 64-hex from click response for PTC atxrN
        $fwAtxrCandidates = [];
        if (is_array($sj)) {
            foreach ($sj as $k => $v) {
                if (is_string($v) && preg_match('/^[a-f0-9]{64}$/i', $v)) {
                    $fwAtxrCandidates[$k] = $v;
                }
            }
        }
        // prefer non-token hex as atxr
        $fwAtxrN = "";
        foreach ($fwAtxrCandidates as $k => $v) {
            if ($v === $token) continue;
            $fwAtxrN = $v;
            break;
        }
        if ($fwAtxrN === "" && $token) $fwAtxrN = $token;
        if (false) logLine("FwTokenSaved", substr($fwCaptchaToken, 0, 24) . "...");
        if (false) logLine("FwAtxrSaved", $fwAtxrN ? substr($fwAtxrN, 0, 24) . "..." : "none");

        // HAR validate: action=validate&UEjS={token}
        // Must: same PHPSESSID jar, X-Requested-With, charset=UTF-8, Accept JSON
        $valHeaders = [
            "Content-Type: application/x-www-form-urlencoded; charset=UTF-8",
            "X-Requested-With: XMLHttpRequest",
            "Accept: application/json, text/javascript, */*; q=0.01",
            "Referer: $fwUrl",
            "Origin: " . BASE,
        ];
        // HAR: only the token_key value is valid for UEjS — do not spray random hex
        $candidates = [$token];
        usleep(2500000); // 2.5s — avoid Please take your time guard between click and validate
        $offerPath = null;
        $vj = [];
        $vraw = "";
        $cv = 0;
        foreach ($candidates as $ti => $tryTok) {
            list($cv, $vraw) = httpRequest($fwUrl, "POST",
                "action=validate&" . ($parsed["validate_field"] ?? "UEjS") . "=" . rawurlencode($tryTok),
                $valHeaders,
                $proxy, $jar
            );
            $vj = json_decode($vraw, true) ?: [];
            if (false) logLine("ValField", (string)($parsed["validate_field"] ?? "UEjS"));
            if (false) logLine("ValTry#" . ($ti+1), "HTTP $cv tok=" . substr($tryTok, 0, 18) . "...");
            if (false) logLine("ValBody#" . ($ti+1), substr((string)$vraw, 0, 70));
            $offerPath = $vj["redirect"] ?? $vj["url"] ?? null;
            if (!$offerPath && preg_match('#(/offerwall/[^"\'\s]+)#i', (string)$vraw, $om)) {
                $offerPath = $om[1];
            }
            if ($offerPath) break;
            // only try first 3 tokens to avoid burning
            if ($ti >= 2) break;
        }
        logLine("Validate", $offerPath ? GREEN . "OK → " . substr($offerPath, 0, 40) . RESET : RED . "FAIL" . RESET);
        if (!$offerPath) {
            logLine("ValFull", substr((string)$vraw, 0, 70));
            logLine("ValJSON", substr(json_encode($vj), 0, 70));
            // show if cookie jar has PHPSESSID
            $cj = @file_get_contents($jar);
            logLine("CookieJar", $cj && strpos($cj, "PHPSESSID") !== false ? GREEN . "PHPSESSID ok" . RESET : RED . "no PHPSESSID" . RESET);
        }

        if (!$offerPath) {
            logLine("Error", RED . "no offerwall redirect" . RESET);
            logEnd();
            return;
        }
        $parts = explode("/", rtrim($offerPath, "/"));
        $offerToken = end($parts);
    } else {
        if (preg_match('#(/offerwall/[^"\'\\s]+)#i', $html, $om)) {
            $offerPath = $om[1];
            $parts = explode("/", rtrim($offerPath, "/"));
            $offerToken = end($parts);
            logLine("Firewall", GREEN . "already clear" . RESET);
        } else {
            logLine("Error", RED . "no captcha / no offerwall" . RESET);
            logEnd();
            return;
        }
    }

    $offerUrl = (strpos($offerPath, "http") === 0)
        ? $offerPath
        : rtrim(BASE, "/") . "/" . ltrim($offerPath, "/");

    list($co, $ohtml) = httpRequest($offerUrl, "GET", null, [], $proxy, $jar);
    if (preg_match('/token["\']?\s*[:=]\s*["\']([a-f0-9]{32,})["\']/i', $ohtml, $tm)) {
        $offerToken = $tm[1];
    }
    logLine("Offerwall", GREY . substr($offerUrl, 0, 42) . RESET);
    if (!empty($ohtml)) {
        $owJs = loadPageScripts($ohtml, $offerUrl, $proxy, $jar, "OwJS");
    }
    // HAR: getads.php after offerwall + scripts (notifads + bmcdn)
    injectNotifCookies($jar);
    fetchGetAds($proxy, $jar, $offerUrl);
    logEnd();

    $okN = 0;
    $fail = 0;
    $i = 0;
    $emptyStreak = 0;

    while (true) {
        $i++;
        if ($claims > 0 && $i > $claims) break;

        list($cs, $sraw) = httpRequest($offerUrl, "POST", [
            "token"  => $offerToken,
            "action" => "switch_cat",
            "type"   => "ptc",
        ], ["X-Requested-With: XMLHttpRequest"], $proxy, $jar);
        $sj = json_decode($sraw, true) ?: [];
        $items = $sj["items"] ?? $sj["data"] ?? [];

        if (empty($items) || !is_array($items)) {
            $emptyStreak++;
            logStart("PTC");
            logLine("Items", YELLOW . "none (streak $emptyStreak)" . RESET);
            logEnd();
            if ($emptyStreak >= 3) break;
            sleep(5);
            continue;
        }
        $emptyStreak = 0;

        usort($items, function ($a, $b) {
            return (float)($b["reward"] ?? 0) <=> (float)($a["reward"] ?? 0);
        });
        $item = null;
        foreach ($items as $it) {
            if ((float)($it["duration"] ?? 99) <= 25) { $item = $it; break; }
        }
        if (!$item) $item = $items[0];

        $label = ($claims === 0) ? "#$i ∞" : "#$i/$claims";
        logStart("CLAIM $label");
        logLine("Reward", YELLOW . ($item["reward"] ?? "?") . RESET);
        logLine("Duration", ($item["duration"] ?? "?") . "s");

        list($ci, $iraw) = httpRequest($offerUrl, "POST", [
            "token"  => $offerToken,
            "action" => "init_transaction",
            "hash"   => $item["hash"] ?? "",
            "sid"    => $item["sid"] ?? $sub,
            "key"    => $item["key"] ?? $key,
            "type"   => $item["type"] ?? "ptc",
        ], ["X-Requested-With: XMLHttpRequest"], $proxy, $jar);
        $ij = json_decode($iraw, true) ?: [];
        $lead = $ij["offer"] ?? $ij["url"] ?? $ij["link"] ?? "";
        if (!$lead) {
            $fail++;
            logLine("Init", RED . substr((string)$iraw, 0, 40) . RESET);
            logEnd();
            if ($fail >= 8) break;
            sleep(3);
            continue;
        }
        if (strpos($lead, "http") !== 0) {
            $lead = rtrim(BASE, "/") . "/" . ltrim($lead, "/");
        }

        // HAR: fresh notif cookies EVERY lead — inject into jar (keep PHPSESSID)
        $notif = injectNotifCookies($jar);
        if (false) logLine("Notif", substr($notif["_bitco_notifad"], 0, 22) . "...");
        fetchGetAds($proxy, $jar, $offerUrl);

        // HAR: GET lead page first (load ad), then start_view
        list($cg, $ghtml) = httpRequest($lead, "GET", null, [
            "Referer: $offerUrl",
            "Accept: text/html,application/xhtml+xml",
        ], $proxy, $jar);
        if (false) logLine("LeadGET", "HTTP $cg");
        $leadJsInfo = ["atxr_key" => null];
        $leadCtoken = ""; // DYNAMIC processLead field name (NOT atxrN!)
        if (is_string($ghtml) && $ghtml !== "") {
            $leadJsInfo = loadPageScripts($ghtml, $lead, $proxy, $jar, "LeadJS");
            // HAR: var ctoken = "BsNQL";  payload[ctoken] = captchaID;
            if (preg_match('/var\s+ctoken\s*=\s*["\']([A-Za-z0-9_]+)["\']/', $ghtml, $cm)) {
                $leadCtoken = $cm[1];
                if (false) logLine("LeadCtoken", $leadCtoken);
            } elseif (preg_match('/ctoken\s*=\s*["\']([A-Za-z0-9_]+)["\']/', $ghtml, $cm)) {
                $leadCtoken = $cm[1];
                if (false) logLine("LeadCtoken", $leadCtoken);
            }
        }
        // Hit notif / ad / tracking URLs found on lead page (HAR: browser loads these)
        if (is_string($ghtml)) {
            $hit = 0;
            if (preg_match_all('#(?:src|href|data-url|data-src)=["\'](https?://[^"\']+(?:notif|notification|ad_|pixel|track|beacon)[^"\']*)["\']#i', $ghtml, $um)) {
                foreach (array_unique($um[1]) as $u) {
                    if (stripos($u, "bitcotasks") === false && stripos($u, "captcha") !== false) continue;
                    httpRequest($u, "GET", null, ["Referer: $lead"], $proxy, $jar);
                    $hit++;
                    if ($hit >= 5) break;
                }
            }
            // also any iframe src on bitcotasks or external ad
            if (preg_match_all('#<iframe[^>]+src=["\'](https?://[^"\']+)["\']#i', $ghtml, $im)) {
                foreach (array_unique($im[1]) as $u) {
                    if (stripos($u, "captcha") !== false) continue;
                    httpRequest($u, "GET", null, ["Referer: $lead"], $proxy, $jar);
                    $hit++;
                    if ($hit >= 8) break;
                }
            }
            if ($hit > 0) logLine("NotifURL", "hit $hit resource(s)");
        }
        // Lead captcha: try solve; friend tip — if fail, still use FIREWALL token/atxr in PTC
        $leadAtxr = "";
        $leadAtxrCands = [];
        if (is_string($ghtml) && (stripos($ghtml, "captcha2") !== false || stripos($ghtml, "action=captcha") !== false)) {
            logLine("LeadCap", YELLOW . "solving…" . RESET);
            // inject atxr_key from loaded page JS into HTML so solver can find it
            if (!empty($leadJsInfo["atxr_key"]) && is_string($ghtml)) {
                $ghtml .= "\n<script>document.getElementById('atxrN').value = response." . $leadJsInfo["atxr_key"] . ";</script>";
                logLine("LeadJSAtxr", $leadJsInfo["atxr_key"]);
            }
            $capRes = solvePageCaptcha($lead, $ghtml, $proxy, $jar, "LeadCap", 1);
            $leadAtxrCands = [];
            if (!empty($capRes["ok"])) {
                $leadAtxr = $capRes["atxrN"] ?? "";
                $leadAtxrCands = $capRes["atxrCandidates"] ?? [];
                if (false) logLine("LeadCap", GREEN . "cleared" . RESET);
                if ($leadAtxr !== "") if (false) logLine("LeadAtxr", substr($leadAtxr, 0, 24) . "...");
                if (!empty($capRes["atxrKey"])) if (false) logLine("LeadAtxrKey", $capRes["atxrKey"]);
                if (false) logLine("LeadAtxrN", count($leadAtxrCands) . " candidate(s)");
            } else {
                // Full A→Z retry: new notif + fresh lead GET + JS load + captcha
                logLine("LeadCap", YELLOW . "retry (full reload)…" . RESET);
                sleep(2);

                // fresh notif cookies
                injectNotifCookies($jar);
                fetchGetAds($proxy, $jar, $offerUrl);

                // cache-bust lead URL so server issues NEW captcha session
                $leadRetry = $lead . (strpos($lead, "?") !== false ? "&" : "?") . "_r=" . mt_rand(100000, 999999);
                list($cg2, $ghtml2) = httpRequest($leadRetry, "GET", null, [
                    "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
                    "Referer: $offerUrl",
                    "Cache-Control: no-cache",
                    "Pragma: no-cache",
                ], $proxy, $jar);

                if (!is_string($ghtml2) || $ghtml2 === "") {
                    logLine("LeadCap", RED . "failed — skip" . RESET);
                    logEnd();
                    $fail++;
                    sleep(2);
                    continue;
                }

                $ghtml = $ghtml2;
                // full JS load (same as first attempt)
                $leadJsInfo = loadPageScripts($ghtml, $lead, $proxy, $jar, "LeadJS");
                $leadCtoken = "";
                if (preg_match('/var\s+ctoken\s*=\s*["\']([A-Za-z0-9_]+)["\']/', $ghtml, $cm)) {
                    $leadCtoken = $cm[1];
                }

                $capRes = solvePageCaptcha($lead, $ghtml, $proxy, $jar, "LeadCap", 1);
                if (!empty($capRes["ok"])) {
                    $leadAtxr = $capRes["atxrN"] ?? "";
                    $leadAtxrCands = $capRes["atxrCandidates"] ?? [];
                    if (!empty($capRes["token"])) $leadAtxr = $capRes["token"];
                    logLine("LeadCap", GREEN . "OK (retry)" . RESET);
                } else {
                    logLine("LeadCap", RED . "failed — skip" . RESET);
                    logEnd();
                    $fail++;
                    sleep(2);
                    continue;
                }
            }
        }




        list($cs2, $svraw) = httpRequest($lead, "POST", ["action" => "start_view"], [
            "X-Requested-With: XMLHttpRequest",
            "Referer: $lead",
            "Origin: " . BASE,
            "Accept: application/json, text/javascript, */*; q=0.01",
        ], $proxy, $jar);
        logLine("StartView", $cs2 === 200 ? GREEN . "ok" . RESET : RED . "fail" . RESET);

        $wait = max((float)($item["duration"] ?? 2), 2) + 1.5 + (mt_rand(2, 8) / 10);
        logLine("View", YELLOW . round($wait, 1) . "s" . RESET);
        usleep((int)($wait * 1000000));

        $tokenLead = basename(parse_url($lead, PHP_URL_PATH) ?: rtrim($lead, "/"));
        // HAR SUCCESS: processLead uses DYNAMIC field name ctoken (BsNQL/HwbMX/...), NOT atxrN
        //   payload[ctoken] = captchaID  (= captcha response TOKEN_KEY value)
        $fieldName = $leadCtoken ?: "atxrN";
        $fieldVal  = $leadAtxr ?: "";
        // PtcField logged only inside RESULT on fail
        if (false) logLine("PtcTok", "token=" . substr($tokenLead, 0, 20) . "... hash=" . substr((string)($item["hash"] ?? ""), 0, 12));

        $payload = [
            "hash"   => $item["hash"] ?? "",
            "sub_id" => $item["sid"] ?? $sub,
            "key"    => $item["key"] ?? $key,
            "token"  => $tokenLead,
            "action" => "proccessLead",
        ];
        if ($fieldVal !== "") {
            $payload[$fieldName] = $fieldVal;
        }

        list($cp, $praw) = httpRequest(BASE . "/system/ajax.php", "POST", $payload, [
            "X-Requested-With: XMLHttpRequest",
            "Referer: $lead",
            "Origin: " . BASE,
            "Accept: application/json, text/javascript, */*; q=0.01",
            "Content-Type: application/x-www-form-urlencoded; charset=UTF-8",
        ], $proxy, $jar);
        $pj = json_decode($praw, true) ?: [];
        $success = (isset($pj["status"]) && (int)$pj["status"] === 200)
            || stripos((string)($pj["message"] ?? ""), "SUCCESS") !== false
            || stripos((string)($pj["msg"] ?? ""), "SUCCESS") !== false;

        // If captcha error and we have more token candidates, retry with same field name
        if (!$success && !empty($leadAtxrCands) && stripos((string)($pj["message"] ?? $praw), "Captcha") !== false) {
            $tried = [$fieldVal];
            $n = 0;
            foreach ($leadAtxrCands as $k => $v) {
                if (!is_string($v) || in_array($v, $tried, true)) continue;
                $n++;
                if ($n > 5) break;
                logLine("PtcRetry", "#$n $fieldName=" . substr($v, 0, 20) . "...");
                $payload[$fieldName] = $v;
                list($cp, $praw) = httpRequest(BASE . "/system/ajax.php", "POST", $payload, [
                    "X-Requested-With: XMLHttpRequest",
                    "Referer: $lead",
                    "Origin: " . BASE,
                    "Accept: application/json, text/javascript, */*; q=0.01",
                    "Content-Type: application/x-www-form-urlencoded; charset=UTF-8",
                ], $proxy, $jar);
                $pj = json_decode($praw, true) ?: [];
                $success = (isset($pj["status"]) && (int)$pj["status"] === 200)
                    || stripos((string)($pj["message"] ?? ""), "SUCCESS") !== false;
                if ($success) break;
                if (stripos((string)($pj["message"] ?? ""), "Captcha") === false) break;
                usleep(300000);
            }
        }

        // Combined RESULT box (no separate STATUS)
        $redir = $pj["redirect"] ?? $pj["url"] ?? "";
        $msgPlain = trim(preg_replace('/\s+/', ' ', strip_tags((string)($pj["message"] ?? $pj["msg"] ?? ""))));
        if ($success) {

            $okN++;
            $fail = 0;
            logLine("Status", GREEN . "SUCCESS" . RESET);
            logLine("Reward", GREEN . "+" . ($item["reward"] ?? "?") . " tokens" . RESET);
            logLine("Total", GREEN . (string)$okN . " earned" . RESET);
            if ($redir) logLine("Redirect", GREY . substr($redir, 0, 55) . RESET);
            if ($msgPlain) logLine("Message", GREEN . substr($msgPlain, 0, 55) . RESET);
        } else {
            $fail++;
            logLine("Status", RED . "FAIL" . RESET);
            logLine("Detail", RED . substr($msgPlain ?: substr((string)$praw, 0, 50), 0, 60) . RESET);
            // re-check jar still has PHPSESSID + notif
            if (is_file($jar)) {
                $cj = file_get_contents($jar);
                $hasSess = strpos($cj, "PHPSESSID") !== false;
                $hasNotif = strpos($cj, "_bitco_notifad") !== false;
                // quiet cookies
            }
            if ($fail >= 8) {
                logLine("Stop", RED . "too many fails" . RESET);
                logEnd();
                break;
            }
        }
        logEnd();
        sleep(mt_rand(2, 5));
    }

    logStart("SUMMARY");
    logLine("Account", $name);
    logLine("OK", GREEN . $okN . RESET);
    logLine("Fail", RED . $fail . RESET);
    logLine("Mode", $claims === 0 ? YELLOW . "unlimited" . RESET : (string)$claims);
    logEnd();
}

while (true) {
    clearScreen();
    echo "\n";
    echo CYAN . "╔══════════════════════════════════════════════════════════╗\n" . RESET;
    echo CYAN . "║" . WHITE . BOLD . "  BitcoTasks.com · Vernuable bitcotask                   " . CYAN . "║\n" . RESET;
    echo CYAN . "╚══════════════════════════════════════════════════════════╝\n" . RESET;

    $accs = loadAccounts();
    echo "\n  Accounts: " . count($accs) . "\n";
    echo GREEN . "  [1]" . WHITE . "  Run all accounts\n" . RESET;
    echo GREEN . "  [2]" . WHITE . "  Run single account\n" . RESET;
    echo GREEN . "  [3]" . WHITE . "  Add account\n" . RESET;
    echo GREEN . "  [4]" . WHITE . "  List accounts\n" . RESET;
    echo GREEN . "  [0]" . WHITE . "  Back\n" . RESET;
    echo "\n" . YELLOW . "  › " . RESET;
    $c = trim(fgets(STDIN));

    if ($c === "0") exit(0);
    if ($c === "3") {
        addAccountInteractive();
        echo WHITE . "Press Enter..." . RESET;
        fgets(STDIN);
        continue;
    }
    if ($c === "4") {
        foreach ($accs as $i => $a) {
            echo "  " . ($i + 1) . ") " . $a["name"] . " sub=" . $a["sub_id"]
                . " proxy=" . ($a["proxy"] ?: "DIRECT") . "\n";
        }
        echo WHITE . "Press Enter..." . RESET;
        fgets(STDIN);
        continue;
    }
    if ($c === "1") {
        if (empty($accs)) {
            echo RED . "  No accounts — use [3] Add\n" . RESET;
            fgets(STDIN);
            continue;
        }
        $claims = askClaims();
        foreach ($accs as $a) runAccount($a, $claims);
        echo WHITE . "Press Enter..." . RESET;
        fgets(STDIN);
        continue;
    }
    if ($c === "2") {
        if (empty($accs)) {
            echo RED . "  No accounts — use [3] Add\n" . RESET;
            fgets(STDIN);
            continue;
        }
        foreach ($accs as $i => $a) {
            echo "  " . ($i + 1) . ") " . $a["name"] . "\n";
        }
        echo WHITE . "  #: " . RESET;
        $ix = (int)trim(fgets(STDIN)) - 1;
        if (!isset($accs[$ix])) continue;
        $claims = askClaims();
        runAccount($accs[$ix], $claims);
        echo WHITE . "Press Enter..." . RESET;
        fgets(STDIN);
        continue;
    }
}
