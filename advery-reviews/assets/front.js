/* Advery Reviews — front-end star picker + REST submission. No framework. */
( function () {
	'use strict';

	var cfg = window.AdveryReviewsFront || {};

	function initWidget( root ) {
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
				var v = parseInt( btn.getAttribute( 'data-value' ), 10 );
				ratingInput.value = v;
				paint( v );
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

			var payload = {
				object_type: root.getAttribute( 'data-object-type' ),
				object_id: root.getAttribute( 'data-object-id' ),
				rating: parseInt( ratingInput.value, 10 ) || 0,
				title: valueOf( form, 'title' ),
				content: valueOf( form, 'content' ),
				author_name: valueOf( form, 'author_name' ),
				author_email: valueOf( form, 'author_email' ),
				website_hp: valueOf( form, 'website_hp' )
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
		} );
	}

	function valueOf( form, name ) {
		var el = form.querySelector( '[name="' + name + '"]' );
		return el ? el.value : '';
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		Array.prototype.slice
			.call( document.querySelectorAll( '.advery-reviews' ) )
			.forEach( initWidget );
	} );
} )();
