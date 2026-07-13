#!/usr/bin/env node
import { runMigrateAllCli } from '../migrateAll';

runMigrateAllCli().catch((err) => {
  console.error(err);
  process.exitCode = 1;
});
