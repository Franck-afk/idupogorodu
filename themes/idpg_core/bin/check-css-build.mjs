#!/usr/bin/env node
/**
 * Сверка сборки SCSS с эталонным `style.css` на уровне ПРАВИЛ.
 *
 * Зачем. `sass` форматирует иначе, чем набран `style.css` (4 167 строк против
 * 3 523 при одинаковых правилах), поэтому обычный `diff` бесполезен — он тонет
 * в шуме. Этот скрипт сравнивает правила так же, как сторож
 * `plugins/idpg-core/tests/test-css-build.php` (`Test_Css_Build`), и отвечает
 * на единственный нужный вопрос: не потеряла и не исказила ли сборка правила.
 *
 * ВАЖНО: алгоритм ниже — реплика сторожа. Если правится один, править и второй.
 * Авторитетный гейт в CI — PHPUnit-тест; этот скрипт нужен для быстрой
 * локальной проверки без поднятия тестового окружения WordPress.
 *
 * Запуск: node bin/check-css-build.mjs <сборка.css> <эталон.css>
 * Код возврата: 0 — правила совпадают, 1 — расхождения, 2 — ошибка запуска.
 *
 * @package idpg_core
 */

import { readFileSync } from 'node:fs';

/**
 * Убирает CSS-комментарии (блочные).
 *
 * @param {string} css Исходный CSS.
 * @return {string} CSS без комментариев.
 */
function stripComments( css ) {
	return css.replace( /\/\*[\s\S]*?\*\//g, '' );
}

/**
 * Разбивает строку по разделителю, чистит и сортирует.
 *
 * @param {string} value Строка.
 * @param {string} sep   Разделитель.
 * @return {string} Нормализованная строка.
 */
function normList( value, sep ) {
	return value
		.split( sep )
		.map( ( part ) => part.trim() )
		.filter( ( part ) => '' !== part )
		.map( ( part ) => part.replace( /\s+/g, ' ' ).trim() )
		.sort()
		.join( sep );
}

/**
 * Нормализует список селекторов.
 *
 * @param {string} value Селекторы через запятую.
 * @return {string} Нормализованный список.
 */
function normSel( value ) {
	return normList( value, ',' );
}

/**
 * Нормализует блок деклараций.
 *
 * @param {string} value Блок деклараций.
 * @return {string} Нормализованный блок.
 */
function normDecl( value ) {
	return normList( value, ';' );
}

/**
 * Обходит CSS и собирает карту правил.
 *
 * Ключ — «at-контекст || нормализованный селектор». Первое вхождение селектора
 * побеждает — как в сторожа.
 *
 * @param {string}   css     CSS без комментариев.
 * @param {string[]} atStack Открытые at-правила.
 * @param {Map}      map     Накопитель.
 * @return {void}
 */
function walk( css, atStack, map ) {
	const len = css.length;
	let i = 0;

	while ( i < len ) {
		if ( '{' !== css[ i ] ) {
			i++;
			continue;
		}

		let start = i - 1;
		while ( start >= 0 && ! [ '{', ';', '}' ].includes( css[ start ] ) ) {
			start--;
		}
		const selector = css.slice( start + 1, i ).trim();

		let depth = 1;
		let j = i + 1;
		while ( j < len && depth > 0 ) {
			if ( '{' === css[ j ] ) {
				depth++;
			} else if ( '}' === css[ j ] ) {
				depth--;
			}
			j++;
		}

		const body = css.slice( i + 1, j - 1 );

		if ( '' === selector ) {
			i = j;
			continue;
		}

		if ( selector.startsWith( '@' ) ) {
			atStack.push( normSel( selector ) );
			walk( body, atStack, map );
			atStack.pop();
		} else {
			const at = atStack.join( ' ' );
			const key = `${ at }||${ normSel( selector ) }`;
			if ( ! map.has( key ) ) {
				map.set( key, {
					decl: normDecl( body ),
					label: ( '' === at ? '' : `${ at } ` ) + selector,
				} );
			}
		}

		i = j;
	}
}

/**
 * Разбирает файл в карту правил.
 *
 * @param {string} file Путь к файлу.
 * @return {Map} Карта правил.
 */
function parseFile( file ) {
	const map = new Map();
	walk( stripComments( readFileSync( file, 'utf8' ) ), [], map );
	return map;
}

const [ , , buildFile, refFile ] = process.argv;

if ( ! buildFile || ! refFile ) {
	process.stderr.write( 'Использование: node bin/check-css-build.mjs <сборка.css> <эталон.css>\n' );
	process.exit( 2 );
}

let build;
let ref;

try {
	build = parseFile( buildFile );
	ref = parseFile( refFile );
} catch ( error ) {
	process.stderr.write( `Не удалось прочитать файл: ${ error.message }\n` );
	process.exit( 2 );
}

const missing = [];
const added = [];
const changed = [];

for ( const [ key, rule ] of ref ) {
	if ( ! build.has( key ) ) {
		missing.push( rule.label );
	} else if ( build.get( key ).decl !== rule.decl ) {
		changed.push( rule.label );
	}
}
for ( const [ key, rule ] of build ) {
	if ( ! ref.has( key ) ) {
		added.push( rule.label );
	}
}

const total = missing.length + added.length + changed.length;

process.stdout.write( `Эталон ${ refFile }: ${ ref.size } правил\n` );
process.stdout.write( `Сборка ${ buildFile }: ${ build.size } правил\n\n` );

if ( 0 === total ) {
	process.stdout.write( 'OK: правила совпадают (MISSING 0, лишних 0, CHANGED 0).\n' );
	process.stdout.write( 'Файлы могут различаться форматированием — это нормально.\n' );
	process.exit( 0 );
}

process.stdout.write( `РАСХОЖДЕНИЕ: потеряно ${ missing.length }, лишних ${ added.length }, изменено ${ changed.length }.\n\n` );

/**
 * Печатает список с ограничением.
 *
 * @param {string} title Заголовок.
 * @param {Array}  list  Список.
 * @return {void}
 */
function dump( title, list ) {
	if ( 0 === list.length ) {
		return;
	}
	process.stdout.write( `${ title } (${ list.length }):\n` );
	list.slice( 0, 20 ).forEach( ( item ) => process.stdout.write( `  ${ item }\n` ) );
	if ( list.length > 20 ) {
		process.stdout.write( `  … и ещё ${ list.length - 20 }\n` );
	}
	process.stdout.write( '\n' );
}

dump( 'Потеряно в сборке', missing );
dump( 'Лишнее в сборке', added );
dump( 'Изменились декларации', changed );

process.stdout.write( 'Эталон style.css НЕ перезаписывать: сначала разобраться в расхождении.\n' );
process.exit( 1 );
