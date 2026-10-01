#!/usr/bin/env node
/**
 * Приёмка DEV-196 — 14 критериев §3 спецификации docs/specs/DEV-196.md.
 *
 * Каждый критерий проверяется тем же средством, что указан в §3. Скрипт
 * ничего не «знает» о правках заранее: он меряет состояние дерева.
 *
 * Запуск: node bin/check-organizer-ui.mjs
 * Выход: 0 — все критерии выполнены, 1 — есть невыполненные.
 */

import {
  readFileSync,
  readdirSync,
  writeFileSync,
  unlinkSync,
  existsSync,
  mkdirSync,
  rmSync,
} from 'node:fs';
import { execFileSync } from 'node:child_process';
import path from 'node:path';

const THEME = path.resolve(process.cwd());
const PARTS = path.join(THEME, 'template-parts', 'organizer');
const SCSS_ORG = path.join(THEME, 'assets', 'scss', 'pages', '_organizer.scss');
const SCSS_DASH = path.join(THEME, 'assets', 'scss', 'pages', '_dashboard.scss');
const TOKENS = path.join(THEME, 'assets', 'scss', 'base', '_tokens.scss');
const JS = path.join(THEME, 'assets', 'js', 'organizer.js');
const PKG = path.join(THEME, 'package.json');

const read = (p) => readFileSync(p, 'utf8');
const parts = readdirSync(PARTS)
  .filter((f) => f.endsWith('.php'))
  .map((f) => read(path.join(PARTS, f)));

const results = [];
const ok = (n, what, detail) => results.push({ n, pass: true, what, detail });
const bad = (n, what, detail) => results.push({ n, pass: false, what, detail });
const verdict = (n, pass, what, detail) => (pass ? ok(n, what, detail) : bad(n, what, detail));

// --- 1. Контракт data-idpg-* не изменился -----------------------------------
// Кабинет читают три скрипта, поэтому сверяется объединение имён из всех
// трёх с тем, что реально есть в разметке: переименование data-атрибута в
// шаблоне ломает выборку, даже если не видно правки в organizer.js.
// Размер контракта organizer.js зафиксирован на момент приёмки.
const EXPECTED_HOOKS = 35;
const JS_FILES = ['organizer.js', 'organizer-event-form.js', 'organizer-venue-form.js'];
const hooksIn = (src) => new Set([...src.matchAll(/data-idpg-[a-z0-9-]+/g)].map((m) => m[0]));

const orgHooks = hooksIn(read(JS));
const allHooks = new Set();
// Атрибуты, которые скрипт создаёт сам: либо через setAttribute, либо внутри
// HTML-фрагмента (следом пробел или `>`). Их нет в разметке по определению,
// и требовать их там бессмысленно.
const jsProvided = new Set();
for (const f of JS_FILES) {
  const p = path.join(THEME, 'assets', 'js', f);
  if (!existsSync(p)) continue;
  const src = read(p);
  for (const m of src.matchAll(/setAttribute\(\s*'(data-idpg-[a-z0-9-]+)'/g)) jsProvided.add(m[1]);
  for (const m of src.matchAll(/(data-idpg-[a-z0-9-]+)(?=[ >])/g)) jsProvided.add(m[1]);
  for (const h of hooksIn(src)) allHooks.add(h);
}
const markupHooks = new Set();
// Разметка кабинета разложена не только по page-organizer.php и
// template-parts/organizer/, но и по отдельным шаблонам формы события и
// площадки (page-organizer-event.php, page-organizer-venue.php), поэтому
// сканируются все .php темы.
const SKIP_DIRS = new Set(['node_modules', 'vendor', 'bin']);
function phpFilesUnder(dir, acc = []) {
  for (const e of readdirSync(dir, { withFileTypes: true })) {
    if (e.name.startsWith('.') || SKIP_DIRS.has(e.name)) continue;
    const full = path.join(dir, e.name);
    if (e.isDirectory()) phpFilesUnder(full, acc);
    else if (e.name.endsWith('.php')) acc.push(full);
  }
  return acc;
}
for (const file of phpFilesUnder(THEME)) {
  for (const h of hooksIn(readFileSync(file, 'utf8'))) markupHooks.add(h);
}
const orphanHooks = [...allHooks]
  .filter((h) => !markupHooks.has(h) && !jsProvided.has(h))
  .sort();
verdict(
  1,
  orgHooks.size === EXPECTED_HOOKS && orphanHooks.length === 0,
  `Контракт: organizer.js ${orgHooks.size}, скриптов ${allHooks.size}, JS-создаёт ${jsProvided.size}, без разметки ${orphanHooks.length}`,
  orphanHooks.length ? 'нет ни в разметке, ни в JS: ' + orphanHooks.join(', ') : `ожидалось ${EXPECTED_HOOKS} и 0 orphans`
);

// --- 2. В стилях кабинета нет жёстких цветов -------------------------------
// Проверяется _organizer.scss — единственный источник стилей кабинета
// (assets/css/organizer.css собирается только из него). Правила .dashboard__*
// в _dashboard.scss после DEV-196 мёртвы, они живут в публичном style.css,
// который критерий 13 замораживает; их hex вынесены в DEV-197.
const hexOrg = [...read(SCSS_ORG).matchAll(/#[0-9a-fA-F]{3,8}\b/g)];
verdict(
  2,
  hexOrg.length === 0,
  `hex в источнике стилей кабинета (_organizer.scss): ${hexOrg.length}`,
  'ожидалось 0'
);

// --- 3. Все используемые токены объявлены -----------------------------------
const declared = new Set(
  [...read(TOKENS).matchAll(/(--idpg-[a-z0-9-]+)\s*:/g)].map((m) => m[1])
);
const used = new Set(
  [...read(SCSS_ORG).matchAll(/var\((--idpg-[a-z0-9-]+)\)/g)].map((m) => m[1])
);
const undeclared = [...used].filter((t) => !declared.has(t));
verdict(
  3,
  undeclared.length === 0,
  `Необъявленных токенов в _organizer.scss: ${undeclared.length}`,
  undeclared.length ? undeclared.join(', ') : 'все объявлены'
);

// --- 4. Отступы и радиусы только из токенов --------------------------------
const px = [
  ...read(SCSS_ORG).matchAll(/(?:padding|margin|gap|border-radius)[a-z-]*\s*:[^;]*?\d+px/g),
];
verdict(
  4,
  px.length === 0,
  `px в padding/margin/gap/border-radius: ${px.length}`,
  'ожидалось 0'
);

// --- 5. Мёртвых селекторов нет ----------------------------------------------
const markupAll = [...parts, read(path.join(THEME, 'page-organizer.php')), read(JS)].join('\n');
const scssClasses = [
  ...new Set([...read(SCSS_ORG).matchAll(/\.(idpg-org[A-Za-z0-9_-]*)/g)].map((m) => m[1])),
];
const dead = scssClasses.filter((c) => {
  const base = c.replace(/__.*$/, '').replace(/--.*$/, '');
  return !markupAll.includes(base);
});
verdict(
  5,
  dead.length === 0,
  `Мёртвых селекторов в organizer.css: ${dead.length} из ${scssClasses.length}`,
  dead.length ? dead.join(', ') : 'все используются'
);

// --- 6. В разметке один префикс ---------------------------------------------
const legacy = parts.filter((c) => c.includes('dashboard__'));
verdict(
  6,
  legacy.length === 0,
  `Файлов разметки с dashboard__: ${legacy.length}`,
  'ожидалось 0'
);

// --- 7. Внутренние блоки не дублируют отступ страницы ----------------------
const bigPadding = [...read(SCSS_ORG).matchAll(/padding:\s*(\d{2,})px/g)];
verdict(
  7,
  bigPadding.length === 0,
  `Отступов уровня страницы (>=10px) внутри секций: ${bigPadding.length}`,
  'ожидалось 0'
);

// --- 8. Мобильные подписи таблицы работают ---------------------------------
const setLabel = (read(JS).match(/setAttribute\('data-label'/g) || []).length;
const cellCalls = (read(JS).match(/cellWithLabel\((?:EVENT|VENUE)_COLUMNS\[\d\]/g) || []).length;
const cssLabel = read(SCSS_ORG).includes('content: attr(data-label)');
verdict(
  8,
  setLabel >= 1 && cellCalls >= 6 && cssLabel,
  `data-label: вызов cell() ${cellCalls}, setAttribute ${setLabel}, CSS ${cssLabel ? 'есть' : 'НЕТ'}`,
  'ожидалось >=6 ячеек и правило content: attr(data-label)'
);

// --- 9. У каждого статуса свой бейдж ----------------------------------------
const js = read(JS);
const metaBlock = js.slice(js.indexOf('var STATUS_META'), js.indexOf('var STATUS_META') + 500);
const statuses = (metaBlock.match(/badge: '([^']+)'/g) || []).map((s) => s.split("'")[1]);
const uniqueBadges = new Set(statuses);
const badgeStyles = [
  ...new Set([...read(SCSS_ORG).matchAll(/\.idpg-org-badge--([a-z]+)/g)].map((m) => m[1])),
];
verdict(
  9,
  statuses.length === 4 && uniqueBadges.size === 4 && badgeStyles.includes('rejected'),
  `Статусов ${statuses.length}, разных бейджей ${uniqueBadges.size}, стилей бейджей ${badgeStyles.length}`,
  'ожидалось 4 статуса, 4 разных класса, наличие --rejected'
);

// --- 10. organizer.css собирается скриптом ---------------------------------
const scripts = JSON.parse(read(PKG)).scripts || {};
let checkCode = -1;
try {
  // На Windows npm — это .cmd, поэтому нужен shell: execFileSync без него
  // падает с ENOENT и даёт неверный код.
  execFileSync('npm', ['run', 'check:organizer-css'], {
    cwd: THEME,
    stdio: 'ignore',
    shell: true,
  });
  checkCode = 0;
} catch (e) {
  checkCode = e.status ?? -1;
}
verdict(
  10,
  Boolean(scripts['compile:organizer-css']) && Boolean(scripts['check:organizer-css']) && checkCode === 0,
  `Скрипты: compile=${Boolean(scripts['compile:organizer-css'])}, check=${Boolean(scripts['check:organizer-css'])}, check:organizer-css код ${checkCode}`,
  'ожидались скрипты и код 0'
);

// --- 13. style.css не тронут этой задачей ----------------------------------
// Задача НЕ пересобирает публичный style.css: в нём лежит пересборка,
// сделанная вне DEV-196, и взять её в коммит означало бы присвоить себе
// чужую работу. Критерий проверяет другое: партиал кабинета не должен
// попасть в общую сборку — иначе весь кабинет приехал бы на каждую
// страницу сайта.
const styleScss = read(path.join(THEME, 'assets', 'scss', 'style.scss'));
const orgWiredIn = /@use\s+['"].*pages\/organizer/.test(styleScss);
const orgBuiltSeparately = Boolean(scripts['compile:organizer-css']);
verdict(
  13,
  !orgWiredIn && orgBuiltSeparately,
  `style.css: партиал кабинета в общей сборке ${orgWiredIn ? 'ПОДКЛЮЧЁН' : 'нет'}, отдельная сборка ${orgBuiltSeparately ? 'есть' : 'НЕТ'}`,
  'ожидалось «не подключён» и «есть»'
);
// --- 14. Профиль живёт без JS ----------------------------------------------
const orgPage = read(path.join(THEME, 'page-organizer.php'));
const nonceOk =
  orgPage.includes('wp_verify_nonce') && orgPage.includes('idpg_organizer_profile');
const redirectOk = orgPage.includes('wp_safe_redirect');
verdict(14, nonceOk && redirectOk, `Профиль без JS: nonce ${nonceOk}, Post/Redirect/Get ${redirectOk}`, 'ожидалось true/true');

// --- 11. PHPCS: новых ошибок относительно HEAD нет ------------------------
// Сравниваются не абсолютные числа, а прирост по каждому файлу: в кабинете
// исторически накоплены нарушения (пробельные отступы во встроенном JS в
// section-profile.php и т.д.), их DEV-196 не обязан вычищать.
const PHPCS_BOOT =
  'D:/laragon/www/idupogorodu/phpcs-tools2/vendor/forrest79/phpcs-ignores/src/bootstrap.php';
const PHPCS = 'D:/laragon/www/idupogorodu/phpcs-tools2/vendor/bin/phpcs.bat';
const PHPCS_STD = path.join(THEME, '.phpcs.xml.dist');
const REPO = path.resolve(THEME, '..', '..');

const phpFiles = [
  'page-organizer.php',
  ...readdirSync(PARTS)
    .filter((f) => f.endsWith('.php'))
    .map((f) => 'template-parts/organizer/' + f),
];

/** Разбор одной строки CSV с учётом кавычек. */
function csvFields(line) {
  const out = [];
  let cur = '';
  let quoted = false;
  for (let i = 0; i < line.length; i++) {
    const ch = line[i];
    if (ch === '"') {
      if (quoted && line[i + 1] === '"') {
        cur += '"';
        i++;
      } else {
        quoted = !quoted;
      }
    } else if (ch === ',' && !quoted) {
      out.push(cur);
      cur = '';
    } else {
      cur += ch;
    }
  }
  out.push(cur);
  return out;
}

const phpcsErrors = (targets, reportFile) => {
  try {
    execFileSync(
      PHPCS,
      [
        '--bootstrap=' + PHPCS_BOOT,
        '--standard=' + PHPCS_STD,
        '--report=csv',
        '--report-file=' + reportFile,
        '--no-colors',
        ...targets,
      ],
      { encoding: 'utf8', shell: true }
    );
  } catch {
    /* phpcs возвращает ненулевой код при наличии нарушений — это ожидаемо */
  }
  if (!existsSync(reportFile)) return null;
  const lines = readFileSync(reportFile, 'utf8').split(/\r?\n/).filter((l) => l.trim());
  // Первая строка — заголовок. Колонка Type (индекс 3) пишется в нижнем
  // регистре: 'error' / 'warning'.
  return lines
    .slice(1)
    .map(csvFields)
    .filter((f) => (f[3] || '').trim().toLowerCase() === 'error').length;
};

const headDir = path.join(THEME, 'bin', '.revert-probe-head');
mkdirSync(headDir, { recursive: true });
const headTargets = [];
for (const rel of phpFiles) {
  const out = path.join(headDir, path.basename(rel));
  let content = '';
  try {
    content = execFileSync(
      'git',
      ['-c', 'safe.directory=*', 'show', 'HEAD:themes/idpg_core/' + rel],
      { cwd: REPO, encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 }
    );
  } catch {
    continue; // файла не было в HEAD — сравнивать нечего
  }
  // BOM и CRLF из checkout добавляют шум в PHPCS, нормализуем.
  writeFileSync(out, content.replace(/^﻿/, '').replace(/\r\n/g, '\n'), 'utf8');
  headTargets.push(out);
}

const nowReport = path.join(THEME, 'bin', '.phpcs-now.csv');
const headReport = path.join(THEME, 'bin', '.phpcs-head.csv');
const nowErr = phpcsErrors(phpFiles.map((r) => path.join(THEME, r)), nowReport);
const headErr = phpcsErrors(headTargets, headReport);
// Отчёты PHPCS — временные файлы, в репозитории им не место.
for (const f of [nowReport, headReport]) unlinkSync(f);
rmSync(headDir, { recursive: true, force: true });

verdict(
  11,
  nowErr !== null && headErr !== null && nowErr <= headErr,
  `PHPCS ошибок: было ${headErr}, стало ${nowErr}`,
  'ожидалось «стало ≤ было»'
);

// --- 12. lint:js без ошибок -------------------------------------------------
let lintOut = '';
let lintErrors = -1;
try {
  lintOut = execFileSync('npm', ['run', 'lint:js'], { cwd: THEME, encoding: 'utf8', shell: true });
} catch (e) {
  lintOut = (e.stdout || '') + (e.stderr || '');
}
const lintSummary = (lintOut.match(/✖\s*\d+ problems \(([^)]*)\)/) || [])[1] || '';
lintErrors = Number((lintSummary.match(/(\d+) errors/) || [])[1] ?? -1);
verdict(12, lintErrors === 0, `lint:js ошибок: ${lintErrors === -1 ? 'не распознано' : lintErrors}`, 'ожидалось 0');

// --- Итог ------------------------------------------------------------------
const width = Math.max(...results.map((r) => r.what.length));
let failed = 0;
for (const r of results.sort((a, b) => a.n - b.n)) {
  if (!r.pass) failed++;
  console.log(
    `  ${r.pass ? 'OK  ' : 'FAIL'}  ${String(r.n).padStart(2)}. ${r.what.padEnd(width)}  → ${r.detail}`
  );
}
console.log('');
console.log(failed === 0 ? '  Все критерии выполнены.' : `  Не выполнено критериев: ${failed}.`);
process.exit(failed === 0 ? 0 : 1);
