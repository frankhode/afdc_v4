<?php
declare(strict_types=1);
require_once __DIR__ . '/_common.php';

$u = function_exists('afdc_v2_current_user') ? afdc_v2_current_user() : null;

if (!$u || empty($u['id'])) {
  jexit(['ok'=>true, 'logged_in'=>false, 'csrf_token'=>csrf_token()]);
}

jexit([
  'ok' => true,
  'logged_in' => true,
  'csrf_token' => csrf_token(),
  'user' => [
    'id' => (int)$u['id'],
    'username' => (string)($u['username'] ?? ''),
    'role' => (string)($u['role'] ?? 'user'),
  ]
]);
