<?php
$localConfigFile = __DIR__ . '/config.local.php';
$localConfig = [];

if (is_file($localConfigFile)) {
    $loaded = require $localConfigFile;
    if (is_array($loaded)) {
        $localConfig = $loaded;
    }
}

$openaiKey = $localConfig['OPENAI_API_KEY'] ?? '';
$adminPassword = $localConfig['ADMIN_PASSWORD'] ?? '';

if (!defined('OPENAI_API_KEY')) {
    define('OPENAI_API_KEY', $openaiKey);
}

if (!defined('ADMIN_PASSWORD')) {
    define('ADMIN_PASSWORD', $adminPassword);
}

function get_local_config_path(): string {
    return __DIR__ . '/config.local.php';
}

function needs_initial_setup(): bool {
    $configFile = get_local_config_path();
    if (!is_file($configFile)) {
        return true;
    }

    $data = require $configFile;
    if (!is_array($data)) {
        return true;
    }

    $apiKey = trim((string)($data['OPENAI_API_KEY'] ?? ''));
    $adminPassword = trim((string)($data['ADMIN_PASSWORD'] ?? ''));

    return ($apiKey === '' || $adminPassword === '');
}