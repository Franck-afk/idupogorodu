// Сборка idpg-core.pot: сканирует PHP-файлы плагина и извлекает
// строки из функций перевода WordPress.
const fs = require('fs');
const path = require('path');

const ROOT = process.argv[2] ? path.resolve(process.argv[2]) : __dirname;
const OUT = path.join(ROOT, 'languages', 'idpg-core.pot');
const DOMAIN = 'idpg-core';

const FUNCS = ['__', '_e', '_x', 'esc_html__', 'esc_html_e', 'esc_attr__', 'esc_attr_e', 'esc_html_x', '_n'];

function* walk(dir) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    if (e.name === 'vendor' || e.name === 'node_modules') continue;
    const p = path.join(dir, e.name);
    if (e.isDirectory()) yield* walk(p);
    else if (e.name.endsWith('.php')) yield p;
  }
}

// Парсер PHP-строк в двойных/одинарных кавычках начиная с offset.
function readString(t, i) {
  const q = t[i];
  let s = '';
  i++;
  while (i < t.length) {
    if (t[i] === '\\' && i + 1 < t.length) {
      const c = t[i + 1];
      if (q === '"') {
        if (c === 'n') s += '\n';
        else if (c === 't') s += '\t';
        else s += c;
      } else {
        s += c === q ? q : c;
      }
      i += 2;
      continue;
    }
    if (t[i] === q) return { s, end: i + 1 };
    s += t[i];
    i++;
  }
  return { s, end: i };
}

const entries = new Map(); // msgid -> {refs:Set, ctx?}
function add(msgid, ref, ctx) {
  const key = (ctx ? ctx + '\u0004' : '') + msgid;
  if (!entries.has(key)) entries.set(key, { refs: new Set(), ctx, msgid });
  entries.get(key).refs.add(ref);
}

for (const file of walk(ROOT)) {
  const rel = path.relative(ROOT, file).replace(/\\/g, '/');
  const t = fs.readFileSync(file, 'utf8');
  const re = /(__|_e|_x|_n|esc_html__|esc_html_e|esc_html_x|esc_attr__|esc_attr_e)\s*\(/g;
  let m;
  while ((m = re.exec(t))) {
    const fn = m[1];
    let i = re.lastIndex;
    // Первый аргумент — строка (пропускаем пробелы).
    while (t[i] === ' ' || t[i] === '\t' || t[i] === '\n' || t[i] === '\r') i++;
    if (t[i] !== '"' && t[i] !== "'") continue; // динамическая строка — пропускаем
    const { s: msgid, end } = readString(t, i);
    if (!msgid) continue;
    // Проверяем домен вторым/последним аргументом: быстро ищем 'idpg-core' до закрывающей скобки.
    let depth = 1, j = end, sawDomain = false, ctx = null;
    while (j < t.length && depth > 0) {
      const c = t[j];
      if (c === '(') depth++;
      else if (c === ')') depth--;
      else if (c === '"' || c === "'") {
        const r = readString(t, j);
        if (r.s === DOMAIN) sawDomain = true;
        if (fn === '_x' && !ctx && r.s !== DOMAIN) ctx = r.s;
        j = r.end;
        continue;
      }
      j++;
    }
    if (!sawDomain) continue;
    const lineNo = t.slice(0, m.index).split('\n').length;
    add(msgid, rel + ':' + lineNo, ctx);
  }
}

// Формирование POT.
const header = `# Copyright (C) 2026 IDPG Team
# This file is distributed under the GPL v2 or later.
msgid ""
msgstr ""
"Project-Id-Version: IDPG Core 1.0.0\\n"
"Report-Msgid-Bugs-To: https://idupogorodu.ru\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"POT-Creation-Date: ${new Date().toISOString().replace('T', ' ').slice(0, 19) + '+0000'}\\n"
"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n"
"X-Generator: idpg pot builder\\n"
"Language-Team: LANGUAGE\\n"
"X-Domain: ${DOMAIN}\\n"

`;

function esc(s) { return s.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, '\\n'); }

let out = header;
for (const [key, e] of [...entries.entries()].sort((a, b) => a[0].localeCompare(b[0]))) {
  out += '#: ' + [...e.refs].sort().join('\n#: ') + '\n';
  if (e.ctx) out += 'msgctxt "' + esc(e.ctx) + '"\n';
  out += 'msgid "' + esc(e.msgid) + '"\nmsgstr ""\n\n';
}

fs.mkdirSync(path.dirname(OUT), { recursive: true });
fs.writeFileSync(OUT, out, 'utf8');
console.log('OK: ' + OUT);
console.log('Записей: ' + entries.size);
