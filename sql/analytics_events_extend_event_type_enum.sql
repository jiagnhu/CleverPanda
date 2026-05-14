-- =============================================================================
-- analytics_events.event_type：线上扩展 / 修复
-- =============================================================================
-- 背景：event_type 为 ENUM 时，若代码写入新类型会 INSERT 失败（接口 db_query_failed）。
-- 执行 ENUM 的 MODIFY 若报 #1265（Data truncated for column 'event_type' at row N）：
--   表中第 N 行（及同类型其它行）的 event_type 字符串，不在你写进 ENUM(...) 的列表里
--   （历史遗留类型、手工数据、或曾用旧脚本建表等）。
-- 已知遗留值示例：`session_target_completed`（当前仓库代码已不再写入，但表里可能仍有旧行）。
--
-- 建议先跑下面 SELECT，看实际出现过哪些 event_type：
-- =============================================================================

SELECT event_type, COUNT(*) AS n
FROM analytics_events
GROUP BY event_type
ORDER BY n DESC;

-- =============================================================================
-- 方案 B（推荐）：改为 VARCHAR，保留各行原有字符串，避免 ENUM 与代码反复漂移
-- =============================================================================

ALTER TABLE analytics_events
  MODIFY COLUMN event_type VARCHAR(64) NOT NULL;

-- =============================================================================
-- 方案 A（可选）：坚持 ENUM 时，必须把「上面 SELECT 里出现的每一个值」都写进列表，
-- 且与 api/analytics.php / 说明.md 一致；否则仍会 #1265。
-- 需要时再取消下面整段注释并执行（不要与上面的 VARCHAR 同库连续执行两次列类型变更
-- 若已执行 B 则不必再执行 A）。
-- =============================================================================
--
-- ALTER TABLE analytics_events
--   MODIFY COLUMN event_type ENUM(
--     'session_start',
--     'return_visit',
--     'page_view',
--     'word_tap',
--     'page_dwell',
--     'audio_play_started',
--     'audio_play_completed',
--     'start_reading_click',
--     'onboarding_to_story_click',
--     'next_chapter_click',
--     'content_load_failed',
--     'session_interrupted',
--     'session_target_completed',
--     'chapter_complete',
--     'parent_feedback',
--     'more_chapters_response',
--     'session_end'
--   ) NOT NULL;
