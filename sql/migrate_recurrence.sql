-- =====================================================
-- Migration: Recurring tasks and calendar events
--
-- Subscriptions already had a repeating cycle; tasks and calendar events did
-- not, so anything routine ("ประชุมทุกวันจันทร์") had to be typed in again
-- every time.
--
-- Two different strategies, matching how each one is used:
--   tasks           roll forward — finishing an occurrence schedules the next,
--                   the same way a subscription renews.
--   calendar_events expand on read — the calendar shows every future
--                   occurrence without storing a row for each one.
-- =====================================================

ALTER TABLE `tasks`
  ADD COLUMN IF NOT EXISTS `repeat_rule` ENUM('none','daily','weekly','monthly','yearly')
      NOT NULL DEFAULT 'none' AFTER `due_date`,
  ADD COLUMN IF NOT EXISTS `repeat_until` DATE DEFAULT NULL AFTER `repeat_rule`;

ALTER TABLE `calendar_events`
  ADD COLUMN IF NOT EXISTS `repeat_rule` ENUM('none','daily','weekly','monthly','yearly')
      NOT NULL DEFAULT 'none' AFTER `is_all_day`,
  ADD COLUMN IF NOT EXISTS `repeat_until` DATE DEFAULT NULL AFTER `repeat_rule`;

-- Expanding a month pulls every repeating event regardless of its start date,
-- so the lookup is by rule rather than by date.
CREATE INDEX IF NOT EXISTS idx_events_user_repeat
    ON calendar_events (user_id, repeat_rule);
