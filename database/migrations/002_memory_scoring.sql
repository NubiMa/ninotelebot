-- Migration 002: Add scoring metadata to memories table
ALTER TABLE memories ADD COLUMN tags TEXT DEFAULT NULL;
ALTER TABLE memories ADD COLUMN relevance_score REAL DEFAULT 0.5;
ALTER TABLE memories ADD COLUMN recall_count INTEGER DEFAULT 0;
ALTER TABLE memories ADD COLUMN last_recalled DATETIME DEFAULT NULL;
