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

$eventType = isset($data['event_type']) ? trim((string)$data['event_type']) : '';
$sessionId = isset($data['session_id']) ? trim((string)$data['session_id']) : '';
$anonymousUserId = isset($data['anonymous_user_id']) ? trim((string)$data['anonymous_user_id']) : '';
$bookId = isset($data['book_id']) ? trim((string)$data['book_id']) : '';
$chapterNo = isset($data['chapter_no']) ? (int)$data['chapter_no'] : 0;
$hoursSinceLastVisit = isset($data['hours_since_last_visit']) ? (float)$data['hours_since_last_visit'] : null;
$daysSinceLastVisit = isset($data['days_since_last_visit']) ? (float)$data['days_since_last_visit'] : null;
$likedRaw = isset($data['liked']) ? strtolower(trim((string)$data['liked'])) : '';
$comment = isset($data['comment']) ? trim((string)$data['comment']) : '';
$eventAtRaw = isset($data['event_at']) ? trim((string)$data['event_at']) : '';
$eventAt = null;
if ($eventAtRaw !== '') {
  $timestamp = strtotime($eventAtRaw);
  if ($timestamp !== false) {
    $eventAt = date('Y-m-d H:i:s', $timestamp);
  }
}
if ($eventAt === null) {
  $eventAt = date('Y-m-d H:i:s');
}

if (!in_array($eventType, ['return_visit', 'chapter_complete', 'parent_feedback'], true)) {
  http_response_code(400);
  respond_error('invalid_event_type');
  exit;
}

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

if (($eventType === 'chapter_complete' || $eventType === 'parent_feedback')) {
  if ($bookId === '' || strlen($bookId) > 80) {
    http_response_code(400);
    respond_error('invalid_book_id');
    exit;
  }
  if ($chapterNo <= 0) {
    http_response_code(400);
    respond_error('invalid_chapter_no');
    exit;
  }
}

if ($eventType === 'return_visit') {
  if ($hoursSinceLastVisit === null || !is_finite($hoursSinceLastVisit) || $hoursSinceLastVisit < 0) {
    http_response_code(400);
    respond_error('invalid_hours_since_last_visit');
    exit;
  }
  if ($daysSinceLastVisit === null || !is_finite($daysSinceLastVisit) || $daysSinceLastVisit < 0) {
    http_response_code(400);
    respond_error('invalid_days_since_last_visit');
    exit;
  }
}

if ($eventType === 'parent_feedback') {
  if (!in_array($likedRaw, ['yes', 'no'], true)) {
    http_response_code(400);
    respond_error('invalid_liked');
    exit;
  }
  if (strlen($comment) > 500) {
    http_response_code(400);
    respond_error('comment_too_long');
    exit;
  }
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
  $pdo->beginTransaction();

  if ($eventType === 'return_visit') {
    $stmt = $pdo->prepare(
      'INSERT INTO behavior_events
        (event_type, session_id, anonymous_user_id, hours_since_last_visit, days_since_last_visit, event_at, created_at, updated_at)
       VALUES
        (:event_type, :session_id, :anonymous_user_id, :hours_since_last_visit, :days_since_last_visit, :event_at, NOW(), NOW())'
    );
    $stmt->execute([
      ':event_type' => $eventType,
      ':session_id' => $sessionId,
      ':anonymous_user_id' => $anonymousUserId,
      ':hours_since_last_visit' => $hoursSinceLastVisit,
      ':days_since_last_visit' => $daysSinceLastVisit,
      ':event_at' => $eventAt
    ]);

    $summaryStmt = $pdo->prepare(
      'INSERT INTO behavior_session_summary
        (session_id, anonymous_user_id, book_id, chapter_no, return_visit_hours, return_visit_days, created_at, updated_at)
       VALUES
        (:session_id, :anonymous_user_id, :book_id, :chapter_no, :return_visit_hours, :return_visit_days, NOW(), NOW())
       ON DUPLICATE KEY UPDATE
        anonymous_user_id = VALUES(anonymous_user_id),
        return_visit_hours = VALUES(return_visit_hours),
        return_visit_days = VALUES(return_visit_days),
        updated_at = NOW()'
    );
    $summaryStmt->execute([
      ':session_id' => $sessionId,
      ':anonymous_user_id' => $anonymousUserId,
      ':book_id' => '',
      ':chapter_no' => 0,
      ':return_visit_hours' => $hoursSinceLastVisit,
      ':return_visit_days' => $daysSinceLastVisit
    ]);
  } elseif ($eventType === 'chapter_complete') {
    $stmt = $pdo->prepare(
      'INSERT INTO behavior_events
        (event_type, session_id, anonymous_user_id, book_id, chapter_no, event_at, created_at, updated_at)
       VALUES
        (:event_type, :session_id, :anonymous_user_id, :book_id, :chapter_no, :event_at, NOW(), NOW())
       ON DUPLICATE KEY UPDATE
        session_id = VALUES(session_id),
        updated_at = NOW()'
    );
    $stmt->execute([
      ':event_type' => $eventType,
      ':session_id' => $sessionId,
      ':anonymous_user_id' => $anonymousUserId,
      ':book_id' => $bookId,
      ':chapter_no' => $chapterNo,
      ':event_at' => $eventAt
    ]);

    $summaryStmt = $pdo->prepare(
      'INSERT INTO behavior_session_summary
        (session_id, anonymous_user_id, book_id, chapter_no, chapter_completed_at, created_at, updated_at)
       VALUES
       (:session_id, :anonymous_user_id, :book_id, :chapter_no, :chapter_completed_at, NOW(), NOW())
       ON DUPLICATE KEY UPDATE
        anonymous_user_id = VALUES(anonymous_user_id),
        chapter_completed_at = CASE
          WHEN chapter_completed_at IS NULL THEN VALUES(chapter_completed_at)
          ELSE chapter_completed_at
        END,
        updated_at = NOW()'
    );
    $summaryStmt->execute([
      ':session_id' => $sessionId,
      ':anonymous_user_id' => $anonymousUserId,
      ':book_id' => $bookId,
      ':chapter_no' => $chapterNo,
      ':chapter_completed_at' => $eventAt
    ]);
  } else {
    $stmt = $pdo->prepare(
      'INSERT INTO behavior_events
        (event_type, session_id, anonymous_user_id, book_id, chapter_no, liked, comment, event_at, created_at, updated_at)
       VALUES
        (:event_type, :session_id, :anonymous_user_id, :book_id, :chapter_no, :liked, :comment, :event_at, NOW(), NOW())
       ON DUPLICATE KEY UPDATE
        session_id = VALUES(session_id),
        liked = VALUES(liked),
        comment = VALUES(comment),
        event_at = VALUES(event_at),
        updated_at = NOW()'
    );
    $stmt->execute([
      ':event_type' => $eventType,
      ':session_id' => $sessionId,
      ':anonymous_user_id' => $anonymousUserId,
      ':book_id' => $bookId,
      ':chapter_no' => $chapterNo,
      ':liked' => $likedRaw,
      ':comment' => $comment === '' ? null : $comment,
      ':event_at' => $eventAt
    ]);

    $summaryStmt = $pdo->prepare(
      'INSERT INTO behavior_session_summary
        (session_id, anonymous_user_id, book_id, chapter_no, liked, comment, created_at, updated_at)
       VALUES
       (:session_id, :anonymous_user_id, :book_id, :chapter_no, :liked, :comment, NOW(), NOW())
       ON DUPLICATE KEY UPDATE
        anonymous_user_id = VALUES(anonymous_user_id),
        liked = VALUES(liked),
        comment = VALUES(comment),
        updated_at = NOW()'
    );
    $summaryStmt->execute([
      ':session_id' => $sessionId,
      ':anonymous_user_id' => $anonymousUserId,
      ':book_id' => $bookId,
      ':chapter_no' => $chapterNo,
      ':liked' => $likedRaw,
      ':comment' => $comment === '' ? null : $comment
    ]);
  }

  $pdo->commit();
} catch (Exception $e) {
  if (isset($pdo) && $pdo->inTransaction()) {
    $pdo->rollBack();
  }
  http_response_code(500);
  error_log($e->getMessage());
  respond_error('db_query_failed', $e->getMessage(), $debug);
  exit;
}

echo json_encode(['ok' => true]);
