-- User Language Preference Migration
-- Run this migration to add language preference support to users

ALTER TABLE users ADD COLUMN preferred_language VARCHAR(5) DEFAULT 'nl' AFTER email;
