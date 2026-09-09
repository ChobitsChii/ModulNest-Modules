#!/usr/bin/env php
<?php

declare(strict_types=1);

const TRUSTED_KEYS = [
    'modulnest-root-2026-01' => 'Dpp7dUPvFHaGxC3csJ+g/SFLxbdgQMQSI5GgbPE/jm4=',
    'modulnest-release-2026-01' => 'YHRqRTiqkFxGec/mM7qkYBn9r1NEiMrRGBO+zNBlnPA=',
];
const ROOT_KEY_ID = 'modulnest-root-2026-01';

function fail(string $message): never
{
    fwrite(STDERR, "Mirror sync failed: {$message}\n");
    exit(1);
}

function fetchBytes(string $url, int $limit): string
{
    $context = stream_context_create(['http' => ['timeout' => 30, 'follow_location' => 0, 'user_agent' => 'ModulNest-Repository-Mirror/1.0']]);
    $handle = @fopen($url, 'rb', false, $context);
    if (!is_resource($handle)) fail('source is unavailable');
    try {
        $bytes = stream_get_contents($handle, $limit + 1);
    } finally {
        fclose($handle);
    }
    if (!is_string($bytes) || strlen($bytes) > $limit) fail('source exceeds size limit');
    return $bytes;
}

function relativePath(string $path): string
{
    if ($path === '' || str_starts_with($path, '/') || str_contains(str_replace('\\', '/', $path), '../') || preg_match('#^[a-z]+:#i', $path)) {
        fail('unsafe catalog path');
    }
    return $path;
}

function writeFile(string $root, string $relative, string $bytes): void
{
    $path = $root . '/' . relativePath($relative);
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) fail('staging directory cannot be created');
    if (file_put_contents($path, $bytes, LOCK_EX) === false) fail('staging file cannot be written');
}

function verifyEd25519(string $signature, string $bytes, string $public): bool
{
    if (strlen($signature) !== 64 || strlen($public) !== 32) return false;
    $prefix = sys_get_temp_dir() . '/modulnest-ed25519-' . bin2hex(random_bytes(6));
    $publicPath = $prefix . '.pub';
    $signaturePath = $prefix . '.sig';
    $payloadPath = $prefix . '.data';
    $der = hex2bin('302a300506032b6570032100') . $public;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    file_put_contents($publicPath, $pem, LOCK_EX);
    file_put_contents($signaturePath, $signature, LOCK_EX);
    file_put_contents($payloadPath, $bytes, LOCK_EX);
    exec(implode(' ', array_map('escapeshellarg', [
        'openssl', 'pkeyutl', '-verify', '-pubin', '-inkey', $publicPath,
        '-rawin', '-in', $payloadPath, '-sigfile', $signaturePath,
    ])), $output, $status);
    @unlink($publicPath);
    @unlink($signaturePath);
    @unlink($payloadPath);
    return $status === 0;
}

$options = getopt('', ['source::', 'target:']);
$source = rtrim((string) ($options['source'] ?? 'https://raw.githubusercontent.com/ChobitsChii/ModulNest-Modules/main'), '/');
$target = rtrim((string) ($options['target'] ?? ''), '/');
if (!str_starts_with($source, 'https://') || $target === '' || !str_starts_with($target, '/')) fail('--target must be an absolute path and source must use HTTPS');

$rootBytes = fetchBytes($source . '/catalog/v1/root.json', 1048576);
$root = json_decode($rootBytes, true, 64, JSON_THROW_ON_ERROR);
$signatureBytes = fetchBytes($source . '/catalog/v1/root.json.sig', 4096);
$signature = json_decode($signatureBytes, true, 8, JSON_THROW_ON_ERROR);
if (($signature['key_id'] ?? '') !== ROOT_KEY_ID) fail('unexpected root key');
$rootPublic = base64_decode(TRUSTED_KEYS[ROOT_KEY_ID], true);
$rootSignature = base64_decode((string) ($signature['signature'] ?? ''), true);
if (!is_string($rootPublic) || !is_string($rootSignature) || !verifyEd25519($rootSignature, $rootBytes, $rootPublic)) fail('invalid root signature');
$sequence = $root['sequence'] ?? null;
if (!is_int($sequence) || $sequence < 1 || !is_array($root['modules'] ?? null)) fail('invalid catalog root');

$currentRootPath = $target . '/catalog/v1/root.json';
if (is_file($currentRootPath)) {
    $currentBytes = (string) file_get_contents($currentRootPath);
    $current = json_decode($currentBytes, true, 16, JSON_THROW_ON_ERROR);
    $currentSequence = (int) ($current['sequence'] ?? 0);
    if ($sequence < $currentSequence || ($sequence === $currentSequence && !hash_equals(hash('sha256', $currentBytes), hash('sha256', $rootBytes)))) {
        fail('catalog replay or sequence conflict');
    }
}

$stage = dirname($target) . '/.sync-' . basename($target) . '-' . bin2hex(random_bytes(6));
if (!mkdir($stage, 0775, true)) fail('staging root cannot be created');
writeFile($stage, 'catalog/v1/root.json', $rootBytes);
writeFile($stage, 'catalog/v1/root.json.sig', $signatureBytes);
$authorized = [];
foreach ($root['signing_keys'] ?? [] as $key) $authorized[(string) ($key['key_id'] ?? '')] = (string) ($key['fingerprint'] ?? '');

foreach ($root['modules'] as $reference) {
    $indexPath = relativePath((string) ($reference['path'] ?? ''));
    $indexBytes = fetchBytes($source . '/' . $indexPath, 1048576);
    if (!hash_equals((string) ($reference['sha256'] ?? ''), hash('sha256', $indexBytes))) fail('module-index hash mismatch');
    $module = json_decode($indexBytes, true, 64, JSON_THROW_ON_ERROR);
    writeFile($stage, $indexPath, $indexBytes);
    foreach ($module['releases'] ?? [] as $release) {
        $package = $release['package'] ?? [];
        $packagePath = relativePath((string) ($package['location'] ?? ''));
        $packageBytes = fetchBytes($source . '/' . $packagePath, min(268435456, (int) ($package['size'] ?? 0) + 1));
        if (strlen($packageBytes) !== (int) ($package['size'] ?? -1) || !hash_equals((string) ($package['sha256'] ?? ''), hash('sha256', $packageBytes))) fail('package hash or size mismatch');
        $keyId = (string) ($release['signing_key_id'] ?? '');
        $public = isset(TRUSTED_KEYS[$keyId]) ? base64_decode(TRUSTED_KEYS[$keyId], true) : false;
        $packageSignature = base64_decode((string) ($release['signature'] ?? ''), true);
        if (!is_string($public) || !isset($authorized[$keyId]) || !hash_equals($authorized[$keyId], hash('sha256', $public))
            || !is_string($packageSignature) || !verifyEd25519($packageSignature, $packageBytes, $public)
        ) fail('package signature is invalid or unauthorized');
        writeFile($stage, $packagePath, $packageBytes);
    }
}

$previous = dirname($target) . '/' . basename($target) . '.previous-' . gmdate('YmdHis');
if (is_dir($target) && !rename($target, $previous)) fail('current mirror cannot be retained');
if (!rename($stage, $target)) {
    if (is_dir($previous)) @rename($previous, $target);
    fail('atomic mirror switch failed');
}
fwrite(STDOUT, "Repository mirror synchronized: sequence {$sequence}.\n");
