import { readFileSync } from 'node:fs';
import { join } from 'node:path';

import mysql from 'mysql2/promise';

/** All connection settings come from the environment, so the same helper later targets any restored dataset. */
export function connectionConfig() {
  return {
    host: process.env.DB_HOST ?? 'db',
    port: Number(process.env.DB_PORT ?? 3306),
    user: process.env.DB_USER ?? 'calendar',
    password: process.env.DB_PASSWORD ?? 'calendar',
    database: process.env.DB_NAME ?? 'dms_calendar',
    multipleStatements: true,
    charset: 'UTF8_GENERAL_CI',
  };
}

export async function withConnection<T>(fn: (c: mysql.Connection) => Promise<T>): Promise<T> {
  const conn = await mysql.createConnection(connectionConfig());
  try {
    return await fn(conn);
  } finally {
    await conn.end();
  }
}

/** Drop every table (works with a schema-scoped account, no DROP DATABASE needed) then load the given SQL files. */
export async function resetDatabase(sqlFiles: string[]): Promise<void> {
  await withConnection(async (c) => {
    await c.query('SET FOREIGN_KEY_CHECKS=0');
    const [rows] = await c.query<mysql.RowDataPacket[]>(
      'SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()',
    );
    for (const r of rows) await c.query(`DROP TABLE IF EXISTS \`${r.t}\``);
    for (const f of sqlFiles) await c.query(readFileSync(f, 'utf8'));
    await c.query('SET FOREIGN_KEY_CHECKS=1');
  });
}

export const baselineFile = (name: string) => join(process.env.BASELINE_DIR ?? '.', name);

export async function query<T = mysql.RowDataPacket>(sql: string, params: unknown[] = []): Promise<T[]> {
  return withConnection(async (c) => {
    const [rows] = await c.query(sql, params);
    return rows as T[];
  });
}

/** Deterministic text dump of the given tables (ordered by primary key) for equality checks between resets. */
export async function snapshotTables(tables: string[]): Promise<string> {
  return withConnection(async (c) => {
    const out: string[] = [];
    for (const t of tables) {
      const [rows] = await c.query(`SELECT * FROM \`${t}\` ORDER BY 1`);
      out.push(`## ${t}\n${JSON.stringify(rows)}`);
    }
    return out.join('\n');
  });
}
