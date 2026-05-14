CREATE OR REPLACE VIEW analytics_batch1_view AS
SELECT
  anonymous_user_id AS user_id,
  session_id,
  session_started_at,
  first_word_tap_sec,
  ROUND(active_duration_ms / 1000, 1) AS duration_seconds,
  ROUND(completed_overall_rate * 100, 1) AS overall_completion_percent,
  CASE WHEN is_return_visit = 1 THEN 'Y' ELSE 'N' END AS returned
FROM analytics_sessions;

CREATE OR REPLACE VIEW analytics_batch1_summary_view AS
SELECT
  COUNT(*) AS total_users,
  ROUND(AVG(started_user) * 100, 1) AS started_user_percent,
  ROUND(AVG(avg_time_to_first_tap_sec), 1) AS average_time_to_first_tap_sec,
  ROUND(AVG(avg_session_duration_seconds), 1) AS average_session_duration_seconds,
  ROUND(AVG(avg_completion_percent), 1) AS average_completion_percent,
  ROUND(AVG(return_user) * 100, 1) AS return_user_percent
FROM (
  SELECT
    anonymous_user_id,
    MAX(CASE WHEN first_word_tap_sec IS NOT NULL THEN 1 ELSE 0 END) AS started_user,
    AVG(CASE WHEN first_word_tap_sec IS NOT NULL THEN first_word_tap_sec END) AS avg_time_to_first_tap_sec,
    AVG(active_duration_ms / 1000) AS avg_session_duration_seconds,
    AVG(completed_overall_rate * 100) AS avg_completion_percent,
    MAX(CASE WHEN is_return_visit = 1 THEN 1 ELSE 0 END) AS return_user
  FROM analytics_sessions
  GROUP BY anonymous_user_id
) AS user_rollup;

CREATE OR REPLACE VIEW analytics_phase2_session_detail_view AS
SELECT
  s.session_id,
  s.anonymous_user_id,
  s.dropoff_book_id,
  s.dropoff_chapter_no,
  s.dropoff_page_no,
  s.dropoff_line_index,
  s.dropoff_position_key,
  s.dropoff_word,
  s.last_content_event_at,
  sc.book_id,
  sc.chapter_no,
  sc.unique_tapped_positions,
  sc.total_interactive_positions,
  sc.completion_rate,
  sc.is_completed
FROM analytics_sessions s
LEFT JOIN analytics_session_chapters sc
  ON s.session_id = sc.session_id;

CREATE OR REPLACE VIEW analytics_testing_events_view AS
SELECT
  id,
  event_type,
  session_id,
  anonymous_user_id,
  book_id,
  chapter_no,
  content_version,
  page_no,
  position_key,
  word,
  JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.dwell_ms')) AS dwell_ms,
  JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.audio_mode')) AS audio_mode,
  JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.target_book_id')) AS target_book_id,
  JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.target_chapter_no')) AS target_chapter_no,
  JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.source')) AS failure_source,
  JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.message')) AS failure_message,
  JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.reason')) AS interruption_reason,
  event_at,
  created_at
FROM analytics_events
WHERE event_type IN (
  'page_view',
  'word_tap',
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
);

CREATE OR REPLACE VIEW analytics_testing_summary_view AS
SELECT
  session_id,
  anonymous_user_id,
  COALESCE(book_id, JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.target_book_id'))) AS book_id,
  COALESCE(chapter_no, CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.target_chapter_no')) AS UNSIGNED)) AS chapter_no,
  COUNT(CASE WHEN event_type = 'word_tap' THEN 1 END) AS total_word_taps,
  COUNT(DISTINCT CASE WHEN event_type = 'word_tap' THEN position_key END) AS unique_tapped_positions,
  SUM(CASE WHEN event_type = 'page_dwell' THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.dwell_ms')) AS UNSIGNED) ELSE 0 END) AS total_page_dwell_ms,
  COUNT(CASE WHEN event_type = 'audio_play_started' THEN 1 END) AS audio_started_count,
  COUNT(CASE WHEN event_type = 'audio_play_completed' THEN 1 END) AS audio_completed_count,
  MAX(CASE WHEN event_type = 'start_reading_click' THEN 1 ELSE 0 END) AS clicked_start_reading,
  MAX(CASE WHEN event_type = 'onboarding_to_story_click' THEN 1 ELSE 0 END) AS clicked_onboarding_to_story,
  COUNT(CASE WHEN event_type = 'next_chapter_click' THEN 1 END) AS next_chapter_clicks,
  MAX(CASE WHEN event_type = 'content_load_failed' THEN 1 ELSE 0 END) AS had_content_load_failure,
  MAX(CASE WHEN event_type = 'session_interrupted' THEN 1 ELSE 0 END) AS had_session_interruption,
  MIN(event_at) AS first_event_at,
  MAX(event_at) AS last_event_at
FROM analytics_events
WHERE event_type IN (
  'word_tap',
  'page_dwell',
  'audio_play_started',
  'audio_play_completed',
  'start_reading_click',
  'onboarding_to_story_click',
  'next_chapter_click',
  'content_load_failed',
  'session_interrupted'
)
GROUP BY
  session_id,
  anonymous_user_id,
  COALESCE(book_id, JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.target_book_id'))),
  COALESCE(chapter_no, CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.target_chapter_no')) AS UNSIGNED));
