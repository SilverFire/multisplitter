#!/usr/bin/env php
<?php

$lockFile = __DIR__ . '/update.lock';
if (!is_file($lockFile)) {
    file_put_contents($lockFile, '0');
}
$fp = fopen($lockFile, 'r+');
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    die("Unable to obtain lock for update.lock.");
}

if (isset($argv[1]) && strpos($argv[1], '--tag=') === 0) {
    $tag = substr($argv[1], 6);
    echo "Synchronizing tag '$tag'? [y/n] ";
    if (strncasecmp(fgets(STDIN), 'y', 1) !== 0) {
        echo "no change is made.\n";
        exit();
    }
}

require(__DIR__ . '/Subsplit.php');
$config = require __DIR__ . '/config.php';

$root = __DIR__;
$githubToken = $config['token'];
$git = $config['git'] ?? 'git';
$branches = $config['branches'] ?? [];
$subsplits = $config['subsplits'] ?? [];

try {
    $subsplit = new Subsplit(
        $root,
        $branches,
        $subsplits,
        $githubToken,
        $git
    );
    $subsplit->update($tag ?? null);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

flock($fp, LOCK_UN);
fclose($fp);
