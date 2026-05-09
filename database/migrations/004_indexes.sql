-- Migration 004: Performance indexes
CREATE INDEX IF NOT EXISTS idx_memories_created  ON memories(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_memories_score    ON memories(relevance_score DESC);
CREATE INDEX IF NOT EXISTS idx_reminders_trigger ON reminders(trigger_time, is_sent, is_cancelled);
CREATE INDEX IF NOT EXISTS idx_conv_session      ON conversations(session_id, id DESC);
CREATE INDEX IF NOT EXISTS idx_conv_importance   ON conversations(importance DESC, id DESC);
