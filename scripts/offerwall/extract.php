<?php
/**
 * Vernuable Official Scripts · v0.0.0
 * Run once: php extract.php
 * Extracts bitcotasks.com.php into this folder.
 */
$b64File = __DIR__ . "/bitcotasks.com.php.b64.gz";
if (!is_file($b64File)) {
    fwrite(STDERR, "Missing bitcotasks.com.php.b64.gz\n");
    exit(1);
}
$raw = file_get_contents($b64File);
$data = gzdecode(base64_decode(preg_replace('/\s+/', '', $raw)));
if ($data === false || $data === "") {
    fwrite(STDERR, "Decode failed\n");
    exit(1);
}
$out = __DIR__ . "/bitcotasks.com.php";
file_put_contents($out, $data);
echo "OK v0.0.0 — wrote " . strlen($data) . " bytes → $out\n";
