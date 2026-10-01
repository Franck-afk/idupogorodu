/**
 * Обратная проба DEV-196: 10 мутаций, по одной на каждый проверяемый критерий.
 *
 * Смысл: если сломать код намеренно и критерий останется зелёным, значит он
 * ничего не проверяет. Ниже каждая мутация применяется к копии файлов,
 * прогоняется node bin/check-organizer-ui.mjs, и ожидается, что конкретный
 * критерий покраснеет. Файлы восстанавливаются из резервной копии.
 *
 * Запуск: node bin/revert-probe-organizer-ui.mjs
 * Выход: 0 — все мутации дали ожидаемую реакцию.
 */

import {
  readFileSync,
  writeFileSync,
  copyFileSync,
  mkdirSync,
  rmSync,
  existsSync,
} from 'node:fs';
import { execFileSync } from 'node:child_process';
import path from 'node:path';

const THEME = path.resolve(process.cwd());
const BACKUP = path.join(THEME, 'bin', '.revert-probe-backup');

// Файлы, которые вообще могут пострадать от мутаций.
const FILES = [
  'page-organizer.php',
  'template-parts/organizer/section-dashboard.php',
  'template-parts/organizer/section-events.php',
  'template-parts/organizer/section-venues.php',
  'assets/scss/pages/_organizer.scss',
  'assets/scss/pages/_dashboard.scss',
  'assets/js/organizer.js',
  'assets/css/organizer.css',
  'package.json',
];

const P = (rel) => path.join(THEME, rel);
const read = (rel) => readFileSync(P(rel), 'utf8');
const write = (rel, s) => writeFileSync(P(rel), s, 'utf8');

// --- резервная копия -------------------------------------------------------
mkdirSync(BACKUP, { recursive: true });
for (const f of FILES) {
  const dest = path.join(BACKUP, f);
  mkdirSync(path.dirname(dest), { recursive: true });
  copyFileSync(P(f), dest);
}

const restore = () => {
  if (!existsSync(BACKUP)) return;
  for (const f of FILES) {
    const src = path.join(BACKUP, f);
    if (existsSync(src)) copyFileSync(src, P(f));
  }
};

// Пробу можно прервать (Ctrl+C, закрыть терминал). Без этих обработчиков
// дерево осталось бы с последней мутацией — такое уже случалось, и «зелёная»
// приёмка потом проверяла бы сломанный файл. Восстановление обязано
// произойти при любом завершении.
let finished = false;
const finish = () => {
  if (finished) return;
  finished = true;
  restore();
  rebuildOrganizerCss();
  rmSync(BACKUP, { recursive: true, force: true });
};
process.on('exit', finish);
for (const sig of ['SIGINT', 'SIGTERM', 'SIGHUP', 'uncaughtException']) {
  process.on(sig, () => {
    finish();
    process.exit(1);
  });
}

const rebuildOrganizerCss = () => {
  try {
    execFileSync('npm', ['run', 'compile:organizer-css'], {
      cwd: THEME,
      stdio: 'ignore',
      shell: true,
    });
  } catch {
    /* сборка не критична для самого прогона проверки */
  }
};

// --- мутации ---------------------------------------------------------------
// apply — мутирует; undo — возвращает как было (не всегда совпадает с restore,
// поэтому используется полный restore между мутациями).
const MUTATIONS = [
  {
    id: 1,
    what: 'Переименовать читаемый скриптом data-атрибут в разметке',
    apply: () => {
      const f = 'template-parts/organizer/section-events.php';
      write(f, read(f).replace('data-idpg-events-body', 'data-idpg-events-bdy'));
    },
  },
  {
    id: 2,
    what: 'Вернуть жёсткий hex в стили кабинета',
    apply: () => {
      const f = 'assets/scss/pages/_organizer.scss';
      write(
        f,
        read(f).replace(
          'color: var(--idpg-primary);',
          'color: #0052ff;'
        )
      );
      rebuildOrganizerCss();
    },
  },
  {
    id: 3,
    what: 'Сослаться на необъявленный токен',
    apply: () => {
      const f = 'assets/scss/pages/_organizer.scss';
      write(
        f,
        read(f).replace('var(--idpg-canvas)', 'var(--idpg-canvas-nonexistent)')
      );
      rebuildOrganizerCss();
    },
  },
  {
    id: 4,
    what: 'Заменить токен отступа на px',
    apply: () => {
      const f = 'assets/scss/pages/_organizer.scss';
      // margin-bottom у .idpg-org-actions — однозначная строка с одним значением.
      write(
        f,
        read(f).replace(
          'margin: 0 0 var(--idpg-space-lg);',
          'margin: 0 0 24px;'
        )
      );
      rebuildOrganizerCss();
    },
  },
  {
    id: 5,
    what: 'Добавить правило для несуществующего класса',
    apply: () => {
      const f = 'assets/scss/pages/_organizer.scss';
      write(f, read(f) + '\n.idpg-org-ghost-element {\n  color: red;\n}\n');
      rebuildOrganizerCss();
    },
  },
  {
    id: 6,
    what: 'Вернуть префикс dashboard__ в разметку',
    apply: () => {
      const f = 'template-parts/organizer/section-events.php';
      write(f, read(f).replace('idpg-org-empty', 'dashboard__empty'));
    },
  },
  {
    id: 7,
    what: 'Вернуть отступ уровня страницы (88px) внутреннему блоку',
    apply: () => {
      const f = 'assets/scss/pages/_organizer.scss';
      write(
        f,
        read(f).replace(
          '.idpg-org-body {\n  display: flow-root;\n}',
          '.idpg-org-body {\n  display: flow-root;\n  padding: 88px;\n}'
        )
      );
      rebuildOrganizerCss();
    },
  },
  {
    id: 8,
    what: 'Убрать data-label у одной ячейки',
    apply: () => {
      const f = 'assets/js/organizer.js';
      write(f, read(f).replace('cellWithLabel(EVENT_COLUMNS[2])', "cellWithLabel('')"));
    },
  },
  {
    id: 9,
    what: 'Вернуть rejected на серый бейдж черновика',
    apply: () => {
      const f = 'assets/js/organizer.js';
      write(
        f,
        read(f).replace(
          "rejected: { label: 'Отклонено', badge: 'idpg-org-badge--rejected' }",
          "rejected: { label: 'Отклонено', badge: 'idpg-org-badge--draft' }"
        )
      );
    },
  },
  {
    id: 10,
    what: 'Убрать npm-скрипт сборки organizer.css',
    apply: () => {
      const f = 'package.json';
      const pkg = JSON.parse(read(f));
      delete pkg.scripts['compile:organizer-css'];
      write(f, JSON.stringify(pkg, null, 2) + '\n');
    },
  },
];

// --- прогон -----------------------------------------------------------------
const runChecks = () => {
  try {
    const out = execFileSync('node', [path.join(THEME, 'bin', 'check-organizer-ui.mjs')], {
      cwd: THEME,
      encoding: 'utf8',
      shell: true,
    });
    return { code: 0, out };
  } catch (e) {
    return { code: e.status ?? 1, out: (e.stdout || '') + (e.stderr || '') };
  }
};

const failed = parseInt(process.env.FAILED_BASELINE ?? '0', 10);

console.log('  Обратная проба DEV-196 — каждая мутация должна ломать свой критерий.');
console.log('');

let missed = 0;
for (const m of MUTATIONS) {
  restore();
  m.apply();
  const { code, out } = runChecks();
  const target = new RegExp(`^\\s*(OK|FAIL)\\s+${m.id}\\.`, 'm');
  const line = out.split('\n').find((l) => target.test(l)) || '';
  const isFail = /^\s*FAIL/.test(line);
  const ok = isFail && code !== 0;
  if (!ok) missed++;
  console.log(
    `  ${ok ? 'поймана' : 'ПРОПУЩЕНА'}  мутация ${String(m.id).padStart(2)}: ${m.what}`
  );
  console.log(`             → ${line.trim() || 'критерий не найден в выводе'}`);
}

restore();
finished = true;
rmSync(BACKUP, { recursive: true, force: true });

console.log('');
if (missed === 0) {
  console.log('  Все мутации пойманы: критерии действительно проверяют код.');
  process.exit(0);
}
console.log(`  Пропущено мутаций: ${missed}. Критерии нужно усилить.`);
process.exit(1);