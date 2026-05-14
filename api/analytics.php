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

function normalize_datetime_or_null($value) {
  if (!is_string($value) || trim($value) === '') {
    return null;
  }

  $timestamp = strtotime($value);
  if ($timestamp === false) {
    return null;
  }

  return date('Y-m-d H:i:s', $timestamp);
}

function encode_payload_json($value) {
  if (!is_array($value) || count($value) === 0) {
    return null;
  }
  return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function build_dedupe_key($eventType, $sessionId, $event) {
  if ($eventType === 'chapter_complete') {
    return "chapter_complete:{$sessionId}:{$event['book_id']}:{$event['chapter_no']}";
  }
  if ($eventType === 'parent_feedback') {
    return "parent_feedback:{$sessionId}:{$event['book_id']}:{$event['chapter_no']}";
  }
  if ($eventType === 'more_chapters_response') {
    return "more_chapters_response:{$sessionId}:{$event['book_id']}:{$event['chapter_no']}";
  }
  return null;
}

function create_pdo($config, $debug = false) {
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

    return new PDO($dsn, $dbUser, $dbPass, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
  } catch (Exception $e) {
    http_response_code(500);
    error_log($e->getMessage());
    respond_error('db_connect_failed', $e->getMessage(), $debug);
    exit;
  }
}

/**
 * Session 是 Batch 1 的核心对象。
 * 一切 Robbie 要看的字段，最终都应该落到 analytics_sessions。
 */
function ensure_session_row(PDO $pdo, $sessionId, $anonymousUserId, $sessionStartedAt) {
  $existingStmt = $pdo->prepare(
    'SELECT session_id
     FROM analytics_sessions
     WHERE session_id = :session_id
     LIMIT 1'
  );
  $existingStmt->execute([':session_id' => $sessionId]);
  $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

  if ($existing) {
    return ['created' => false];
  }

  $previousStmt = $pdo->prepare(
    'SELECT session_started_at
     FROM analytics_sessions
     WHERE anonymous_user_id = :anonymous_user_id
       AND session_id <> :session_id
     ORDER BY session_started_at DESC
     LIMIT 1'
  );
  $previousStmt->execute([
    ':anonymous_user_id' => $anonymousUserId,
    ':session_id' => $sessionId
  ]);
  $previous = $previousStmt->fetch(PDO::FETCH_ASSOC);

  $previousSessionAt = $previous['session_started_at'] ?? null;
  $isReturnVisit = $previousSessionAt !== null ? 1 : 0;
  $returnGapHours = null;
  $returnGapDays = null;

  if ($previousSessionAt !== null) {
    $deltaSeconds = strtotime($sessionStartedAt) - strtotime($previousSessionAt);
    if ($deltaSeconds >= 0) {
      $returnGapHours = round($deltaSeconds / 3600, 2);
      $returnGapDays = round($deltaSeconds / 86400, 2);
    }
  }

  $insertStmt = $pdo->prepare(
    'INSERT INTO analytics_sessions
      (
        session_id,
        anonymous_user_id,
        session_started_at,
        active_duration_ms,
        is_return_visit,
        previous_session_at,
        return_gap_hours,
        return_gap_days,
        completed_overall_rate,
        last_content_event_at,
        dropoff_event_type,
        dropoff_book_id,
        dropoff_chapter_no,
        dropoff_content_version,
        dropoff_page_no,
        dropoff_line_index,
        dropoff_position_key,
        dropoff_word,
        created_at,
        updated_at
      )
     VALUES
      (
        :session_id,
        :anonymous_user_id,
        :session_started_at,
        0,
        :is_return_visit,
        :previous_session_at,
        :return_gap_hours,
        :return_gap_days,
        0,
        NULL,
        NULL,
        NULL,
        NULL,
        NULL,
        NULL,
        NULL,
        NULL,
        NULL,
        NOW(),
        NOW()
      )'
  );

  $insertStmt->execute([
    ':session_id' => $sessionId,
    ':anonymous_user_id' => $anonymousUserId,
    ':session_started_at' => $sessionStartedAt,
    ':is_return_visit' => $isReturnVisit,
    ':previous_session_at' => $previousSessionAt,
    ':return_gap_hours' => $returnGapHours,
    ':return_gap_days' => $returnGapDays
  ]);

  $sessionStartEvent = $pdo->prepare(
    'INSERT INTO analytics_events
      (
        event_type,
        dedupe_key,
        session_id,
        anonymous_user_id,
        event_at,
        created_at,
        updated_at
      )
     VALUES
      (
        :event_type,
        :dedupe_key,
        :session_id,
        :anonymous_user_id,
        :event_at,
        NOW(),
        NOW()
      )
     ON DUPLICATE KEY UPDATE
      updated_at = NOW()'
  );
  $sessionStartEvent->execute([
    ':event_type' => 'session_start',
    ':dedupe_key' => "session_start:{$sessionId}",
    ':session_id' => $sessionId,
    ':anonymous_user_id' => $anonymousUserId,
    ':event_at' => $sessionStartedAt
  ]);

  if ($isReturnVisit === 1) {
    $returnVisitEvent = $pdo->prepare(
      'INSERT INTO analytics_events
        (
          event_type,
          dedupe_key,
          session_id,
          anonymous_user_id,
          payload_json,
          event_at,
          created_at,
          updated_at
        )
       VALUES
        (
          :event_type,
          :dedupe_key,
          :session_id,
          :anonymous_user_id,
          :payload_json,
          :event_at,
          NOW(),
          NOW()
        )
       ON DUPLICATE KEY UPDATE
        payload_json = VALUES(payload_json),
        updated_at = NOW()'
    );
    $returnVisitEvent->execute([
      ':event_type' => 'return_visit',
      ':dedupe_key' => "return_visit:{$sessionId}",
      ':session_id' => $sessionId,
      ':anonymous_user_id' => $anonymousUserId,
      ':payload_json' => encode_payload_json([
        'previous_session_at' => $previousSessionAt,
        'return_gap_hours' => $returnGapHours,
        'return_gap_days' => $returnGapDays
      ]),
      ':event_at' => $sessionStartedAt
    ]);
  }

  return ['created' => true];
}

function patch_session_row(
  PDO $pdo,
  $sessionId,
  $anonymousUserId,
  $activeDurationDeltaMs,
  $sessionEndedAt
) {
  $stmt = $pdo->prepare(
    'UPDATE analytics_sessions
     SET
      anonymous_user_id = :anonymous_user_id,
      active_duration_ms = active_duration_ms + :active_duration_delta_ms,
      session_ended_at =
        CASE
          WHEN :session_ended_at_set IS NOT NULL THEN :session_ended_at_set
          ELSE session_ended_at
        END,
      updated_at = NOW()
     WHERE session_id = :session_id'
  );

  $stmt->execute([
    ':session_id' => $sessionId,
    ':anonymous_user_id' => $anonymousUserId,
    ':active_duration_delta_ms' => $activeDurationDeltaMs,
    ':session_ended_at_set' => $sessionEndedAt
  ]);
}

/**
 * first_word_tap 只能来自真实的 word_tap 事件。
 * 这样 sessions 表里的 first tap 永远以事实事件为准，不依赖前端额外并行传一份字段。
 *
 * 这里 first_word_tap_sec 不能再判断 first_word_tap_at 是否为空，
 * 因为同一条 UPDATE 里先写入了 first_word_tap_at，后面的判断可能拿到的是新值。
 * 所以秒数是否需要回填，只看 first_word_tap_sec 自己是否还为空。
 */
function set_session_first_word_tap_if_missing(PDO $pdo, $sessionId, $eventAt) {
  $stmt = $pdo->prepare(
    'UPDATE analytics_sessions
     SET
      first_word_tap_at = COALESCE(first_word_tap_at, :first_word_tap_at),
      first_word_tap_sec =
        CASE
          WHEN first_word_tap_sec IS NULL
            THEN GREATEST(TIMESTAMPDIFF(SECOND, session_started_at, COALESCE(first_word_tap_at, :first_word_tap_diff)), 0)
          ELSE first_word_tap_sec
        END,
      updated_at = NOW()
     WHERE session_id = :session_id'
  );

  $stmt->execute([
    ':session_id' => $sessionId,
    ':first_word_tap_at' => $eventAt,
    ':first_word_tap_diff' => $eventAt
  ]);
}

function upsert_content_chapter(PDO $pdo, $bookId, $chapterNo, $contentVersion, $totalInteractivePositions, $contentUrl) {
  $stmt = $pdo->prepare(
    'INSERT INTO analytics_content_chapters
      (
        book_id,
        chapter_no,
        content_version,
        total_interactive_positions,
        content_url,
        created_at,
        updated_at
      )
     VALUES
      (
        :book_id,
        :chapter_no,
        :content_version,
        :total_interactive_positions,
        :content_url,
        NOW(),
        NOW()
      )
     ON DUPLICATE KEY UPDATE
      total_interactive_positions = VALUES(total_interactive_positions),
      content_url = VALUES(content_url),
      updated_at = NOW()'
  );

  $stmt->execute([
    ':book_id' => $bookId,
    ':chapter_no' => $chapterNo,
    ':content_version' => $contentVersion,
    ':total_interactive_positions' => $totalInteractivePositions,
    ':content_url' => $contentUrl === '' ? null : $contentUrl
  ]);
}

/**
 * 掉线点只描述“最后停在阅读内容哪里”，因此只接受内容事件更新。
 * 这样家长反馈、更多章节弹窗这些后续动作不会污染 drop-off。
 */
function update_session_dropoff(
  PDO $pdo,
  $sessionId,
  $eventType,
  $eventAt,
  $bookId,
  $chapterNo,
  $contentVersion,
  $pageNo,
  $lineIndex,
  $positionKey,
  $word
) {
  $stmt = $pdo->prepare(
    'UPDATE analytics_sessions
     SET
      last_content_event_at =
        CASE
          WHEN last_content_event_at IS NULL OR :event_at_time >= last_content_event_at
            THEN :event_at_set
          ELSE last_content_event_at
        END,
      dropoff_event_type =
        CASE
          WHEN last_content_event_at IS NULL OR :event_at_type >= last_content_event_at
            THEN :dropoff_event_type_set
          ELSE dropoff_event_type
        END,
      dropoff_book_id =
        CASE
          WHEN last_content_event_at IS NULL OR :event_at_book >= last_content_event_at
            THEN :dropoff_book_id_set
          ELSE dropoff_book_id
        END,
      dropoff_chapter_no =
        CASE
          WHEN last_content_event_at IS NULL OR :event_at_chapter >= last_content_event_at
            THEN :dropoff_chapter_no_set
          ELSE dropoff_chapter_no
        END,
      dropoff_content_version =
        CASE
          WHEN last_content_event_at IS NULL OR :event_at_version >= last_content_event_at
            THEN :dropoff_content_version_set
          ELSE dropoff_content_version
        END,
      dropoff_page_no =
        CASE
          WHEN last_content_event_at IS NULL OR :event_at_page >= last_content_event_at
            THEN :dropoff_page_no_set
          ELSE dropoff_page_no
        END,
      dropoff_line_index =
        CASE
          WHEN last_content_event_at IS NULL OR :event_at_line >= last_content_event_at
            THEN :dropoff_line_index_set
          ELSE dropoff_line_index
        END,
      dropoff_position_key =
        CASE
          WHEN last_content_event_at IS NULL OR :event_at_position >= last_content_event_at
            THEN :dropoff_position_key_set
          ELSE dropoff_position_key
        END,
      dropoff_word =
        CASE
          WHEN last_content_event_at IS NULL OR :event_at_word >= last_content_event_at
            THEN :dropoff_word_set
          ELSE dropoff_word
        END,
      updated_at = NOW()
     WHERE session_id = :session_id'
  );

  $stmt->execute([
    ':session_id' => $sessionId,
    ':event_at_time' => $eventAt,
    ':event_at_set' => $eventAt,
    ':event_at_type' => $eventAt,
    ':dropoff_event_type_set' => $eventType,
    ':event_at_book' => $eventAt,
    ':dropoff_book_id_set' => $bookId,
    ':event_at_chapter' => $eventAt,
    ':dropoff_chapter_no_set' => $chapterNo,
    ':event_at_version' => $eventAt,
    ':dropoff_content_version_set' => $contentVersion,
    ':event_at_page' => $eventAt,
    ':dropoff_page_no_set' => $pageNo,
    ':event_at_line' => $eventAt,
    ':dropoff_line_index_set' => $lineIndex,
    ':event_at_position' => $eventAt,
    ':dropoff_position_key_set' => $positionKey,
    ':event_at_word' => $eventAt,
    ':dropoff_word_set' => $word,
  ]);
}

function resolve_total_interactive_positions(PDO $pdo, $bookId, $chapterNo, $contentVersion, $fallback) {
  $stmt = $pdo->prepare(
    'SELECT total_interactive_positions
     FROM analytics_content_chapters
     WHERE book_id = :book_id
       AND chapter_no = :chapter_no
       AND content_version = :content_version
     LIMIT 1'
  );
  $stmt->execute([
    ':book_id' => $bookId,
    ':chapter_no' => $chapterNo,
    ':content_version' => $contentVersion
  ]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($row && isset($row['total_interactive_positions'])) {
    return (int)$row['total_interactive_positions'];
  }

  return max(0, (int)$fallback);
}

function ensure_session_chapter_row(
  PDO $pdo,
  $sessionId,
  $anonymousUserId,
  $bookId,
  $chapterNo,
  $contentVersion,
  $totalInteractivePositions,
  $firstTapAt,
  $lastTapAt
) {
  $stmt = $pdo->prepare(
    'INSERT INTO analytics_session_chapters
      (
        session_id,
        anonymous_user_id,
        book_id,
        chapter_no,
        content_version,
        total_interactive_positions,
        unique_tapped_positions,
        completion_rate,
        first_tap_at,
        last_tap_at,
        created_at,
        updated_at
      )
     VALUES
      (
        :session_id,
        :anonymous_user_id,
        :book_id,
        :chapter_no,
        :content_version,
        :total_interactive_positions,
        0,
        0,
        :first_tap_at,
        :last_tap_at,
        NOW(),
        NOW()
      )
     ON DUPLICATE KEY UPDATE
      anonymous_user_id = VALUES(anonymous_user_id),
      total_interactive_positions =
        CASE
          WHEN total_interactive_positions > 0 THEN total_interactive_positions
          ELSE VALUES(total_interactive_positions)
        END,
      first_tap_at = COALESCE(first_tap_at, VALUES(first_tap_at)),
      last_tap_at =
        CASE
          WHEN VALUES(last_tap_at) IS NOT NULL THEN VALUES(last_tap_at)
          ELSE last_tap_at
        END,
      updated_at = NOW()'
  );

  $stmt->execute([
    ':session_id' => $sessionId,
    ':anonymous_user_id' => $anonymousUserId,
    ':book_id' => $bookId,
    ':chapter_no' => $chapterNo,
    ':content_version' => $contentVersion,
    ':total_interactive_positions' => $totalInteractivePositions,
    ':first_tap_at' => $firstTapAt,
    ':last_tap_at' => $lastTapAt
  ]);
}

function increment_session_chapter_progress(PDO $pdo, $sessionId, $bookId, $chapterNo, $contentVersion) {
  $incrementStmt = $pdo->prepare(
    'UPDATE analytics_session_chapters
     SET
      unique_tapped_positions = unique_tapped_positions + 1,
      updated_at = NOW()
     WHERE session_id = :session_id
       AND book_id = :book_id
       AND chapter_no = :chapter_no
       AND content_version = :content_version'
  );
  $incrementStmt->execute([
    ':session_id' => $sessionId,
    ':book_id' => $bookId,
    ':chapter_no' => $chapterNo,
    ':content_version' => $contentVersion
  ]);
}

function recalculate_session_chapter_rate(PDO $pdo, $sessionId, $bookId, $chapterNo, $contentVersion) {
  $stmt = $pdo->prepare(
    'UPDATE analytics_session_chapters
     SET
      completion_rate =
        CASE
          WHEN total_interactive_positions > 0
            THEN ROUND(unique_tapped_positions / total_interactive_positions, 4)
          ELSE 0
        END,
      updated_at = NOW()
     WHERE session_id = :session_id
       AND book_id = :book_id
       AND chapter_no = :chapter_no
       AND content_version = :content_version'
  );
  $stmt->execute([
    ':session_id' => $sessionId,
    ':book_id' => $bookId,
    ':chapter_no' => $chapterNo,
    ':content_version' => $contentVersion
  ]);
}

function mark_session_chapter_completed(
  PDO $pdo,
  $sessionId,
  $anonymousUserId,
  $bookId,
  $chapterNo,
  $contentVersion,
  $eventAt
) {
  $totalInteractivePositions = resolve_total_interactive_positions($pdo, $bookId, $chapterNo, $contentVersion, 0);

  ensure_session_chapter_row(
    $pdo,
    $sessionId,
    $anonymousUserId,
    $bookId,
    $chapterNo,
    $contentVersion,
    $totalInteractivePositions,
    null,
    $eventAt
  );

  $stmt = $pdo->prepare(
    'UPDATE analytics_session_chapters
     SET
      is_completed = 1,
      completed_at = COALESCE(completed_at, :completed_at),
      updated_at = NOW()
     WHERE session_id = :session_id
       AND book_id = :book_id
       AND chapter_no = :chapter_no
       AND content_version = :content_version'
  );
  $stmt->execute([
    ':completed_at' => $eventAt,
    ':session_id' => $sessionId,
    ':book_id' => $bookId,
    ':chapter_no' => $chapterNo,
    ':content_version' => $contentVersion
  ]);
}

function insert_singleton_event(PDO $pdo, $sessionId, $anonymousUserId, $event) {
  $eventType = $event['type'];
  $dedupeKey = build_dedupe_key($eventType, $sessionId, $event);
  $bookId = isset($event['book_id']) ? trim((string)$event['book_id']) : '';
  $chapterNo = isset($event['chapter_no']) ? (int)$event['chapter_no'] : null;
  $contentVersion = isset($event['content_version']) ? trim((string)$event['content_version']) : '';
  $pageNo = isset($event['page_no']) ? (int)$event['page_no'] : null;
  $positionKey = isset($event['position_key']) ? trim((string)$event['position_key']) : '';
  $word = isset($event['word']) ? trim((string)$event['word']) : '';
  $liked = isset($event['liked']) ? trim((string)$event['liked']) : null;
  $comment = isset($event['comment']) ? trim((string)$event['comment']) : '';
  $response = isset($event['response']) ? trim((string)$event['response']) : null;
  $note = isset($event['note']) ? trim((string)$event['note']) : '';
  $payloadJson = encode_payload_json(isset($event['payload']) && is_array($event['payload']) ? $event['payload'] : []);
  $eventAt = normalize_datetime_or_null($event['event_at'] ?? '') ?? date('Y-m-d H:i:s');

  $stmt = $pdo->prepare(
    'INSERT INTO analytics_events
      (
        event_type,
        dedupe_key,
        session_id,
        anonymous_user_id,
        book_id,
        chapter_no,
        content_version,
        page_no,
        position_key,
        word,
        liked,
        comment,
        more_chapters_response,
        more_chapters_note,
        payload_json,
        event_at,
        created_at,
        updated_at
      )
     VALUES
      (
        :event_type,
        :dedupe_key,
        :session_id,
        :anonymous_user_id,
        :book_id,
        :chapter_no,
        :content_version,
        :page_no,
        :position_key,
        :word,
        :liked,
        :comment,
        :more_chapters_response,
        :more_chapters_note,
        :payload_json,
        :event_at,
        NOW(),
        NOW()
      )
     ON DUPLICATE KEY UPDATE
      liked = VALUES(liked),
      comment = VALUES(comment),
      more_chapters_response = VALUES(more_chapters_response),
      more_chapters_note = VALUES(more_chapters_note),
      payload_json = VALUES(payload_json),
      event_at = VALUES(event_at),
      updated_at = NOW()'
  );

  $stmt->execute([
    ':event_type' => $eventType,
    ':dedupe_key' => $dedupeKey,
    ':session_id' => $sessionId,
    ':anonymous_user_id' => $anonymousUserId,
    ':book_id' => $bookId === '' ? null : $bookId,
    ':chapter_no' => $chapterNo,
    ':content_version' => $contentVersion === '' ? null : $contentVersion,
    ':page_no' => $pageNo,
    ':position_key' => $positionKey === '' ? null : $positionKey,
    ':word' => $word === '' ? null : $word,
    ':liked' => $liked,
    ':comment' => $comment === '' ? null : $comment,
    ':more_chapters_response' => $response,
    ':more_chapters_note' => $note === '' ? null : $note,
    ':payload_json' => $payloadJson,
    ':event_at' => $eventAt
  ]);
}

function upsert_session_end_event(PDO $pdo, $sessionId, $anonymousUserId, $sessionEndedAt) {
  if ($sessionEndedAt === null) {
    return;
  }

  $stmt = $pdo->prepare(
    'INSERT INTO analytics_events
      (
        event_type,
        dedupe_key,
        session_id,
        anonymous_user_id,
        event_at,
        created_at,
        updated_at
      )
     VALUES
      (
        :event_type,
        :dedupe_key,
        :session_id,
        :anonymous_user_id,
        :event_at,
        NOW(),
        NOW()
      )
     ON DUPLICATE KEY UPDATE
      event_at = VALUES(event_at),
      updated_at = NOW()'
  );

  $stmt->execute([
    ':event_type' => 'session_end',
    ':dedupe_key' => "session_end:{$sessionId}",
    ':session_id' => $sessionId,
    ':anonymous_user_id' => $anonymousUserId,
    ':event_at' => $sessionEndedAt
  ]);
}

function insert_free_event(PDO $pdo, $sessionId, $anonymousUserId, $event) {
  $eventType = $event['type'];
  $bookId = isset($event['book_id']) ? trim((string)$event['book_id']) : '';
  $chapterNo = isset($event['chapter_no']) ? (int)$event['chapter_no'] : null;
  $contentVersion = isset($event['content_version']) ? trim((string)$event['content_version']) : '';
  $pageNo = isset($event['page_no']) ? (int)$event['page_no'] : null;
  $positionKey = isset($event['position_key']) ? trim((string)$event['position_key']) : '';
  $word = isset($event['word']) ? trim((string)$event['word']) : '';
  $payloadJson = encode_payload_json(isset($event['payload']) && is_array($event['payload']) ? $event['payload'] : []);
  $eventAt = normalize_datetime_or_null($event['event_at'] ?? '') ?? date('Y-m-d H:i:s');

  $stmt = $pdo->prepare(
    'INSERT INTO analytics_events
      (
        event_type,
        session_id,
        anonymous_user_id,
        book_id,
        chapter_no,
        content_version,
        page_no,
        position_key,
        word,
        payload_json,
        event_at,
        created_at,
        updated_at
      )
     VALUES
      (
        :event_type,
        :session_id,
        :anonymous_user_id,
        :book_id,
        :chapter_no,
        :content_version,
        :page_no,
        :position_key,
        :word,
        :payload_json,
        :event_at,
        NOW(),
        NOW()
      )'
  );

  $stmt->execute([
    ':event_type' => $eventType,
    ':session_id' => $sessionId,
    ':anonymous_user_id' => $anonymousUserId,
    ':book_id' => $bookId === '' ? null : $bookId,
    ':chapter_no' => $chapterNo,
    ':content_version' => $contentVersion === '' ? null : $contentVersion,
    ':page_no' => $pageNo,
    ':position_key' => $positionKey === '' ? null : $positionKey,
    ':word' => $word === '' ? null : $word,
    ':payload_json' => $payloadJson,
    ':event_at' => $eventAt
  ]);

  return $eventAt;
}

function process_word_tap_event(PDO $pdo, $sessionId, $anonymousUserId, $event) {
  $eventAt = insert_free_event($pdo, $sessionId, $anonymousUserId, $event);
  set_session_first_word_tap_if_missing($pdo, $sessionId, $eventAt);

  $bookId = trim((string)$event['book_id']);
  $chapterNo = (int)$event['chapter_no'];
  $contentVersion = trim((string)$event['content_version']);
  $pageNo = (int)$event['page_no'];
  $positionKey = trim((string)$event['position_key']);
  $word = trim((string)$event['word']);
  $chapterTotalInteractivePositions = isset($event['chapter_total_interactive_positions'])
    ? max(0, (int)$event['chapter_total_interactive_positions'])
    : 0;
  $lineIndex = null;
  $interactiveIndexInLine = null;
  if (isset($event['payload']) && is_array($event['payload'])) {
    if (isset($event['payload']['line_index'])) {
      $lineIndex = (int)$event['payload']['line_index'];
    }
    if (isset($event['payload']['interactive_index_in_line'])) {
      $interactiveIndexInLine = (int)$event['payload']['interactive_index_in_line'];
    }
  }

  $positionStmt = $pdo->prepare(
    'INSERT IGNORE INTO analytics_session_chapter_positions
      (
        session_id,
        anonymous_user_id,
        book_id,
        chapter_no,
        content_version,
        position_key,
        page_no,
        line_index,
        interactive_index_in_line,
        word,
        first_tapped_at,
        created_at
      )
     VALUES
      (
        :session_id,
        :anonymous_user_id,
        :book_id,
        :chapter_no,
        :content_version,
        :position_key,
        :page_no,
        :line_index,
        :interactive_index_in_line,
        :word,
        :first_tapped_at,
        NOW()
      )'
  );
  $positionStmt->execute([
    ':session_id' => $sessionId,
    ':anonymous_user_id' => $anonymousUserId,
    ':book_id' => $bookId,
    ':chapter_no' => $chapterNo,
    ':content_version' => $contentVersion,
    ':position_key' => $positionKey,
    ':page_no' => $pageNo,
    ':line_index' => $lineIndex,
    ':interactive_index_in_line' => $interactiveIndexInLine,
    ':word' => $word,
    ':first_tapped_at' => $eventAt
  ]);

  $isNewPosition = $positionStmt->rowCount() > 0;
  $totalInteractivePositions = resolve_total_interactive_positions(
    $pdo,
    $bookId,
    $chapterNo,
    $contentVersion,
    $chapterTotalInteractivePositions
  );

  ensure_session_chapter_row(
    $pdo,
    $sessionId,
    $anonymousUserId,
    $bookId,
    $chapterNo,
    $contentVersion,
    $totalInteractivePositions,
    $eventAt,
    $eventAt
  );

  if ($isNewPosition) {
    increment_session_chapter_progress($pdo, $sessionId, $bookId, $chapterNo, $contentVersion);
  }

  recalculate_session_chapter_rate($pdo, $sessionId, $bookId, $chapterNo, $contentVersion);
  update_session_dropoff(
    $pdo,
    $sessionId,
    'word_tap',
    $eventAt,
    $bookId,
    $chapterNo,
    $contentVersion,
    $pageNo,
    $lineIndex,
    $positionKey,
    $word
  );
}

function process_page_view_event(PDO $pdo, $sessionId, $anonymousUserId, $event) {
  $eventAt = insert_free_event($pdo, $sessionId, $anonymousUserId, $event);

  $bookId = trim((string)$event['book_id']);
  $chapterNo = (int)$event['chapter_no'];
  $contentVersion = trim((string)$event['content_version']);
  $pageNo = (int)$event['page_no'];
  $lineIndex = null;

  if (isset($event['payload']) && is_array($event['payload']) && array_key_exists('line_index', $event['payload'])) {
    $lineIndex = (int)$event['payload']['line_index'];
  }

  update_session_dropoff(
    $pdo,
    $sessionId,
    'page_view',
    $eventAt,
    $bookId,
    $chapterNo,
    $contentVersion,
    $pageNo,
    $lineIndex,
    null,
    null
  );
}

function recalculate_session_overall_rate(PDO $pdo, $sessionId) {
  $stmt = $pdo->prepare(
    'SELECT
      COALESCE(SUM(unique_tapped_positions), 0) AS tapped_positions,
      COALESCE(SUM(total_interactive_positions), 0) AS total_positions
     FROM analytics_session_chapters
     WHERE session_id = :session_id'
  );
  $stmt->execute([':session_id' => $sessionId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  $tapped = isset($row['tapped_positions']) ? (int)$row['tapped_positions'] : 0;
  $total = isset($row['total_positions']) ? (int)$row['total_positions'] : 0;
  $overallRate = $total > 0 ? round($tapped / $total, 4) : 0;

  $updateStmt = $pdo->prepare(
    'UPDATE analytics_sessions
     SET
      completed_overall_rate = :completed_overall_rate,
      updated_at = NOW()
     WHERE session_id = :session_id'
  );
  $updateStmt->execute([
    ':completed_overall_rate' => $overallRate,
    ':session_id' => $sessionId
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

$action = isset($data['action']) ? trim((string)$data['action']) : '';
if ($action !== 'sync_session') {
  http_response_code(400);
  respond_error('invalid_action');
  exit;
}

$sessionId = isset($data['session_id']) ? trim((string)$data['session_id']) : '';
$anonymousUserId = isset($data['anonymous_user_id']) ? trim((string)$data['anonymous_user_id']) : '';
$sessionStartedAt = normalize_datetime_or_null($data['session_started_at'] ?? '') ?? date('Y-m-d H:i:s');
$sessionEndedAt = normalize_datetime_or_null($data['session_ended_at'] ?? '');
$activeDurationDeltaMs = isset($data['active_duration_delta_ms']) ? (int)$data['active_duration_delta_ms'] : 0;
$contentChapters = isset($data['content_chapters']) && is_array($data['content_chapters']) ? $data['content_chapters'] : [];
$events = isset($data['events']) && is_array($data['events']) ? $data['events'] : [];

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

if ($activeDurationDeltaMs < 0) {
  http_response_code(400);
  respond_error('invalid_active_duration_delta');
  exit;
}

$allowedEventTypes = [
  'word_tap',
  'page_view',
  'page_dwell',
  'audio_play_started',
  'audio_play_completed',
  'start_reading_click',
  'onboarding_to_story_click',
  'next_chapter_click',
  'content_load_failed',
  'session_interrupted',
  'chapter_complete',
  'parent_feedback',
  'more_chapters_response'
];

foreach ($contentChapters as $chapterMeta) {
  if (!is_array($chapterMeta)) {
    http_response_code(400);
    respond_error('invalid_content_chapter');
    exit;
  }

  $bookId = isset($chapterMeta['book_id']) ? trim((string)$chapterMeta['book_id']) : '';
  $chapterNo = isset($chapterMeta['chapter_no']) ? (int)$chapterMeta['chapter_no'] : 0;
  $contentVersion = isset($chapterMeta['content_version']) ? trim((string)$chapterMeta['content_version']) : '';
  $totalInteractivePositions = isset($chapterMeta['total_interactive_positions'])
    ? (int)$chapterMeta['total_interactive_positions']
    : -1;

  if ($bookId === '' || strlen($bookId) > 80) {
    http_response_code(400);
    respond_error('invalid_content_chapter_book_id');
    exit;
  }
  if ($chapterNo <= 0) {
    http_response_code(400);
    respond_error('invalid_content_chapter_no');
    exit;
  }
  if ($contentVersion === '' || strlen($contentVersion) > 120) {
    http_response_code(400);
    respond_error('invalid_content_version');
    exit;
  }
  if ($totalInteractivePositions < 0) {
    http_response_code(400);
    respond_error('invalid_total_interactive_positions');
    exit;
  }
}

foreach ($events as $event) {
  if (!is_array($event)) {
    http_response_code(400);
    respond_error('invalid_event_payload');
    exit;
  }

  $eventType = isset($event['type']) ? trim((string)$event['type']) : '';
  if (!in_array($eventType, $allowedEventTypes, true)) {
    http_response_code(400);
    respond_error('invalid_event_type');
    exit;
  }

  if ($eventType === 'word_tap' || $eventType === 'page_view' || $eventType === 'chapter_complete') {
    $bookId = isset($event['book_id']) ? trim((string)$event['book_id']) : '';
    $chapterNo = isset($event['chapter_no']) ? (int)$event['chapter_no'] : 0;
    $contentVersion = isset($event['content_version']) ? trim((string)$event['content_version']) : '';
    if ($bookId === '' || strlen($bookId) > 80) {
      http_response_code(400);
      respond_error('invalid_event_book_id');
      exit;
    }
    if ($chapterNo <= 0) {
      http_response_code(400);
      respond_error('invalid_event_chapter_no');
      exit;
    }
    if ($contentVersion === '' || strlen($contentVersion) > 120) {
      http_response_code(400);
      respond_error('invalid_event_content_version');
      exit;
    }
  }

  if ($eventType === 'word_tap') {
    $pageNo = isset($event['page_no']) ? (int)$event['page_no'] : 0;
    $positionKey = isset($event['position_key']) ? trim((string)$event['position_key']) : '';
    $word = isset($event['word']) ? trim((string)$event['word']) : '';
    if ($pageNo <= 0) {
      http_response_code(400);
      respond_error('invalid_word_tap_page_no');
      exit;
    }
    if ($positionKey === '' || strlen($positionKey) > 120) {
      http_response_code(400);
      respond_error('invalid_position_key');
      exit;
    }
    if ($word === '') {
      http_response_code(400);
      respond_error('invalid_word');
      exit;
    }
  }

  if ($eventType === 'page_view') {
    $pageNo = isset($event['page_no']) ? (int)$event['page_no'] : 0;
    if ($pageNo <= 0) {
      http_response_code(400);
      respond_error('invalid_page_view_page_no');
      exit;
    }
    if (isset($event['payload']) && is_array($event['payload']) && array_key_exists('line_index', $event['payload'])) {
      $lineIndex = (int)$event['payload']['line_index'];
      if ($lineIndex < 0) {
        http_response_code(400);
        respond_error('invalid_page_view_line_index');
        exit;
      }
    }
  }

  if ($eventType === 'parent_feedback') {
    $bookId = isset($event['book_id']) ? trim((string)$event['book_id']) : '';
    $chapterNo = isset($event['chapter_no']) ? (int)$event['chapter_no'] : 0;
    $liked = isset($event['liked']) ? trim((string)$event['liked']) : '';
    $comment = isset($event['comment']) ? trim((string)$event['comment']) : '';
    if ($bookId === '' || $chapterNo <= 0 || !in_array($liked, ['yes', 'no'], true) || strlen($comment) > 500) {
      http_response_code(400);
      respond_error('invalid_parent_feedback');
      exit;
    }
  }

  if ($eventType === 'more_chapters_response') {
    $bookId = isset($event['book_id']) ? trim((string)$event['book_id']) : '';
    $chapterNo = isset($event['chapter_no']) ? (int)$event['chapter_no'] : 0;
    $response = isset($event['response']) ? trim((string)$event['response']) : '';
    $note = isset($event['note']) ? trim((string)$event['note']) : '';
    if ($bookId === '' || $chapterNo <= 0 || !in_array($response, ['yes', 'no'], true) || strlen($note) > 500) {
      http_response_code(400);
      respond_error('invalid_more_chapters_response');
      exit;
    }
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

$pdo = create_pdo($config, $debug);

try {
  $pdo->beginTransaction();

  ensure_session_row($pdo, $sessionId, $anonymousUserId, $sessionStartedAt);

  patch_session_row(
    $pdo,
    $sessionId,
    $anonymousUserId,
    $activeDurationDeltaMs,
    $sessionEndedAt
  );
  upsert_session_end_event($pdo, $sessionId, $anonymousUserId, $sessionEndedAt);

  foreach ($contentChapters as $chapterMeta) {
    upsert_content_chapter(
      $pdo,
      trim((string)$chapterMeta['book_id']),
      (int)$chapterMeta['chapter_no'],
      trim((string)$chapterMeta['content_version']),
      (int)$chapterMeta['total_interactive_positions'],
      isset($chapterMeta['content_url']) ? trim((string)$chapterMeta['content_url']) : ''
    );
  }

  foreach ($events as $event) {
    $eventType = trim((string)$event['type']);

    if ($eventType === 'word_tap') {
      process_word_tap_event($pdo, $sessionId, $anonymousUserId, $event);
      continue;
    }

    if ($eventType === 'page_view') {
      process_page_view_event($pdo, $sessionId, $anonymousUserId, $event);
      continue;
    }

    insert_singleton_event($pdo, $sessionId, $anonymousUserId, $event);

    if ($eventType === 'chapter_complete') {
      mark_session_chapter_completed(
        $pdo,
        $sessionId,
        $anonymousUserId,
        trim((string)$event['book_id']),
        (int)$event['chapter_no'],
        trim((string)$event['content_version']),
        normalize_datetime_or_null($event['event_at'] ?? '') ?? date('Y-m-d H:i:s')
      );
    }
  }

  recalculate_session_overall_rate($pdo, $sessionId);
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
