#!/usr/bin/env node
// Masks personal data in Claude session exports before they are committed.
//
//   node scripts/scrub-sessions.mjs                 mask every ai-sessions/*.md in place
//   node scripts/scrub-sessions.mjs --check         report what is left to mask, change nothing
//   node scripts/scrub-sessions.mjs [--check] FILE  limit the run to the given files
//
// Exit codes: 0 clean (or scrubbed), 1 --check found something, 2 usage or read error.
// Reports never print the matched text, only the rule and the line, so a leaked value is
// not copied into the terminal or a CI log.
import { readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const SESSIONS_DIR = join(ROOT, 'ai-sessions');
const EXIT_FOUND = 1;
const EXIT_ERROR = 2;

// Addresses that are public by design and safe to keep in an export.
const KEEP_EMAIL = /^(?:noreply@(?:anthropic|github)\.com|[^@\s]+@example\.(?:com|org|net))$/i;
// Windows account folders that are not a person, and placeholders such as C:\Users\…
const KEEP_USER_FOLDER = new Set(['public', 'default', 'all users', '[user]']);
const PLACEHOLDER_FOLDER = /^[.\u2026*]+$/;

/** Each rule: a global pattern and a function from the matched text to its masked form. */
const RULES = [
  {
    name: 'api-key',
    pattern:
      /\b(?:sk-ant-[A-Za-z0-9_-]{20,}|sk-[A-Za-z0-9_-]{32,}|gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{30,}|AKIA[0-9A-Z]{16}|xox[abpr]-[A-Za-z0-9-]{10,}|AIza[0-9A-Za-z_-]{35})/g,
    mask: () => '[API_KEY]',
  },
  {
    name: 'bearer-token',
    pattern: /\bBearer[ \t]+[A-Za-z0-9._~+/=-]{20,}/g,
    mask: (match) => match.replace(/(Bearer[ \t]+).*/, '$1[TOKEN]'),
  },
  {
    name: 'email',
    pattern: /[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}/g,
    mask: (match) => (KEEP_EMAIL.test(match) ? match : '[EMAIL]'),
  },
  {
    // International format with a plus sign, e.g. +90 532 123 45 67. An added diff line such
    // as "+2026-11-04" is a date, not a phone number.
    name: 'phone',
    pattern: /\+(?!\d{4}-\d{2}-\d{2})\d[\d \t().-]{8,}\d/g,
    mask: () => '[PHONE]',
  },
  {
    // Turkish national format, e.g. 0532 123 45 67 or 0 (532) 123 45 67.
    name: 'phone',
    pattern: /(?<![\d.])(?:0090|0)[ \t-]?\(?[2-5]\d{2}\)?[ \t-]?\d{3}[ \t-]?\d{2}[ \t-]?\d{2}(?!\d)/g,
    mask: () => '[PHONE]',
  },
  {
    // US format, e.g. (555) 123-4567 or 555-123-4567.
    name: 'phone',
    pattern: /\(\d{3}\)[ \t]?\d{3}-\d{4}|\b\d{3}-\d{3}-\d{4}\b/g,
    mask: () => '[PHONE]',
  },
  {
    // The account folder after Users in C:\Users\x, C:\\Users\\x (JSON), C:/Users/x and /c/Users/x.
    name: 'user-path',
    pattern:
      /(?<=(?:[A-Za-z]:(?:\\\\|\\|\/)|\/[a-z]\/)Users(?:\\\\|\\|\/))[^\\/\s"'`<>|:*?]+/gi,
    mask: (match) =>
      KEEP_USER_FOLDER.has(match.toLowerCase()) || PLACEHOLDER_FOLDER.test(match) ? match : '[USER]',
  },
];

const lineAt = (text, offset) => text.slice(0, offset).split('\n').length;

/** Masks `text`; returns the new text and one finding per change (rule and line, never the value). */
export function scrub(text) {
  const findings = [];
  let output = text;
  for (const rule of RULES) {
    const current = output;
    output = current.replace(rule.pattern, (match, ...rest) => {
      const masked = rule.mask(match);
      if (masked !== match) {
        const offset = rest.find((value) => typeof value === 'number');
        findings.push({ rule: rule.name, line: lineAt(current, offset) });
      }
      return masked;
    });
  }
  return { text: output, findings };
}

function sessionFiles(args) {
  const explicit = args.filter((arg) => !arg.startsWith('--'));
  if (explicit.length > 0) return explicit.map((file) => resolve(file));
  return readdirSync(SESSIONS_DIR)
    .filter((name) => name.endsWith('.md'))
    .map((name) => join(SESSIONS_DIR, name));
}

function main(args) {
  const checkOnly = args.includes('--check');
  let files;
  try {
    files = sessionFiles(args);
  } catch (error) {
    console.error(`scrub-sessions: cannot list ${relative(ROOT, SESSIONS_DIR)}: ${error.message}`);
    return EXIT_ERROR;
  }

  let totalFindings = 0;
  for (const file of files) {
    let text;
    try {
      text = readFileSync(file, 'utf8');
    } catch (error) {
      console.error(`scrub-sessions: cannot read ${file}: ${error.message}`);
      return EXIT_ERROR;
    }
    const { text: masked, findings } = scrub(text);
    if (findings.length === 0) continue;

    totalFindings += findings.length;
    const name = relative(ROOT, file);
    if (checkOnly) {
      for (const { rule, line } of findings) console.log(`${name}:${line} ${rule}`);
    } else {
      writeFileSync(file, masked);
      const counts = findings.reduce((acc, { rule }) => ({ ...acc, [rule]: (acc[rule] ?? 0) + 1 }), {});
      console.log(`${name}: masked ${JSON.stringify(counts)}`);
    }
  }

  if (checkOnly) {
    console.log(totalFindings === 0 ? 'clean' : `${totalFindings} unmasked value(s) found`);
    return totalFindings === 0 ? 0 : EXIT_FOUND;
  }
  console.log(totalFindings === 0 ? 'nothing to mask' : `masked ${totalFindings} value(s)`);
  return 0;
}

if (process.argv[1] && resolve(process.argv[1]) === resolve(fileURLToPath(import.meta.url))) {
  process.exitCode = main(process.argv.slice(2));
}
