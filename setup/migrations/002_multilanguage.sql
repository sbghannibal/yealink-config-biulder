-- Multi-Language Migration
-- Run this migration to add language preference support to admins

ALTER TABLE admins ADD COLUMN language VARCHAR(5) DEFAULT 'nl' AFTER email;
