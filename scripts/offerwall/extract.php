<?php
/**
 * Vernuable Official Scripts · v0.0.0
 * Run once: php extract.php
 */
$dir = __DIR__;
$parts = glob("$dir/bot.part*.b64");
sort($parts);
if (count($parts) < 1) { fwrite(STDERR, "No bot.part*.b64\n"); exit(1); }
$b64 = "";
foreach ($parts as $p) {
    $b64 .= preg_replace('/\s+/', '', file_get_contents($p));
}
$data = gzdecode(base64_decode($b64));
if ($data === false || $data === "") {
    fwrite(STDERR, "Decode failed\n");
    exit(1);
}
$out = "$dir/bitcotasks.com.php";
file_put_contents($out, $data);
echo "OK v0.0.0 — wrote " . strlen($data) . " bytes → $out\n";
