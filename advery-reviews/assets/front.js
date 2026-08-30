/* Advery Reviews — front-end star picker, REST submission, and AJAX loading.
   No framework. The page URL never changes (no query params / history writes),
   so pagination and "load more" don't fragment SEO; the first page is already
   server-rendered for crawlers. */
( function () {
	'use strict';

	var cfg = window.AdveryReviewsFront || {};

	function stars( n ) {
		n = Math.max( 0, Math.min( 5, parseInt( n, 10 ) || 0 ) );
		return '★'.repeat( n ) + '☆'.repeat( 5 - n );
	}

	function el( tag, cls, text ) {
		var e = document.createElement( tag );
		if ( cls ) {
			e.className = cls;
		}
		if ( text != null ) {
			e.textContent = text;
		}
		return e;
	}

	function renderItem( r ) {
		var li = el( 'li', 'advery-reviews__item' );
		var head = el( 'div', 'advery-reviews__item-head' );
		head.appendChild( el( 'strong', 'advery-reviews__author', r.author_name || ( cfg.i18n && cfg.i18n.anonymous ) || '' ) );
		if ( r.rating > 0 ) {
			head.appendChild( el( 'span', 'advery-reviews__stars', stars( r.rating ) ) );
		}
		li.appendChild( head );
		if ( r.title ) {
			li.appendChild( el( 'div', 'advery-reviews__title', r.title ) );
		}
		var content = el( 'div', 'advery-reviews__content' );
		content.innerHTML = r.content || ''; // server-sanitized (kses allowlist)
		li.appendChild( content );
		return li;
	}

	function fetchPage( root, page ) {
		var type = root.getAttribute( 'data-object-type' );
		var id = root.getAttribute( 'data-object-id' );
		var per = root.getAttribute( 'data-per-page' ) || 10;
		var url = cfg.listBase + '/' + encodeURIComponent( type ) + '/' + encodeURIComponent( id ) +
			'?page=' + encodeURIComponent( page ) + '&per_page=' + encodeURIComponent( per );
		return fetch( url, { headers: { 'X-WP-Nonce': cfg.nonce } } ).then( function ( r ) {
			return r.json();
		} );
	}

	function initLoading( root ) {
		var list = root.querySelector( '.advery-reviews__list' );
		var mode = root.getAttribute( 'data-load-mode' );

		if ( mode === 'load_more' ) {
			var btn = root.querySelector( '.advery-reviews__loadmore' );
			if ( ! btn ) {
				return;
			}
			btn.addEventListener( 'click', function () {
				var next = ( parseInt( root.getAttribute( 'data-page' ), 10 ) || 1 ) + 1;
				var label = btn.textContent;
				btn.disabled = true;
				btn.textContent = ( cfg.i18n && cfg.i18n.loading ) || 'Loading…';
				fetchPage( root, next ).then( function ( data ) {
					( data.items || [] ).forEach( function ( r ) {
						list.appendChild( renderItem( r ) );
					} );
					root.setAttribute( 'data-page', next );
					var total = parseInt( root.getAttribute( 'data-total' ), 10 ) || 0;
					var per = parseInt( root.getAttribute( 'data-per-page' ), 10 ) || 10;
					if ( next * per >= total ) {
						btn.parentNode.removeChild( btn );
					} else {
						btn.disabled = false;
						btn.textContent = label;
					}
				} ).catch( function () {
					btn.disabled = false;
					btn.textContent = label;
				} );
			} );
		} else if ( mode === 'paginate' ) {
			var pager = root.querySelector( '.advery-reviews__pager' );
			if ( ! pager ) {
				return;
			}
			pager.addEventListener( 'click', function ( e ) {
				var b = e.target.closest( '.advery-reviews__page' );
				if ( ! b ) {
					return;
				}
				var page = parseInt( b.getAttribute( 'data-page' ), 10 ) || 1;
				list.style.opacity = '0.5';
				fetchPage( root, page ).then( function ( data ) {
					list.innerHTML = '';
					( data.items || [] ).forEach( function ( r ) {
						list.appendChild( renderItem( r ) );
					} );
					list.style.opacity = '';
					root.setAttribute( 'data-page', page );
					Array.prototype.forEach.call( pager.querySelectorAll( '.advery-reviews__page' ), function ( x ) {
						x.classList.toggle( 'is-active', x === b );
					} );
					if ( root.scrollIntoView ) {
						root.scrollIntoView( { behavior: 'smooth', block: 'start' } );
					}
				} ).catch( function () {
					list.style.opacity = '';
				} );
			} );
		}
	}

	function initForm( root ) {
		var form = root.querySelector( '.advery-reviews__form' );
		if ( ! form ) {
			return;
		}

		var ratingInput = form.querySelector( 'input[name="rating"]' );
		var starButtons = Array.prototype.slice.call( form.querySelectorAll( '.advery-reviews__star-btn' ) );

		function paint( value ) {
			starButtons.forEach( function ( btn ) {
				var v = parseInt( btn.getAttribute( 'data-value' ), 10 );
				btn.textContent = v <= value ? '★' : '☆';
				btn.classList.toggle( 'is-on', v <= value );
			} );
		}

		starButtons.forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				ratingInput.value = parseInt( btn.getAttribute( 'data-value' ), 10 );
				paint( parseInt( ratingInput.value, 10 ) );
			} );
			btn.addEventListener( 'mouseenter', function () {
				paint( parseInt( btn.getAttribute( 'data-value' ), 10 ) );
			} );
		} );
		form.addEventListener( 'mouseleave', function () {
			paint( parseInt( ratingInput.value, 10 ) || 0 );
		} );

		var msg = form.querySelector( '.advery-reviews__msg' );
		var submit = form.querySelector( '.advery-reviews__submit' );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			msg.className = 'advery-reviews__msg';
			msg.textContent = cfg.i18n ? cfg.i18n.sending : 'Sending…';
			submit.disabled = true;
			getCaptchaToken().then( send );
		} );

		function send( captchaToken ) {
			var payload = {
				object_type: root.getAttribute( 'data-object-type' ),
				object_id: root.getAttribute( 'data-object-id' ),
				rating: parseInt( ratingInput.value, 10 ) || 0,
				title: valueOf( form, 'title' ),
				content: valueOf( form, 'content' ),
				author_name: valueOf( form, 'author_name' ),
				author_email: valueOf( form, 'author_email' ),
				advery_hp: valueOf( form, 'advery_hp' ),
				advery_ts: valueOf( form, 'advery_ts' ),
				advery_tk: valueOf( form, 'advery_tk' ),
				captcha_token: captchaToken
			};

			fetch( cfg.rest, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
				body: JSON.stringify( payload )
			} )
				.then( function ( r ) {
					return r.json().then( function ( body ) {
						return { ok: r.ok, body: body };
					} );
				} )
				.then( function ( res ) {
					submit.disabled = false;
					if ( res.ok && res.body && res.body.ok ) {
						form.reset();
						paint( 0 );
						ratingInput.value = 0;
						msg.className = 'advery-reviews__msg is-success';
						msg.textContent = res.body.message || 'Thanks!';
					} else {
						msg.className = 'advery-reviews__msg is-error';
						msg.textContent = ( res.body && res.body.message ) || ( cfg.i18n ? cfg.i18n.error : 'Error' );
					}
				} )
				.catch( function () {
					submit.disabled = false;
					msg.className = 'advery-reviews__msg is-error';
					msg.textContent = cfg.i18n ? cfg.i18n.error : 'Error';
				} );
		}

		function getCaptchaToken() {
			var c = cfg.captcha || {};
			if ( c.provider === 'recaptcha_v3' && window.grecaptcha && c.siteKey ) {
				return new Promise( function ( resolve ) {
					grecaptcha.ready( function () {
						grecaptcha.execute( c.siteKey, { action: 'submit' } )
							.then( resolve )
							.catch( function () { resolve( '' ); } );
					} );
				} );
			}
			var names = {
				recaptcha_v2: 'g-recaptcha-response',
				hcaptcha: 'h-captcha-response',
				turnstile: 'cf-turnstile-response'
			};
			var field = names[ c.provider ];
			return Promise.resolve( field ? valueOf( form, field ) : '' );
		}
	}

	function valueOf( form, name ) {
		var e = form.querySelector( '[name="' + name + '"]' );
		return e ? e.value : '';
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		Array.prototype.slice.call( document.querySelectorAll( '.advery-reviews' ) ).forEach( function ( root ) {
			initForm( root );
			initLoading( root );
		} );
	} );
} )();
