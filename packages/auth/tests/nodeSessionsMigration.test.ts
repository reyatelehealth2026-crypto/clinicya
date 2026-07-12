import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { splitSqlStatements } from '@reya/db';

const MIGRATION_PATH = join(
  __dirname,
  '..',
  '..',
  'db',
  'migrations',
  'master',
  'migration_2026-07-12_node_sessions.sql'
);

describe('migrations/master/migration_2026-07-12_node_sessions.sql', () => {
  const sqlText = readFileSync(MIGRATION_PATH, 'utf8');
  const statements = splitSqlStatements(sqlText);

  it('parses into at least one non-empty, semicolon-terminated statement using @reya/db\'s own splitter', () => {
    expect(statements.length).toBeGreaterThan(0);
    for (const statement of statements) {
      expect(statement.trim().length).toBeGreaterThan(0);
    }
  });

  it('contains a CREATE TABLE IF NOT EXISTS `node_sessions` statement', () => {
    const hasCreateTable = statements.some((s) => /CREATE TABLE IF NOT EXISTS `node_sessions`/i.test(s));
    expect(hasCreateTable).toBe(true);
  });

  it('never uses a DELIMITER statement (repo has no triggers/stored procedures) — checked on the parsed statements, not the free-text header comments that document this rule in prose', () => {
    for (const statement of statements) {
      expect(statement).not.toMatch(/^DELIMITER\b/im);
    }
  });

  it('never declares a TRIGGER or PROCEDURE', () => {
    for (const statement of statements) {
      expect(statement).not.toMatch(/CREATE\s+(TRIGGER|PROCEDURE)/i);
    }
  });

  it('every statement is CREATE-TABLE-IF-NOT-EXISTS-idempotent-safe or a benign USE statement', () => {
    for (const statement of statements) {
      const isCreateIfNotExists = /^CREATE TABLE IF NOT EXISTS/i.test(statement);
      const isUse = /^USE\s+`/i.test(statement);
      expect(isCreateIfNotExists || isUse).toBe(true);
    }
  });

  it('declares the two revoke-in-one-query indexes (realm, admin_user_id) and (realm, platform_user_id)', () => {
    expect(sqlText).toMatch(/idx_node_sessions_realm_admin_user.*\(`realm`, `admin_user_id`\)/);
    expect(sqlText).toMatch(/idx_node_sessions_realm_platform_user.*\(`realm`, `platform_user_id`\)/);
  });

  it('declares an expiry-sweep GC index', () => {
    expect(sqlText).toMatch(/idx_node_sessions_expires_at.*\(`expires_at`\)/);
  });
});
