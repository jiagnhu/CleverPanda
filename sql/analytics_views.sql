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
