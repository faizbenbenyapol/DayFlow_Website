-- =====================================================
-- migrate_perf_indexes.sql — indexes for the query rewrites
--
-- The date filters were rewritten from DATE()/DATE_FORMAT() wrappers to plain
-- range comparisons so MySQL can use an index. focus_sessions was the one table
-- whose filter columns had no composite index to use.
-- =====================================================

-- getStats() filters user_id = ? AND type = 'work' AND completed_at >= ? < ?
-- Equality columns first, then the range column.
CREATE INDEX IF NOT EXISTS idx_focus_user_type_date
    ON focus_sessions (user_id, type, completed_at);

-- Global search orders by id DESC per user; files/quick_items/bookmarks are
-- scanned by user_id only today.
CREATE INDEX IF NOT EXISTS idx_files_user_id
    ON files (user_id, id);

CREATE INDEX IF NOT EXISTS idx_subs_user_id
    ON subscriptions (user_id, id);

CREATE INDEX IF NOT EXISTS idx_projects_user_id
    ON projects (user_id, id);
