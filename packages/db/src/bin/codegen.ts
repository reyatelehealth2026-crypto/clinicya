#!/usr/bin/env node
import { runCodegenCli } from '../codegen';

runCodegenCli().catch((err) => {
  console.error(err);
  process.exitCode = 1;
});
