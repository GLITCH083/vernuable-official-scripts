<?php
// Colors + helpers (Buxads-style)

define("RED", "\033[1;31;40m");
define("GREEN", "\033[1;32;40m");
define("YELLOW", "\033[1;33;40m");
define("BLUE", "\033[1;34;40m");
define("PURPLE", "\033[1;35;40m");
define("CYAN", "\033[1;36;40m");
define("GREY", "\033[1;30;40m");
define("WHITE", "\033[1;37m");
define("MAGENTA", "\033[35m");
define("RESET", "\033[0m");
define("BOLD", "\033[1m");
define("DIM", "\033[2m");

function enableCtrlC() {
    if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
        pcntl_async_signals(true);
        pcntl_signal(SIGINT, function () {
            echo "\n" . YELLOW . "⏹  Stopped by user (Ctrl+C)\n" . RESET;
            exit(0);
        });
        pcntl_signal(SIGTERM, function () {
            echo "\n" . YELLOW . "⏹  Terminated\n" . RESET;
            exit(0);
        });
    }
}

function clearScreen() {
    if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
        system('cls');
    } else {
        system('clear');
    }
}

/**
 * Buxads-style: configs/{folder}-config/{filename}
 * First run prompts and saves. No accounts.example.json needed.
 */
function saveData($folder, $filename) {
    $base = dirname(__DIR__) . "/configs/{$folder}-config";
    if (!is_dir($base)) {
        mkdir($base, 0777, true);
    }
    $path = $base . "/" . $filename;
    if (file_exists($path)) {
        return trim(file_get_contents($path));
    }
    echo WHITE . "Input $filename: " . RESET;
    $data = trim(fgets(STDIN));
    file_put_contents($path, $data);
    return $data;
}

function configPath($folder, $filename) {
    $base = dirname(__DIR__) . "/configs/{$folder}-config";
    if (!is_dir($base)) {
        mkdir($base, 0777, true);
    }
    return $base . "/" . $filename;
}

function parseProxyString($proxy) {
    if (empty($proxy)) {
        return null;
    }
    $proxy = trim($proxy);
    // host:port:user:pass
    $parts = explode(':', $proxy);
    if (count($parts) === 4) {
        return "http://{$parts[2]}:{$parts[3]}@{$parts[0]}:{$parts[1]}";
    }
    if (count($parts) === 2) {
        return "http://{$proxy}";
    }
    if (preg_match('#^https?://#i', $proxy) || preg_match('#^socks#i', $proxy)) {
        return $proxy;
    }
    return "http://" . $proxy;
}

function theme_box_w() {
    return 61;
}

function theme_plain($s) {
    return preg_replace('/\033\[[0-9;]*m/', '', (string)$s);
}

function themeOpen($title) {
    $w = theme_box_w();
    echo "\n";
    echo CYAN . "┌" . str_repeat("─", $w) . "┐\n" . RESET;
    $plain = theme_plain($title);
    $pad = max(1, $w - 2 - strlen($plain));
    echo CYAN . "│" . RESET . WHITE . "  " . $title . str_repeat(" ", max(0, $pad - 2)) . CYAN . "│\n" . RESET;
    echo CYAN . "├" . str_repeat("─", $w) . "┤\n" . RESET;
}

function themeRow($label, $value) {
    $w = theme_box_w();
    $plainV = theme_plain($value);
    if (strlen($plainV) > 42) {
        $plainV = substr($plainV, 0, 39) . "...";
        $value = $plainV;
    }
    $padV = max(1, $w - 16 - strlen($plainV));
    echo CYAN . "│" . RESET . WHITE . "  " . str_pad((string)$label, 12) . ": " . $value . str_repeat(" ", $padV) . CYAN . "│\n" . RESET;
}

function themeClose() {
    echo CYAN . "└" . str_repeat("─", theme_box_w()) . "┘\n" . RESET;
}

function themeStatus($title, $rows) {
    themeOpen($title);
    foreach ($rows as $label => $value) {
        themeRow($label, $value);
    }
    themeClose();
}

function themeBox($title, $rows = []) {
    themeStatus($title, $rows);
}

function logFail($title, $detail = "") {
    themeStatus("❌  " . $title, [
        "Status" => RED . "Failed" . RESET,
        "Detail" => $detail ?: "-",
    ]);
}

function logWait($msg, $seconds = 0) {
    $rows = ["Action" => YELLOW . $msg . RESET];
    if ($seconds > 0) {
        $rows["Wait"] = WHITE . $seconds . "s" . RESET;
    }
    themeStatus("⏳  WAITING", $rows);
}
