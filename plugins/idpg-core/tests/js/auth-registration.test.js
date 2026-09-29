/**
 * Тест JS-пути регистрации организатора: реально исполняет assets/js/auth.js.
 *
 * Зачем: 28.09.2026 consent-поле не отправлялось из auth.js, и все PHP-тесты
 * оставались зелёными — REST-слой принимал запрос, который реальный браузер
 * отправить не мог. Тест ловит именно «поле есть в форме, но не уходит в payload».
 *
 * Без зависимостей: минимальный стаб DOM + перехват fetch.
 * Запуск: node tests/js/auth-registration.test.js
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const vm = require( 'vm' );

/**
 * Ищет auth.js, поднимаясь вверх по дереву каталогов.
 *
 * Тест лежит в wp-content/plugins/idpg-core/tests/js, а тема — в
 * wp-content/themes, но жёсткий путь ломается при другой раскладке.
 *
 * @return {string} Путь к файлу.
 */
function resolveAuthJs() {
	const relative = path.join( 'themes', 'idpg_core', 'assets', 'js', 'auth.js' );
	let dir = __dirname;

	for ( let i = 0; i < 8; i++ ) {
		const candidate = path.join( dir, relative );
		if ( fs.existsSync( candidate ) ) {
			return candidate;
		}
		dir = path.dirname( dir );
	}

	throw new Error( 'auth.js не найден выше по дереву от ' + __dirname );
}

const AUTH_JS = resolveAuthJs();

/* ── Минимальный стаб DOM ────────────────────────────────────────────── */

class StubElement {
	constructor( name ) {
		this.tagName = ( name || 'div' ).toUpperCase();
		this.value = '';
		this.checked = false;
		this.type = '';
		this.className = '';
		this.id = '';
		this.innerHTML = '';
		this.textContent = '';
		this.attributes = {};
		this.children = [];
		this.parentElement = null;
		this.handlers = {};
		this.selectors = {};
		this.selectorLists = {};
		this._classList = new Set();

		this.classList = {
			add: ( c ) => this._classList.add( c ),
			remove: ( c ) => this._classList.delete( c ),
			contains: ( c ) => this._classList.has( c ),
		};
	}

	addEventListener( type, fn ) {
		( this.handlers[ type ] = this.handlers[ type ] || [] ).push( fn );
	}

	dispatch( type, event ) {
		( this.handlers[ type ] || [] ).forEach( ( fn ) => fn( event ) );
	}

	getAttribute( name ) {
		return Object.prototype.hasOwnProperty.call( this.attributes, name ) ? this.attributes[ name ] : null;
	}

	setAttribute( name, value ) {
		this.attributes[ name ] = String( value );
	}

	removeAttribute( name ) {
		delete this.attributes[ name ];
	}

	querySelector( selector ) {
		return this.selectors[ selector ] || null;
	}

	querySelectorAll( selector ) {
		return this.selectorLists[ selector ] || [];
	}

	closest( selector ) {
		return this.selectors[ selector ] || null;
	}

	focus() {}

	appendChild( child ) {
		this.children.push( child );
		return child;
	}

	insertBefore( node ) {
		this.children.unshift( node );
		return node;
	}
}

/**
 * Собирает DOM страницы регистрации.
 *
 * @param {Object}  options
 * @param {boolean} options.withConsentCheckbox Есть ли чекбокс согласия.
 * @param {boolean} options.termsChecked        Отмечен ли чекбокс оферты.
 * @param {boolean} options.consentChecked      Отмечен ли чекбокс ПДн.
 * @return {Object}
 */
function buildDom( options ) {
	const opts = Object.assign( { withConsentCheckbox: true, termsChecked: true, consentChecked: true }, options );

	const panel = new StubElement( 'div' );
	panel.id = 'register-organizer-form';

	const form = new StubElement( 'form' );
	form.selectors[ '.auth__panel' ] = panel;

	const text = ( name, value ) => {
		const el = new StubElement( 'input' );
		el.value = value;
		form.selectors[ '[name="' + name + '"]' ] = el;
		return el;
	};

	const check = ( name, isChecked ) => {
		const el = new StubElement( 'input' );
		el.type = 'checkbox';
		el.checked = isChecked;
		form.selectors[ '[name="' + name + '"]' ] = el;
		return el;
	};

	const fields = {
		org_name: text( 'org_name', 'ООО Ромашка' ),
		contact_name: text( 'contact_name', 'Иванов Иван' ),
		email: text( 'email', 'org@example.test' ),
		phone: text( 'phone', '+7 999 000-00-00' ),
		password: text( 'password', 'super-secret-1' ),
		password_confirm: text( 'password_confirm', 'super-secret-1' ),
	};

	const submitBtn = new StubElement( 'button' );
	form.selectors[ '.auth__submit' ] = submitBtn;

	const termsBox = check( 'terms', opts.termsChecked );
	let pdnBox = null;

	if ( opts.withConsentCheckbox ) {
		pdnBox = check( 'idpg_pdn_consent', opts.consentChecked );
	}

	const message = new StubElement( 'div' );
	message.id = 'auth-message';
	const messageText = new StubElement( 'p' );
	message.selectors[ '.auth__message-text' ] = messageText;

	const document = {
		getElementById: ( id ) => ( 'auth-message' === id ? message : null ),
		querySelectorAll: ( selector ) => {
			if ( '.auth__tab' === selector ) {
				return [];
			}
			if ( '.auth__panel' === selector ) {
				return [ panel ];
			}
			if ( '.auth__form' === selector ) {
				return [ form ];
			}
			// Улучшения полей пароля и прочее в этот тест не вмешиваются.
			return [];
		},
		createElement: ( tag ) => new StubElement( tag ),
		addEventListener: ( type, fn ) => {
			if ( 'DOMContentLoaded' === type ) {
				document._domReady = fn;
			}
		},
	};

	return { document, form, panel, message, messageText, fields, termsBox, pdnBox };
}

/**
 * Исполняет auth.js в песочнице и возвращает перехваченные вызовы fetch.
 *
 * @param {Object} dom
 * @return {Object} { fetches }
 */
function runAuthJs( dom ) {
	const fetches = [];

	const FormDataStub = class FormData {
		constructor( formEl ) {
			this._data = {};
			Object.keys( formEl.selectors ).forEach( ( selector ) => {
				const match = selector.match( /^\[name="(.+)"\]$/ );
				if ( ! match ) {
					return;
				}
				const el = formEl.selectors[ selector ];
				this._data[ match[ 1 ] ] = 'checkbox' === el.type ? ( el.checked ? '1' : null ) : el.value;
			} );
		}

		get( key ) {
			return Object.prototype.hasOwnProperty.call( this._data, key ) ? this._data[ key ] : null;
		}
	};

	const sandbox = {
		document: dom.document,
		window: {
			idpgAuth: { restUrl: 'https://example.test/wp-json/idpg/v1', nonce: 'test-nonce' },
			location: { origin: 'https://example.test' },
		},
		FormData: FormDataStub,
		fetch: ( url, options ) => {
			fetches.push( { url, options, body: JSON.parse( options.body ) } );
			return Promise.resolve( {
				ok: true,
				json: () => Promise.resolve( { success: true, pending_email: true } ),
			} );
		},
		setTimeout,
		clearTimeout,
		console,
		Promise,
		JSON,
	};

	vm.createContext( sandbox );
	vm.runInContext( fs.readFileSync( AUTH_JS, 'utf8' ), sandbox, { filename: 'auth.js' } );

	// init() навешивается на DOMContentLoaded.
	if ( dom.document._domReady ) {
		dom.document._domReady();
	}

	return { fetches };
}

/**
 * Отправляет форму регистрации.
 *
 * @param {Object} dom
 * @return {Object} { fetches }
 */
function submitRegister( dom ) {
	const { fetches } = runAuthJs( dom );

	dom.form.dispatch( 'submit', {
		preventDefault: () => {},
		target: dom.form,
	} );

	return { fetches };
}

/* ── Мини-фреймворк ─────────────────────────────────────────────────── */

let passed = 0;
let failed = 0;

function check( label, condition, detail ) {
	if ( condition ) {
		passed++;
		console.log( '  [PASS] ' + label );
	} else {
		failed++;
		console.log( '  [FAIL] ' + label + ( detail ? ' -> ' + detail : '' ) );
	}
}

/* ── Тесты ───────────────────────────────────────────────────────────── */

console.log( '=== auth.js: путь регистрации организатора ===' );

// 1. Согласие отмечено — поле уходит в payload.
{
	const dom = buildDom( { consentChecked: true } );
	const { fetches } = submitRegister( dom );

	check( 'отправлен ровно один запрос', 1 === fetches.length, 'запросов: ' + fetches.length );
	check( 'endpoint ведёт на /auth/register', fetches[ 0 ] && fetches[ 0 ].url.endsWith( '/auth/register' ) );
	check(
		'payload содержит idpg_pdn_consent: true',
		fetches[ 0 ] && true === fetches[ 0 ].body.idpg_pdn_consent,
		fetches[ 0 ] ? JSON.stringify( fetches[ 0 ].body ) : 'нет запроса'
	);
	check( 'payload помечен как organizer', fetches[ 0 ] && 'organizer' === fetches[ 0 ].body.type );
	check( 'payload содержит согласие с офертой', fetches[ 0 ] && true === fetches[ 0 ].body.terms );
}

// 2. Согласие не отмечено — запрос не уходит.
{
	const dom = buildDom( { consentChecked: false } );
	const { fetches } = submitRegister( dom );

	check( 'без согласия запрос не отправлен', 0 === fetches.length, 'запросов: ' + fetches.length );
	check(
		'показано объяснение про персональные данные',
		/персональных данных/.test( dom.messageText.textContent ),
		dom.messageText.textContent
	);
}

// 3. Оферта не отмечена, согласие есть — запрос не уходит.
{
	const dom = buildDom( { termsChecked: false, consentChecked: true } );
	const { fetches } = submitRegister( dom );

	check( 'без оферты запрос не отправлен', 0 === fetches.length, 'запросов: ' + fetches.length );
	check( 'показано объяснение про оферту', /оферт/.test( dom.messageText.textContent ), dom.messageText.textContent );
}

// 4. Чекбокса согласия в форме нет — уходит явный false, а не undefined.
{
	const dom = buildDom( { withConsentCheckbox: false } );
	const { fetches } = submitRegister( dom );

	check( 'запрос отправлен', 1 === fetches.length, 'запросов: ' + fetches.length );
	check(
		'отсутствие чекбокса = явный false, а не undefined',
		fetches[ 0 ] && false === fetches[ 0 ].body.idpg_pdn_consent,
		fetches[ 0 ] ? String( fetches[ 0 ].body.idpg_pdn_consent ) : 'нет запроса'
	);
	check(
		'ключ согласия присутствует в payload',
		fetches[ 0 ] && Object.prototype.hasOwnProperty.call( fetches[ 0 ].body, 'idpg_pdn_consent' )
	);
}

// 5. Поле читается по точному имени, а не по «любому чекбоксу».
{
	const dom = buildDom( { consentChecked: true } );
	// Переименовываем чекбокс согласия в ошибочное имя.
	dom.pdnBox = null;
	delete dom.form.selectors[ '[name="idpg_pdn_consent"]' ];
	dom.form.selectors[ '[name="idpg_pdn_agreement"]' ] = ( function () {
		const el = new StubElement( 'input' );
		el.checked = true;
		return el;
	} )();

	const { fetches } = submitRegister( dom );

	check(
		'чекбокс под чужим именем не считается согласием',
		fetches[ 0 ] && false === fetches[ 0 ].body.idpg_pdn_consent,
		fetches[ 0 ] ? String( fetches[ 0 ].body.idpg_pdn_consent ) : 'нет запроса'
	);
}

console.log( '\nRESULT: PASS=' + passed + ' FAIL=' + failed );
process.exit( failed > 0 ? 1 : 0 );
