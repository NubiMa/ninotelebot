-- Migration 003: Add session tracking and importance to conversations
ALTER TABLE conversations ADD COLUMN importance INTEGER DEFAULT 0;
ALTER TABLE conversations ADD COLUMN session_id TEXT DEFAULT NULL;
