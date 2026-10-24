/**
 * Документация проекта «Иду по городу» — интерактивность.
 * Тема (localStorage) · мобильное меню · копирование кода · scroll-to-top.
 */
( function () {
	'use strict';

	/* ── Тема ── */
	var STORAGE_KEY = 'idpg-docs-theme';

	function applyTheme( theme ) {
		document.documentElement.setAttribute( 'data-theme', theme );
		var btn = document.querySelector( '.theme-toggle__label' );
		if ( btn ) {
			btn.textContent = 'dark' === theme ? 'Тёмная тема' : 'Светлая тема';
		}
	}

	var saved = null;
	try {
		saved = localStorage.getItem( STORAGE_KEY );
	} catch ( e ) {}

	if ( ! saved ) {
		saved = window.matchMedia && window.matchMedia( '(prefers-color-scheme: dark)' ).matches
			? 'dark'
			: 'light';
	}
	applyTheme( saved );

	document.addEventListener( 'click', function ( e ) {
		var toggle = e.target.closest( '.theme-toggle' );
		if ( ! toggle ) {
			return;
		}
		var current = document.documentElement.getAttribute( 'data-theme' );
		var next = 'dark' === current ? 'light' : 'dark';
		applyTheme( next );
		try {
			localStorage.setItem( STORAGE_KEY, next );
		} catch ( err ) {}
	} );

	/* ── Мобильное меню ── */
	var burger = document.querySelector( '.nav-burger' );
	var sidebar = document.querySelector( '.sidebar' );

	if ( burger && sidebar ) {
		burger.addEventListener( 'click', function () {
			sidebar.classList.toggle( 'is-open' );
		} );

		// Закрытие по клику вне сайдбара.
		document.addEventListener( 'click', function ( e ) {
			if ( ! sidebar.classList.contains( 'is-open' ) ) {
				return;
			}
			if ( ! e.target.closest( '.sidebar' ) && ! e.target.closest( '.nav-burger' ) ) {
				sidebar.classList.remove( 'is-open' );
			}
		} );
	}

	/* ── Копирование кода ── */
	document.querySelectorAll( '.codeblock' ).forEach( function ( block ) {
		var btn = block.querySelector( '.codeblock__copy' );
		if ( ! btn ) {
			return;
		}
		btn.addEventListener( 'click', function () {
			var pre = block.querySelector( 'pre' );
			if ( ! pre ) {
				return;
			}
			var text = pre.innerText;

			function done() {
				btn.textContent = 'Скопировано';
				btn.classList.add( 'is-copied' );
				setTimeout( function () {
					btn.textContent = 'Копировать';
					btn.classList.remove( 'is-copied' );
				}, 1600 );
			}

			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then( done, function () {
					fallbackCopy( text );
					done();
				} );
			} else {
				fallbackCopy( text );
				done();
			}
		} );
	} );

	function fallbackCopy( text ) {
		var ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.style.position = 'fixed';
		ta.style.opacity = '0';
		document.body.appendChild( ta );
		ta.select();
		try {
			document.execCommand( 'copy' );
		} catch ( e ) {}
		document.body.removeChild( ta );
	}

	/* ── Кнопка «Наверх» ── */
	var scrollTop = document.querySelector( '.scroll-top' );
	if ( scrollTop ) {
		window.addEventListener( 'scroll', function () {
			scrollTop.classList.toggle( 'is-visible', window.scrollY > 400 );
		}, { passive: true } );

		scrollTop.addEventListener( 'click', function () {
			window.scrollTo( { top: 0, behavior: 'smooth' } );
		} );
	}

	/* ── Активная ссылка в сайдбаре ── */
	var current = location.pathname.split( '/' ).pop() || 'index.html';
	document.querySelectorAll( '.sidebar__link' ).forEach( function ( link ) {
		var href = link.getAttribute( 'href' ) || '';
		if ( href === current || href.endsWith( '/' + current ) ) {
			link.classList.add( 'is-active' );
		}
	} );
} )();
