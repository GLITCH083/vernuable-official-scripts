<?php
/**
 * Vernuable Official Scripts · v0.0.0
 * Run once: php extract.php
 */
$dir = __DIR__;
$parts = ["$dir/bot.part1.b64", "$dir/bot.part2.b64"];
$b64 = "";
foreach ($parts as $p) {
    if (!is_file($p)) { fwrite(STDERR, "Missing $p\n"); exit(1); }
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
