#!/usr/bin/env php
<?php
/**
 * Vernuable Official Scripts — Main Launcher (Buxads-style)
 *
 * @author GLITCH083
 * @version 0.0.0
 *
 * php bot.php
 */

error_reporting(0);
ini_set("display_errors", 0);

define("BASE_DIR", __DIR__);
define("SCRIPTS_DIR", BASE_DIR . "/scripts");

require_once BASE_DIR . "/functions/function.php";
require_once BASE_DIR . "/functions/vernuable.php";

$tokenFile = BASE_DIR . "/github_token.txt";
$githubToken = file_exists($tokenFile) ? trim(file_get_contents($tokenFile)) : "";

define("GITHUB_TOKEN", $githubToken);
define("GITHUB_USERNAME", "GLITCH083");
define("GITHUB_REPO", "vernuable-official-scripts");
define("GITHUB_BRANCH", "main");
define("GITHUB_API_URL", "https://api.github.com/repos/" . GITHUB_USERNAME . "/" . GITHUB_REPO);
define("VERSION_FILE", "version.json");
define("TEMP_DIR", BASE_DIR . "/temp_update");
define("APP_HOST", "vernuable-Bot");

date_default_timezone_set("Asia/Karachi");
enableCtrlC();

function githubApiRequest($endpoint) {
    $url = GITHUB_API_URL . $endpoint;
    $ch = curl_init();
    $headers = [
        "Accept: application/vnd.github.v3+json",
        "User-Agent: VernuableOfficialScripts/1.0",
    ];
    if (GITHUB_TOKEN !== "") {
        $headers[] = "Authorization: token " . GITHUB_TOKEN;
    }
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (PHP_VERSION_ID < 80500) {
        @curl_close($ch);
    }
    if ($httpCode == 200 && $response) {
        return json_decode($response, true);
    }
    return null;
}

function githubDownloadFile($path) {
    $data = githubApiRequest("/contents/" . $path . "?ref=" . GITHUB_BRANCH);
    if ($data && isset($data["content"])) {
        return base64_decode($data["content"]);
    }
    $rawUrl = "https://raw.githubusercontent.com/" . GITHUB_USERNAME . "/" . GITHUB_REPO . "/" . GITHUB_BRANCH . "/" . $path;
    $ch = curl_init();
    $headers = ["User-Agent: VernuableOfficialScripts/1.0"];
    if (GITHUB_TOKEN !== "") {
        $headers[] = "Authorization: token " . GITHUB_TOKEN;
    }
    curl_setopt_array($ch, [
        CURLOPT_URL => $rawUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (PHP_VERSION_ID < 80500) {
        @curl_close($ch);
    }
    return ($code == 200 && $body !== false && $body !== "") ? $body : false;
}

function getRepoTreeFiles() {
    $data = githubApiRequest("/git/trees/" . GITHUB_BRANCH . "?recursive=1");
    $files = [];
    if ($data && isset($data["tree"]) && is_array($data["tree"])) {
        foreach ($data["tree"] as $item) {
            if (isset($item["type"], $item["path"]) && $item["type"] === "blob") {
                $files[] = $item["path"];
            }
        }
    }
    return $files;
}

function getCurrentVersion() {
    $f = BASE_DIR . "/" . VERSION_FILE;
    if (file_exists($f)) {
        $d = json_decode(file_get_contents($f), true);
        if ($d && isset($d["version"])) return $d;
    }
    return ["version" => "0.0.0", "whats_new" => []];
}

function fetchLatestVersion() {
    $c = githubDownloadFile(VERSION_FILE);
    if ($c) {
        $d = json_decode($c, true);
        if ($d && isset($d["version"])) return $d;
    }
    return null;
}

function deleteDirectory($dir) {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

function applyUpdate($latest) {
    echo "\n" . YELLOW . "Downloading update files...\n" . RESET;
    if (is_dir(TEMP_DIR)) deleteDirectory(TEMP_DIR);
    mkdir(TEMP_DIR, 0777, true);
    $tree = getRepoTreeFiles();
    if (empty($tree)) {
        $tree = ["bot.php", "version.json", "functions/function.php", "functions/vernuable.php", "scripts/offerwall/bitcotasks.com.php", "README.md"];
    }
    $n = 0;
    foreach ($tree as $file) {
        if (strpos($file, ".git") === 0) continue;
        if (substr($file, -4) === ".b64") continue;
        echo "  $file ... ";
        $content = githubDownloadFile($file);
        if ($content === false) { echo RED . "x\n" . RESET; continue; }
        $tmp = TEMP_DIR . "/" . $file;
        $dir = dirname($tmp);
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        file_put_contents($tmp, $content);
        echo GREEN . "ok\n" . RESET;
        $n++;
    }
    if ($n == 0) {
        echo RED . "No files downloaded.\n" . RESET;
        deleteDirectory(TEMP_DIR);
        return false;
    }
    $applied = 0;
    echo YELLOW . "Applying...\n" . RESET;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(TEMP_DIR, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($it as $item) {
        if (!$item->isFile()) continue;
        $rel = substr($item->getPathname(), strlen(TEMP_DIR) + 1);
        $dest = BASE_DIR . "/" . $rel;
        $dir = dirname($dest);
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        if (@rename($item->getPathname(), $dest) || @copy($item->getPathname(), $dest)) {
            echo "  updated $rel\n";
            $applied++;
        }
    }
    deleteDirectory(TEMP_DIR);
    echo GREEN . "Done ($applied files). Restart bot.\n" . RESET;
    return true;
}

function checkForUpdates() {
    echo "\n" . CYAN . "Checking for updates...\n" . RESET;
    $cur = getCurrentVersion();
    echo "  Current: " . $cur["version"] . "\n";
    $lat = fetchLatestVersion();
    if (!$lat) {
        echo RED . "Could not fetch version.json\n" . RESET;
        echo WHITE . "Press Enter..." . RESET; fgets(STDIN); return;
    }
    echo "  Latest:  " . $lat["version"] . "\n";
    if (version_compare($lat["version"], $cur["version"], ">")) {
        echo GREEN . "Update available!\n" . RESET;
        foreach ($lat["whats_new"] ?? [] as $n) echo "  - $n\n";
        echo WHITE . "Install? (y/n): " . RESET;
        $c = strtolower(trim(fgets(STDIN)));
        if ($c === "y" || $c === "yes") applyUpdate($lat);
    } else {
        echo GREEN . "Already latest (" . $cur["version"] . ")\n" . RESET;
    }
    echo WHITE . "Press Enter..." . RESET; fgets(STDIN);
}

function getScripts() {
    $out = [];
    if (!is_dir(SCRIPTS_DIR)) return $out;
    foreach (glob(SCRIPTS_DIR . "/*", GLOB_ONLYDIR) as $dir) {
        $cat = basename($dir);
        foreach (glob($dir . "/*.php") as $file) {
            $out[] = ["file" => $file, "basename" => basename($file), "category" => $cat,
                "description" => "BitcoTasks offerwall"];
        }
    }
    return $out;
}

function showBanner() {
    $v = getCurrentVersion();
    $bal = "?";
    try {
        $b = vernuable_balance();
        if ($b !== null) $bal = "$" . number_format($b, 5);
    } catch (Exception $e) {}
    echo "\n";
    echo CYAN . "╔══════════════════════════════════════════════════════════╗\n" . RESET;
    echo CYAN . "║" . WHITE . BOLD . "     VERNUABLE  ·  OFFICIAL SCRIPTS                      " . CYAN . "║\n" . RESET;
    echo CYAN . "║" . DIM . "     Buxads-style launcher · auto-update                  " . CYAN . "║\n" . RESET;
    echo CYAN . "╠══════════════════════════════════════════════════════════╣\n" . RESET;
    echo CYAN . "║" . WHITE . "  Version  : " . GREEN . $v["version"] . WHITE . str_repeat(" ", max(0, 40 - strlen($v["version"]))) . CYAN . "║\n" . RESET;
    echo CYAN . "║" . WHITE . "  Balance  : " . GREEN . $bal . WHITE . str_repeat(" ", max(0, 40 - strlen($bal))) . CYAN . "║\n" . RESET;
    echo CYAN . "╚══════════════════════════════════════════════════════════╝\n" . RESET;
}

function mainMenu() {
    while (true) {
        clearScreen();
        showBanner();
        $scripts = getScripts();
        $cats = [];
        foreach ($scripts as $s) $cats[$s["category"]] = true;
        $cats = array_keys($cats);
        echo "\n" . WHITE . BOLD . "  MAIN MENU\n" . RESET;
        echo GREY . "  ────────────────────────────────────────\n" . RESET;
        $i = 1; $map = [];
        foreach ($cats as $cat) {
            $cnt = count(array_filter($scripts, function ($s) use ($cat) { return $s["category"] === $cat; }));
            echo "  " . GREEN . "[$i]" . WHITE . "  " . ucwords($cat) . GREY . " ($cnt scripts)\n" . RESET;
            $map[$i] = $cat; $i++;
        }
        echo "  " . GREEN . "[$i]" . WHITE . "  Check for Updates\n" . RESET; $upd = $i; $i++;
        echo "  " . GREEN . "[$i]" . WHITE . "  Vernuable balance / set API key\n" . RESET; $bal = $i;
        echo "  " . GREEN . "[0]" . WHITE . "  Exit\n" . RESET;
        echo "\n" . YELLOW . "  › " . RESET;
        $choice = trim(fgets(STDIN));
        if ($choice === "0" || strtolower($choice) === "q") { echo "Bye.\n"; exit(0); }
        if ((int)$choice === $upd) { checkForUpdates(); continue; }
        if ((int)$choice === $bal) {
            clearScreen(); showBanner();
            $key = vernuable_key();
            $masked = strlen($key) > 10 ? substr($key, 0, 6) . "…" . substr($key, -4) : $key;
            echo "\n  API key: $masked\n";
            $b = vernuable_balance();
            echo $b !== null ? GREEN . "  Balance: $" . number_format($b, 5) . "\n" . RESET : RED . "  fail\n" . RESET;
            echo WHITE . "Press Enter..." . RESET; fgets(STDIN); continue;
        }
        $n = (int)$choice;
        if (!isset($map[$n])) continue;
        $cat = $map[$n];
        $list = array_values(array_filter($scripts, function ($s) use ($cat) { return $s["category"] === $cat; }));
        while (true) {
            clearScreen(); showBanner();
            echo "\n  " . strtoupper($cat) . "\n";
            foreach ($list as $j => $s) {
                echo "  " . GREEN . "[" . ($j+1) . "]" . WHITE . "  " . $s["basename"] . "\n" . RESET;
            }
            echo "  " . GREEN . "[0]" . WHITE . "  Back\n" . RESET;
            echo "\n" . YELLOW . "  › " . RESET;
            $c2 = trim(fgets(STDIN));
            if ($c2 === "0") break;
            $idx = (int)$c2 - 1;
            if ($idx < 0 || $idx >= count($list)) continue;
            passthru("php " . escapeshellarg($list[$idx]["file"]));
            echo WHITE . "Press Enter..." . RESET; fgets(STDIN);
        }
    }
}

mainMenu();
