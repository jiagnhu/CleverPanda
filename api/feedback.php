<?php
header('Content-Type: application/json; charset=utf-8');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = ['http://localhost:5173', 'http://127.0.0.1:5173'];
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
  header("Access-Control-Allow-Origin: {$origin}");
  header('Vary: Origin');
  header('Access-Control-Allow-Methods: POST, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type');
}

$debug = false;
$debugAllowed = $origin !== '' && in_array($origin, $allowedOrigins, true);
if ($debugAllowed && isset($_GET['debug']) && $_GET['debug'] === '1') {
  $debug = true;
}

function respond_error($code, $detail = null, $debug = false) {
  $payload = ['ok' => false, 'error' => $code];
  if ($debug && $detail !== null) {
    $payload['detail'] = $detail;
  }
  echo json_encode($payload);
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  respond_error('method_not_allowed');
  exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
  http_response_code(400);
  respond_error('invalid_payload');
  exit;
}

$sessionId = isset($data['session_id']) ? trim((string)$data['session_id']) : '';
$action = isset($data['action']) ? trim((string)$data['action']) : '';
$ctaClicked = isset($data['cta_clicked']) ? (int)$data['cta_clicked'] : 0;
$answer = isset($data['answer']) ? trim((string)$data['answer']) : '';
$sourceRaw = isset($data['source']) ? strtolower(trim((string)$data['source'])) : '';
$source = in_array($sourceRaw, ['internal', 'fiverr'], true) ? $sourceRaw : 'unknown';
$envRaw = isset($data['env']) ? strtolower(trim((string)$data['env'])) : '';
$env = in_array($envRaw, ['test', 'mvp'], true) ? $envRaw : 'unknown';

if ($sessionId === '' || strlen($sessionId) > 80) {
  http_response_code(400);
  respond_error('invalid_session');
  exit;
}

if ($action === 'submit' && $answer === '') {
  http_response_code(422);
  respond_error('answer_required');
  exit;
}

$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
  http_response_code(500);
  respond_error('config_missing');
  exit;
}

$config = require $configPath;
$localConfigPath = __DIR__ . '/config.local.php';
if (file_exists($localConfigPath)) {
  $localConfig = require $localConfigPath;
  if (is_array($localConfig)) {
    $config = array_merge($config, $localConfig);
  }
}
$debug = $debug || (!empty($config['debug']));
$dbHost = $config['db_host'] ?? '';
$dbPort = $config['db_port'] ?? '';
$dbName = $config['db_name'] ?? '';
$dbUser = $config['db_user'] ?? '';
$dbPass = $config['db_pass'] ?? '';

if ($dbHost === '' || $dbName === '' || $dbUser === '') {
  http_response_code(500);
  respond_error('db_config_invalid');
  exit;
}

try {
  $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
  if ($dbPort !== '') {
    $dsn .= ";port={$dbPort}";
  }
  $pdo = new PDO($dsn, $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
  ]);
} catch (Exception $e) {
  http_response_code(500);
  error_log($e->getMessage());
  respond_error('db_connect_failed', $e->getMessage(), $debug);
  exit;
}

try {
  $stmt = $pdo->prepare(
    'INSERT INTO feedback (session_id, source, env, cta_clicked, answer, created_at, updated_at)
     VALUES (:session_id, :source, :env, :cta_clicked, :answer, NOW(), NOW())
     ON DUPLICATE KEY UPDATE
       source = VALUES(source),
       env = VALUES(env),
       cta_clicked = GREATEST(cta_clicked, VALUES(cta_clicked)),
       answer = COALESCE(VALUES(answer), answer),
       updated_at = NOW()'
  );

  $answerParam = $answer !== '' ? $answer : null;
  $stmt->execute([
    ':session_id' => $sessionId,
    ':source' => $source,
    ':env' => $env,
    ':cta_clicked' => $ctaClicked ? 1 : 0,
    ':answer' => $answerParam
  ]);
} catch (Exception $e) {
  http_response_code(500);
  error_log($e->getMessage());
  respond_error('db_query_failed', $e->getMessage(), $debug);
  exit;
}

echo json_encode(['ok' => true]);
