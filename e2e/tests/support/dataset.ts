import { existsSync } from 'node:fs';

import { CATALOG } from '../../fixtures/synthetic/catalog';
import { baselineFile, resetDatabase } from './db';
import { clearMail, resetFakes, resetFiles } from './io';

/**
 * A dataset knows how to load itself onto the frozen schema and how to name the representative records tests need.
 * `synthetic` is implemented now. A later `snapshot` dataset (a restored, masked production copy; separately
 * approved) will implement the same interface by loading its dump and discovering representative ids by query.
 * Tests that depend on the exact synthetic catalog are tagged @synthetic.
 */
export interface Dataset {
  name: string;
  /** Reset DB (+ files, mail, fakes) to the pristine state of this dataset. */
  load(): Promise<void>;
  /** Event ids for each registration/visibility variant. */
  events: Record<string, { id: number; name: string }>;
}

const synthetic: Dataset = {
  name: 'synthetic',
  async load() {
    const files = [baselineFile('00-schema-lookups.sql'), baselineFile('10-synthetic.sql')];
    for (const f of files) {
      if (!existsSync(f)) throw new Error(`Missing baseline file ${f}; generate it first (see docs/testing.md).`);
    }
    await resetDatabase(files);
    resetFiles();
    await clearMail();
    await resetFakes();
  },
  events: CATALOG.events,
};

const datasets: Record<string, Dataset> = { synthetic };

export function currentDataset(): Dataset {
  const name = process.env.DATASET ?? 'synthetic';
  const ds = datasets[name];
  if (!ds) throw new Error(`Unknown DATASET "${name}". Known: ${Object.keys(datasets).join(', ')}`);
  return ds;
}
