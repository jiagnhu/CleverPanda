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
$anonymousUserId = isset($data['anonymous_user_id']) ? trim((string)$data['anonymous_user_id']) : '';
$clickDelta = isset($data['click_count_delta']) ? (int)$data['click_count_delta'] : 0;
$activeDurationDelta = isset($data['active_duration_delta_ms']) ? (int)$data['active_duration_delta_ms'] : 0;

$reachedRaw = $data['reached_sentence_6'] ?? false;
$reachedBool = filter_var($reachedRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
$reachedSentence6 = $reachedBool === true ? 1 : 0;

if ($sessionId === '' || strlen($sessionId) > 80) {
  http_response_code(400);
  respond_error('invalid_session');
  exit;
}

if ($anonymousUserId === '' || strlen($anonymousUserId) > 80) {
  http_response_code(400);
  respond_error('invalid_anonymous_user_id');
  exit;
}

if ($clickDelta < 0) {
  http_response_code(400);
  respond_error('invalid_click_delta');
  exit;
}

if ($activeDurationDelta < 0) {
  http_response_code(400);
  respond_error('invalid_active_duration_delta');
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
    'INSERT INTO tracking
      (session_id, anonymous_user_id, total_click_count, reached_sentence_6, active_duration_ms, session_start_at, last_event_at, created_at, updated_at)
     VALUES
      (:session_id, :anonymous_user_id, :click_delta, :reached_sentence_6, :active_duration_delta, NOW(), NOW(), NOW(), NOW())
     ON DUPLICATE KEY UPDATE
      anonymous_user_id = VALUES(anonymous_user_id),
      total_click_count = total_click_count + VALUES(total_click_count),
      reached_sentence_6 = GREATEST(reached_sentence_6, VALUES(reached_sentence_6)),
      active_duration_ms = active_duration_ms + VALUES(active_duration_ms),
      last_event_at = NOW(),
      updated_at = NOW()'
  );

  $stmt->execute([
    ':session_id' => $sessionId,
    ':anonymous_user_id' => $anonymousUserId,
    ':click_delta' => $clickDelta,
    ':reached_sentence_6' => $reachedSentence6,
    ':active_duration_delta' => $activeDurationDelta
  ]);
} catch (Exception $e) {
  http_response_code(500);
  error_log($e->getMessage());
  respond_error('db_query_failed', $e->getMessage(), $debug);
  exit;
}

echo json_encode(['ok' => true]);
