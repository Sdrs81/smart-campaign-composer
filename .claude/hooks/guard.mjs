#!/usr/bin/env node
// PreToolUse guard for the Bash and PowerShell tools.
//
// It blocks commands by their effect, not by the tool that produces it: recursive
// deletes, pushes to main, reads of real env files, leaked API keys, destructive docker
// operations. It fails closed: a command it cannot parse safely is blocked.
// Exit code 2 makes Claude Code reject the call and show stderr to the model.
import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { API_KEY_PATTERN, writeHookLog } from './hook-log.mjs';

const EXIT_BLOCK = 2;
const MAX_SHELL_DEPTH = 3; // bash -c "bash -c '...'" nesting we are willing to unwrap
const GIT_TIMEOUT_MS = 5_000;
const MAX_DIFF_BYTES = 64 * 1024 * 1024;

const PROTECTED_BRANCHES = new Set(['main', 'master']);
const API_KEY_VARIABLE = /(?:\$\{?|\$env:|%)ANTHROPIC_API_KEY/i;

// Shell features whose result we cannot predict statically.
const SUBSTITUTION = /\$\(|\$\{|`|\\\r?\n/;
// Verbs that delete, read, copy, push or commit: only for these an unparseable
// command is blocked, so harmless commands with a backtick are not.
const RISKY_WORD =
  /\b(?:rm|rmdir|rd|del|erase|remove-item|ri|git|docker|docker-compose|cat|type|gc|get-content|cp|copy|copy-item|mv|move|move-item|grep|egrep|rg|select-string|sls|sed|awk|head|tail|less|more|source|base64|strings|xxd|od|findstr|env|printenv|tee|eval|iex|invoke-expression)\b/i;

// Reads of the process environment from inside other runtimes or PowerShell drives.
const ENV_READ_PATTERN =
  /\$env:|(?:^|[\s"'(,;|])env:|\[(?:system\.)?environment\]|process\.env|os\.environ|\bgetenv\b|\$_ENV|\$_SERVER/i;
// `Get-ChildItem -Recurse | Select-String ...` walks into .env files.
const POWERSHELL_RECURSIVE_SEARCH =
  /(?:get-childitem|\bgci\b|\bdir\b|\bls\b)[^|]*\s-r(?:e(?:c(?:u(?:r(?:s(?:e)?)?)?)?)?)?\b[^|]*\|\s*(?:select-string|sls|get-content|gc|cat|type)\b/i;

const SHELLS = new Set(['bash', 'sh', 'zsh', 'dash', 'pwsh', 'powershell', 'cmd']);
// Commands whose arguments contain another command (`docker compose exec app rm -rf x`).
const WRAPPERS = new Set([
  'sudo', 'command', 'time', 'nohup', 'xargs', 'exec', 'env', 'docker', 'docker-compose',
  'find', 'timeout', 'nice', 'start', 'call', 'watch', 'builtin',
]);
const READERS = new Set([
  'cat', 'type', 'gc', 'get-content', 'more', 'less', 'head', 'tail', 'cp', 'copy', 'copy-item',
  'cpi', 'mv', 'move', 'move-item', 'grep', 'egrep', 'fgrep', 'rg', 'select-string', 'sls', 'sed',
  'awk', 'base64', 'strings', 'xxd', 'od', 'findstr', 'tee', 'source', '.', 'bat', 'nl', 'tac',
  'sort', 'cut', 'paste', 'diff', 'cmp',
]);
const REMOVE_VERBS = new Set(['rm', 'rmdir', 'rd', 'del', 'erase', 'remove-item', 'ri']);
const EVAL_VERBS = new Set(['eval', 'iex', 'invoke-expression']);
const SHELL_COMMAND_FLAG = /^(?:-c|-lc|-ec|-command|\/c|\/k)$/i;
const SHELL_ENCODED_FLAG = /^-e(?:nc(?:odedcommand)?)?$/i;

// Names a glob such as `.e*` could expand to; used to catch `cat .e*`.
const ENV_FILE_SAMPLES = ['.env', '.env.local', '.env.testing', '.env.production', '.env.staging'];
const ENV_EXAMPLE = '.env.example';

const ALLOW = Object.freeze({ blocked: false });
const block = (rule, reason) => ({ blocked: true, rule, reason });

/** Splits a command into segments of tokens, honouring quotes. Returns null on an unbalanced quote. */
export function scan(command) {
  const segments = [];
  let tokens = [];
  let current = '';
  let inToken = false;
  let quote = null;

  const endToken = () => {
    if (inToken) {
      tokens.push(current);
      current = '';
      inToken = false;
    }
  };
  const endSegment = () => {
    endToken();
    if (tokens.length > 0) segments.push(tokens);
    tokens = [];
  };

  for (let i = 0; i < command.length; i++) {
    const ch = command[i];
    if (quote) {
      if (ch === quote) {
        quote = null;
      } else if (ch === '\\' && quote === '"' && command[i + 1] === '"') {
        current += '"';
        i++;
      } else {
        current += ch;
      }
      continue;
    }
    if (ch === "'" || ch === '"') {
      quote = ch;
      inToken = true;
    } else if (ch === '\n' || ch === ';') {
      endSegment();
    } else if (/\s/.test(ch)) {
      endToken();
    } else if (ch === '|') {
      endSegment();
      if (command[i + 1] === '|') i++;
    } else if (ch === '&') {
      const previous = command[i - 1];
      if (previous === '>' || previous === '<') {
        current += ch; // `2>&1` is a redirection, not a command separator
        inToken = true;
      } else {
        endSegment();
        if (command[i + 1] === '&') i++;
      }
    } else {
      current += ch;
      inToken = true;
    }
  }
  if (quote) return null;
  endSegment();
  return segments;
}

const verbOf = (token) =>
  token.split(/[\\/]/).pop().toLowerCase().replace(/\.(?:exe|cmd|bat|ps1)$/, '');

// Words inside a token, so `sh -c "cat .env"` and `--env-file=.env` are inspected too.
const subwords = (token) => token.split(/[\s;&|<>=:,()'"`]+/).filter(Boolean);

function candidatesOf(argv) {
  const verb = verbOf(argv[0]);
  const candidates = [argv];
  if (SHELLS.has(verb) || WRAPPERS.has(verb)) {
    for (let i = 1; i < argv.length; i++) candidates.push(argv.slice(i));
  }
  return candidates;
}

function globToRegExp(glob) {
  let source = '';
  for (const ch of glob) {
    if (ch === '*') source += '.*';
    else if (ch === '?') source += '.';
    else if (ch === '[' || ch === ']') source += ch;
    else source += ch.replace(/[.+^${}()|\\]/g, '\\$&');
  }
  try {
    return new RegExp(`^${source}$`, 'i');
  } catch {
    return /(?!)/; // an unterminated `[` is a literal, which matches no env file
  }
}

function isRealEnvFileName(name, readerInvolved) {
  const lower = name.toLowerCase();
  if (lower === ENV_EXAMPLE) return false;
  if (!/[*?[]/.test(lower)) return lower.startsWith('.env');
  // A leading `*` or `?` also matches dotfiles on Windows, but only matters when the
  // command reads file contents.
  if (!lower.startsWith('.') && !readerInvolved) return false;
  const pattern = globToRegExp(lower);
  return ENV_FILE_SAMPLES.some((sample) => pattern.test(sample));
}

function checkEnvFileAccess(argv) {
  if (verbOf(argv[0]) === 'git' && argv.includes('check-ignore')) return null;
  const readerInvolved = candidatesOf(argv).some((c) => READERS.has(verbOf(c[0])));
  for (const token of argv) {
    for (const word of subwords(token)) {
      if (/^\/proc\/[^/]+\/environ$/.test(word)) {
        return block('env-dump', 'reading /proc/*/environ exposes secrets');
      }
      const name = word.split(/[\\/]/).pop();
      if (name && isRealEnvFileName(name, readerInvolved)) {
        return block(
          'env-file',
          `real env files must not be accessed (${name}); only .env.example is allowed`,
        );
      }
    }
  }
  return null;
}

function isRecursiveRemove(args) {
  return args.some((arg) => {
    const lower = arg.toLowerCase();
    if (lower === '--recursive' || lower === '/s') return true;
    // PowerShell -Recurse, including its unambiguous abbreviations.
    if (/^-r(?:e(?:c(?:u(?:r(?:s(?:e)?)?)?)?)?)?$/i.test(arg)) return true;
    // Unix short-flag clusters (-rf, -fR); the length cap keeps -Force/-Confirm out.
    return /^-[a-zA-Z]{1,4}$/.test(arg) && /[rR]/.test(arg);
  });
}

function parseGit(args) {
  let dir = null;
  let i = 0;
  while (i < args.length && args[i].startsWith('-')) {
    if (args[i] === '-C') {
      dir = args[i + 1] ?? null;
      i += 2;
    } else if (['-c', '--git-dir', '--work-tree', '--namespace', '--exec-path'].includes(args[i])) {
      i += 2;
    } else {
      i += 1;
    }
  }
  return { dir, sub: args[i], rest: args.slice(i + 1) };
}

function checkGit(args, state, ctx) {
  const { dir, sub, rest } = parseGit(args);
  const currentBranch = () => (state.branch !== undefined ? state.branch : ctx.branch(dir));

  if (sub === 'switch' || sub === 'checkout') {
    const target = rest.find((a) => !a.startsWith('-'));
    const creating = rest.some((a) => ['-b', '-B', '-c', '-C'].includes(a));
    if (target && (PROTECTED_BRANCHES.has(target) || sub === 'switch' || creating)) {
      state.branch = target; // later segments in the same command see the new branch
    }
    return null;
  }

  if (sub === 'push') {
    const flags = rest.filter((a) => a.startsWith('-'));
    const positional = rest.filter((a) => !a.startsWith('-'));
    const forced =
      flags.some((f) => /^--force/.test(f) || (/^-[a-zA-Z]+$/.test(f) && f.includes('f'))) ||
      positional.some((p) => p.startsWith('+'));
    if (forced) return block('force-push', 'force push is not allowed');
    if (flags.includes('--mirror') || flags.includes('--all')) {
      return block('push-target-main', '--mirror and --all would also push main');
    }
    const targetsMain = positional.some((p) =>
      p
        .replace(/^\+/, '')
        .split(':')
        .some((ref) => PROTECTED_BRANCHES.has(ref.replace(/^refs\/heads\//, ''))),
    );
    if (targetsMain) return block('push-target-main', 'pushing to main is not allowed');
    const branch = currentBranch();
    if (branch === null) return block('unknown-branch', 'could not determine the current branch');
    if (PROTECTED_BRANCHES.has(branch)) {
      return block('push-from-main', 'pushing while on main is not allowed; use a phase branch');
    }
    return null;
  }

  if (sub === 'commit') {
    const branch = currentBranch();
    if (branch === null) return block('unknown-branch', 'could not determine the current branch');
    if (PROTECTED_BRANCHES.has(branch)) {
      return block('commit-on-main', 'committing on main is not allowed; use a phase branch');
    }
    const includesUnstaged = rest.some((a) => a === '--all' || /^-[a-zA-Z]*a[a-zA-Z]*$/.test(a));
    const leaked = ctx.stagedSecret(includesUnstaged, dir);
    if (leaked === null) {
      return block('unknown-diff', 'could not read the diff to check it for API keys');
    }
    if (leaked) return block('secret-in-commit', 'an Anthropic API key (sk-ant-) is in the diff');
  }
  return null;
}

function checkDocker(verb, args) {
  const destructive = block('docker-destructive', 'this docker command can delete data volumes');
  const volumeFlag = (a) => a === '--volumes' || /^-[a-zA-Z]*v[a-zA-Z]*$/.test(a);
  if (args.includes('down') && args.some(volumeFlag)) return destructive;
  const volumeAt = args.indexOf('volume');
  if (volumeAt >= 0 && ['rm', 'remove', 'prune'].includes(args[volumeAt + 1])) return destructive;
  const systemAt = args.indexOf('system');
  if (systemAt >= 0 && args[systemAt + 1] === 'prune') return destructive;
  if (args.includes('inspect')) {
    return block('env-dump', 'docker inspect prints container environment variables');
  }
  if ((verb === 'docker-compose' || args.includes('compose')) && args.includes('config')) {
    return block('env-dump', 'docker compose config prints values resolved from .env');
  }
  return null;
}

function checkRecursiveSearch(verb, args) {
  const lowerArgs = args.map((a) => a.toLowerCase());
  const grepLike = ['grep', 'egrep', 'fgrep'].includes(verb);
  if (grepLike && args.some((a) => a === '--recursive' || /^-[a-zA-Z]*[rR][a-zA-Z]*$/.test(a))) {
    return block('recursive-search', 'recursive grep reads .env files; use the Grep tool');
  }
  if (verb === 'rg' && args.some((a) => /^--(?:no-ignore|hidden)/.test(a) || /^-u+$/.test(a))) {
    return block('recursive-search', 'rg with ignore rules disabled reads .env files');
  }
  if (verb === 'findstr' && lowerArgs.includes('/s')) {
    return block('recursive-search', 'recursive findstr reads .env files; use the Grep tool');
  }
  return null;
}

function checkEnvDump(verb, args) {
  const dump = (why) => block('env-dump', why);
  if (verb === 'printenv') return dump('printenv exposes secrets');
  if (verb === 'env' && args.every((a) => a.startsWith('-'))) return dump('env exposes secrets');
  if (verb === 'set' && args.length === 0) return dump('set exposes secrets');
  if (verb === 'export' && (args.length === 0 || args.includes('-p'))) return dump('export lists secrets');
  if ((verb === 'declare' || verb === 'typeset') && args.some((a) => a === '-x' || a === '-p')) {
    return dump('declare lists secrets');
  }
  return null;
}

function checkShellWrapper(verb, argv, ctx, depth) {
  if (!SHELLS.has(verb)) return null;
  for (let i = 1; i < argv.length; i++) {
    if (SHELL_ENCODED_FLAG.test(argv[i])) {
      return block('unparseable', 'encoded commands cannot be inspected');
    }
    if (SHELL_COMMAND_FLAG.test(argv[i]) && argv[i + 1] !== undefined) {
      const inner = evaluate(argv[i + 1], ctx, depth + 1);
      if (inner.blocked) return inner;
    }
  }
  return null;
}

function checkCandidate(argv, state, ctx, depth) {
  const verb = verbOf(argv[0]);
  const args = argv.slice(1);

  if (EVAL_VERBS.has(verb)) return block('unparseable', `${verb} runs text that cannot be inspected`);
  if (REMOVE_VERBS.has(verb) && isRecursiveRemove(args)) {
    return block('recursive-delete', 'recursive deletes are not allowed');
  }
  if (verb === 'find' && args.includes('-delete')) {
    return block('recursive-delete', 'find -delete is not allowed');
  }
  if (verb === 'git') return checkGit(args, state, ctx);
  if (verb === 'docker' || verb === 'docker-compose') return checkDocker(verb, args);
  return (
    checkRecursiveSearch(verb, args) ??
    checkEnvDump(verb, args) ??
    checkShellWrapper(verb, argv, ctx, depth)
  );
}

/**
 * Decides whether a shell command may run.
 * `ctx.branch(dir)` returns the current branch or null; `ctx.stagedSecret(all, dir)` returns
 * true/false, or null when the diff could not be read (treated as a block).
 */
export function evaluate(command, ctx, depth = 0) {
  if (typeof command !== 'string') return block('invalid-input', 'the command is not a string');
  if (command.trim() === '') return ALLOW;
  if (depth > MAX_SHELL_DEPTH) return block('unparseable', 'shell commands are nested too deeply');

  if (API_KEY_PATTERN.test(command)) {
    return block('secret-in-command', 'the command contains an Anthropic API key');
  }
  if (API_KEY_VARIABLE.test(command)) {
    return block('env-dump', 'expanding ANTHROPIC_API_KEY would expose the key');
  }
  if (ENV_READ_PATTERN.test(command)) {
    return block('env-dump', 'reading environment variables could expose secrets');
  }
  if (POWERSHELL_RECURSIVE_SEARCH.test(command)) {
    return block('recursive-search', 'a recursive file search would read .env files; use the Grep tool');
  }

  const risky = RISKY_WORD.test(command);
  if (risky && SUBSTITUTION.test(command)) {
    return block('unparseable', 'command substitution, backticks or line continuation cannot be inspected');
  }
  const segments = scan(command);
  if (segments === null) {
    return risky ? block('unparseable', 'unbalanced quote') : ALLOW;
  }

  const state = { branch: undefined };
  for (const argv of segments) {
    const hit =
      checkEnvFileAccess(argv) ??
      candidatesOf(argv)
        .map((candidate) => checkCandidate(candidate, state, ctx, depth))
        .find(Boolean);
    if (hit) return hit;
  }
  return ALLOW;
}

export function hasAddedSecret(diff) {
  return diff
    .split('\n')
    .some((line) => line.startsWith('+') && !line.startsWith('+++') && API_KEY_PATTERN.test(line));
}

function makeContext(cwd) {
  const git = (dir, args) =>
    execFileSync('git', args, {
      cwd: dir ? resolve(cwd, dir) : cwd,
      encoding: 'utf8',
      timeout: GIT_TIMEOUT_MS,
      maxBuffer: MAX_DIFF_BYTES,
      stdio: ['ignore', 'pipe', 'ignore'],
    });
  return {
    branch(dir) {
      try {
        return git(dir, ['branch', '--show-current']).trim();
      } catch {
        return null; // unknown state is blocked by the caller (fail closed)
      }
    },
    stagedSecret(includeUnstaged, dir) {
      try {
        const staged = git(dir, ['diff', '--cached']);
        const unstaged = includeUnstaged ? git(dir, ['diff']) : '';
        return hasAddedSecret(staged + unstaged);
      } catch {
        return null;
      }
    },
  };
}

const readStdin = () =>
  new Promise((resolveInput, reject) => {
    let data = '';
    process.stdin.setEncoding('utf8');
    process.stdin.on('data', (chunk) => (data += chunk));
    process.stdin.on('end', () => resolveInput(data));
    process.stdin.on('error', reject);
  });

async function main() {
  let input;
  try {
    input = JSON.parse(await readStdin());
  } catch {
    process.stderr.write('guard: hook input is not valid JSON; blocking (fail closed)\n');
    process.exitCode = EXIT_BLOCK;
    return;
  }

  const tool = input?.tool_name;
  if (tool !== 'Bash' && tool !== 'PowerShell') return;

  const command = input?.tool_input?.command;
  const cwd = input?.cwd ?? process.cwd();
  const projectDir = process.env.CLAUDE_PROJECT_DIR ?? cwd;

  let verdict;
  try {
    verdict = evaluate(command, makeContext(cwd));
  } catch (error) {
    verdict = block('internal-error', `guard failed (${error.message}); blocking (fail closed)`);
  }
  if (!verdict.blocked) return;

  writeHookLog(projectDir, 'guard', 'BLOCK', verdict.rule, tool, command);
  process.stderr.write(`Blocked by guard hook [${verdict.rule}]: ${verdict.reason}\n`);
  process.exitCode = EXIT_BLOCK;
}

if (process.argv[1] && resolve(process.argv[1]) === resolve(fileURLToPath(import.meta.url))) {
  await main();
}
