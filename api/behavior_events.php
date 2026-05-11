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

function decode_json_map($raw) {
  if (!is_string($raw) || trim($raw) === '') {
    return [];
  }
  $decoded = json_decode($raw, true);
  if (!is_array($decoded)) {
    return [];
  }
  return $decoded;
}

function encode_json_map($value) {
  if (!is_array($value) || count($value) === 0) {
    return null;
  }
  return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function fetch_user_summary_row(PDO $pdo, $sessionId, $bookId) {
  $stmt = $pdo->prepare(
    'SELECT
      return_visit_hours,
      return_visit_days,
      completed_chapters_json,
      parent_feedback_json,
      more_chapters_json
     FROM behavior_user_summary
     WHERE session_id = :session_id
       AND book_id = :book_id
     LIMIT 1'
  );
  $stmt->execute([
    ':session_id' => $sessionId,
    ':book_id' => $bookId
  ]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    return [
      'return_visit_hours' => null,
      'return_visit_days' => null,
      'completed_chapters_json' => [],
      'parent_feedback_json' => [],
      'more_chapters_json' => []
    ];
  }

  return [
    'return_visit_hours' => isset($row['return_visit_hours']) ? $row['return_visit_hours'] : null,
    'return_visit_days' => isset($row['return_visit_days']) ? $row['return_visit_days'] : null,
    'completed_chapters_json' => decode_json_map($row['completed_chapters_json'] ?? ''),
    'parent_feedback_json' => decode_json_map($row['parent_feedback_json'] ?? ''),
    'more_chapters_json' => decode_json_map($row['more_chapters_json'] ?? '')
  ];
}

function upsert_user_summary(
  PDO $pdo,
  $sessionId,
  $anonymousUserId,
  $bookId,
  $returnVisitHours,
  $returnVisitDays,
  $completedChaptersJson,
  $parentFeedbackJson,
  $moreChaptersJson
) {
  $stmt = $pdo->prepare(
    'INSERT INTO behavior_user_summary
      (
        session_id,
        anonymous_user_id,
        book_id,
        return_visit_hours,
        return_visit_days,
        completed_chapters_json,
        parent_feedback_json,
        more_chapters_json,
        created_at,
        updated_at
      )
     VALUES
      (
        :session_id,
        :anonymous_user_id,
        :book_id,
        :return_visit_hours,
        :return_visit_days,
        :completed_chapters_json,
        :parent_feedback_json,
        :more_chapters_json,
        NOW(),
        NOW()
      )
     ON DUPLICATE KEY UPDATE
      anonymous_user_id = VALUES(anonymous_user_id),
      return_visit_hours = COALESCE(VALUES(return_visit_hours), return_visit_hours),
      return_visit_days = COALESCE(VALUES(return_visit_days), return_visit_days),
      completed_chapters_json = COALESCE(VALUES(completed_chapters_json), completed_chapters_json),
      parent_feedback_json = COALESCE(VALUES(parent_feedback_json), parent_feedback_json),
      more_chapters_json = COALESCE(VALUES(more_chapters_json), more_chapters_json),
      updated_at = NOW()'
  );

  $stmt->execute([
    ':session_id' => $sessionId,
    ':anonymous_user_id' => $anonymousUserId,
    ':book_id' => $bookId,
    ':return_visit_hours' => $returnVisitHours,
    ':return_visit_days' => $returnVisitDays,
    ':completed_chapters_json' => $completedChaptersJson,
    ':parent_feedback_json' => $parentFeedbackJson,
    ':more_chapters_json' => $moreChaptersJson
  ]);
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
$moreChaptersResponseRaw = isset($data['more_chapters_response'])
  ? strtolower(trim((string)$data['more_chapters_response']))
  : '';
$moreChaptersNote = isset($data['more_chapters_note']) ? trim((string)$data['more_chapters_note']) : '';
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

if (!in_array($eventType, ['return_visit', 'chapter_complete', 'parent_feedback', 'more_chapters_response', 'onboarding_interaction'], true)) {
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

if (in_array($eventType, ['chapter_complete', 'parent_feedback', 'more_chapters_response'], true)) {
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

if ($eventType === 'more_chapters_response') {
  if (!in_array($moreChaptersResponseRaw, ['yes', 'no'], true)) {
    http_response_code(400);
    respond_error('invalid_more_chapters_response');
    exit;
  }
  if (strlen($moreChaptersNote) > 500) {
    http_response_code(400);
    respond_error('more_chapters_note_too_long');
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

  if ($eventType === 'onboarding_interaction') {
    $pageIndex = isset($data['page_index']) ? (int)$data['page_index'] : 0;
    $targetWord = isset($data['target_word']) ? trim((string)$data['target_word']) : '';
    $wordsTapped = isset($data['words_tapped']) && is_array($data['words_tapped'])
      ? $data['words_tapped']
      : [];
    $totalTaps = isset($data['total_taps']) ? (int)$data['total_taps'] : 0;
    $firstTapMs = isset($data['first_tap_ms']) && $data['first_tap_ms'] !== null
      ? (int)$data['first_tap_ms']
      : null;
    $hesitationMs = isset($data['hesitation_ms']) && $data['hesitation_ms'] !== null
      ? (int)$data['hesitation_ms']
      : null;

    $stmt = $pdo->prepare(
      'INSERT INTO onboarding_events
        (session_id, anonymous_user_id, page_index, target_word, words_tapped, total_taps, first_tap_ms, hesitation_ms, event_at, created_at)
       VALUES
        (:session_id, :anonymous_user_id, :page_index, :target_word, :words_tapped, :total_taps, :first_tap_ms, :hesitation_ms, :event_at, NOW())'
    );
    $stmt->execute([
      ':session_id'        => $sessionId,
      ':anonymous_user_id' => $anonymousUserId,
      ':page_index'        => $pageIndex,
      ':target_word'       => $targetWord !== '' ? $targetWord : null,
      ':words_tapped'      => json_encode($wordsTapped, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      ':total_taps'        => $totalTaps,
      ':first_tap_ms'      => $firstTapMs,
      ':hesitation_ms'     => $hesitationMs,
      ':event_at'          => $eventAt,
    ]);
  } elseif ($eventType === 'return_visit') {
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

    upsert_user_summary(
      $pdo,
      $sessionId,
      $anonymousUserId,
      '',
      $hoursSinceLastVisit,
      $daysSinceLastVisit,
      null,
      null,
      null
    );
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

    $userSummary = fetch_user_summary_row($pdo, $sessionId, $bookId);
    $completedChapters = $userSummary['completed_chapters_json'];
    $chapterKey = (string)$chapterNo;
    if (!array_key_exists($chapterKey, $completedChapters)) {
      $completedChapters[$chapterKey] = $eventAt;
    }

    upsert_user_summary(
      $pdo,
      $sessionId,
      $anonymousUserId,
      $bookId,
      null,
      null,
      encode_json_map($completedChapters),
      null,
      null
    );
  } elseif ($eventType === 'parent_feedback') {
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

    $userSummary = fetch_user_summary_row($pdo, $sessionId, $bookId);
    $chapterKey = (string)$chapterNo;
    $parentFeedback = $userSummary['parent_feedback_json'];
    $parentFeedback[$chapterKey] = [
      'liked' => $likedRaw,
      'comment' => $comment === '' ? null : $comment,
      'event_at' => $eventAt
    ];

    upsert_user_summary(
      $pdo,
      $sessionId,
      $anonymousUserId,
      $bookId,
      null,
      null,
      null,
      encode_json_map($parentFeedback),
      null
    );
  } else {
    $stmt = $pdo->prepare(
      'INSERT INTO behavior_events
        (event_type, session_id, anonymous_user_id, book_id, chapter_no, more_chapters_response, more_chapters_note, event_at, created_at, updated_at)
       VALUES
        (:event_type, :session_id, :anonymous_user_id, :book_id, :chapter_no, :more_chapters_response, :more_chapters_note, :event_at, NOW(), NOW())
       ON DUPLICATE KEY UPDATE
        session_id = VALUES(session_id),
        more_chapters_response = VALUES(more_chapters_response),
        more_chapters_note = VALUES(more_chapters_note),
        event_at = VALUES(event_at),
        updated_at = NOW()'
    );
    $stmt->execute([
      ':event_type' => $eventType,
      ':session_id' => $sessionId,
      ':anonymous_user_id' => $anonymousUserId,
      ':book_id' => $bookId,
      ':chapter_no' => $chapterNo,
      ':more_chapters_response' => $moreChaptersResponseRaw,
      ':more_chapters_note' => $moreChaptersNote === '' ? null : $moreChaptersNote,
      ':event_at' => $eventAt
    ]);

    $userSummary = fetch_user_summary_row($pdo, $sessionId, $bookId);
    $chapterKey = (string)$chapterNo;
    $moreChapters = $userSummary['more_chapters_json'];
    $moreChapters[$chapterKey] = [
      'response' => $moreChaptersResponseRaw,
      'note' => $moreChaptersNote === '' ? null : $moreChaptersNote,
      'event_at' => $eventAt
    ];

    upsert_user_summary(
      $pdo,
      $sessionId,
      $anonymousUserId,
      $bookId,
      null,
      null,
      null,
      null,
      encode_json_map($moreChapters)
    );
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
