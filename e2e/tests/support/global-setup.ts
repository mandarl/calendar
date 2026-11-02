import { mkdirSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { readFileSync, existsSync } from 'node:fs';

import { baselineFile } from './db';
import { currentDataset } from './dataset';

/**
 * Runs once before the suite when the harness environment is present (DB_HOST set). Against a plain dev stack
 * (no DB_HOST) the original behaviour is kept: tests simply hit BASE_URL.
 */
export default async function globalSetup(): Promise<void> {
  if (!process.env.DB_HOST) return;
  const dataset = currentDataset();
  await dataset.load();

  const dir = process.env.BASELINE_DIR;
  if (dir) {
    mkdirSync(dir, { recursive: true });
    const sha = (f: string) =>
      existsSync(baselineFile(f)) ? createHash('sha256').update(readFileSync(baselineFile(f))).digest('hex') : null;
    writeFileSync(
      baselineFile('manifest.json'),
      JSON.stringify(
        {
          baselineId: dir.split('/').pop(),
          dataset: dataset.name,
          anchor: process.env.ANCHOR,
          baseUrl: process.env.BASE_URL,
          schemaSha256: sha('00-schema-lookups.sql'),
          datasetSha256: sha('10-synthetic.sql'),
          playwright: process.env.PW_VERSION ?? '1.60.0',
          note: 'Image/revision labels are added by run.sh (manifest.image.json).',
        },
        null,
        2,
      ),
    );
  }
}
