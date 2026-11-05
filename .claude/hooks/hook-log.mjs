// Shared by the hooks: one tab-separated line per event in .claude/logs/hooks.log,
// so a reviewer can see that a hook actually ran.
import { appendFileSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';

export const API_KEY_PATTERN = /sk-ant-[A-Za-z0-9_-]{20,}/;

const FIELD_LIMIT = 200;
const API_KEY_PATTERN_GLOBAL = new RegExp(API_KEY_PATTERN.source, 'g');

const clean = (value) =>
  String(value ?? '')
    .replace(API_KEY_PATTERN_GLOBAL, 'sk-ant-***')
    .replace(/\s+/g, ' ')
    .slice(0, FIELD_LIMIT);

/** Appends `time  hook  level  fields...`. Never throws: logging must not change a hook's verdict. */
export function writeHookLog(projectDir, hook, level, ...fields) {
  try {
    const logDir = join(projectDir, '.claude', 'logs');
    mkdirSync(logDir, { recursive: true });
    const line = [new Date().toISOString(), hook, level, ...fields.map(clean)].join('\t');
    appendFileSync(join(logDir, 'hooks.log'), `${line}\n`);
  } catch (error) {
    process.stderr.write(`${hook}: could not write hooks.log: ${error.message}\n`);
  }
}
