/* Zaver Web Reviews — post-edit metabox. Vanilla JS + REST; no framework. */
( function () {
	'use strict';
	var cfg = window.ZaverWebReviewsMeta || {};
	var i18n = cfg.i18n || {};
	var root = document.getElementById( 'zaverweb-reviews-metabox' );
	if ( ! root || ! cfg.rest ) {
		return;
	}
	var TYPE = root.getAttribute( 'data-object-type' );
	var ID = root.getAttribute( 'data-object-id' );

	function api( path, opts ) {
		opts = opts || {};
		opts.headers = Object.assign( { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce }, opts.headers || {} );
		return fetch( cfg.rest + path, opts ).then( function ( r ) {
			return r.json().then( function ( b ) { return { ok: r.ok, body: b }; } );
		} );
	}
	function el( tag, cls, text ) {
		var e = document.createElement( tag );
		if ( cls ) { e.className = cls; }
		if ( text != null ) { e.textContent = text; }
		return e;
	}
	function stars( n ) { n = parseInt( n, 10 ) || 0; return '★'.repeat( n ) + '☆'.repeat( 5 - n ); }

	function load() {
		root.textContent = '…';
		api( '/reviews?per_page=100&object_type=' + encodeURIComponent( TYPE ) + '&object_id=' + encodeURIComponent( ID ) )
			.then( function ( res ) {
				root.innerHTML = '';
				var items = ( res.body && res.body.items ) || [];
				// Only this object's reviews (the list endpoint filters by object_type
				// but not object_id, so filter here).
				items = items.filter( function ( r ) { return String( r.object_id ) === String( ID ); } );
				render( items );
			} )
			.catch( function () { root.textContent = i18n.error || 'Error'; } );
	}

	function render( items ) {
		var wrap = el( 'div', 'zaverweb-mb' );

		if ( ! items.length ) {
			wrap.appendChild( el( 'p', 'zaverweb-mb__empty', i18n.none || 'No reviews yet.' ) );
		} else {
			var list = el( 'ul', 'zaverweb-mb__list' );
			items.forEach( function ( r ) { list.appendChild( row( r ) ); } );
			wrap.appendChild( list );
		}

		wrap.appendChild( addForm() );
		root.appendChild( wrap );
	}

	function row( r ) {
		var li = el( 'li', 'zaverweb-mb__item is-' + r.status );
		var head = el( 'div', 'zaverweb-mb__head' );
		head.appendChild( el( 'strong', null, r.author_name || '—' ) );
		if ( r.rating > 0 ) { head.appendChild( el( 'span', 'zaverweb-mb__stars', stars( r.rating ) ) ); }
		head.appendChild( el( 'span', 'zaverweb-mb__badge is-' + r.status, r.status ) );
		li.appendChild( head );
		if ( r.title ) { li.appendChild( el( 'div', 'zaverweb-mb__title', r.title ) ); }
		li.appendChild( el( 'div', 'zaverweb-mb__content', ( r.content || '' ).replace( /<[^>]+>/g, '' ) ) );

		var actions = el( 'div', 'zaverweb-mb__actions' );
		[ [ 'approved', i18n.approve ], [ 'pending', i18n.pending ], [ 'spam', i18n.spam ], [ 'trash', i18n.trash ] ].forEach( function ( a ) {
			if ( r.status === a[ 0 ] ) { return; }
			var b = el( 'button', 'button-link', a[ 1 ] );
			b.type = 'button';
			b.addEventListener( 'click', function () { setStatus( r.id, a[ 0 ] ); } );
			actions.appendChild( b );
		} );
		var del = el( 'button', 'button-link zaverweb-mb__del', i18n.delete );
		del.type = 'button';
		del.addEventListener( 'click', function () { if ( window.confirm( i18n.delete + '?' ) ) { remove( r.id ); } } );
		actions.appendChild( del );
		li.appendChild( actions );
		return li;
	}

	function addForm() {
		var f = el( 'div', 'zaverweb-mb__form' );
		f.appendChild( el( 'h4', null, i18n.add || 'Add a review' ) );

		var name = input( 'text', i18n.name );
		var email = input( 'email', i18n.email );

		// "Add as me": use the logged-in manager's own identity instead of typing
		// custom details. Toggling it fills + locks the name/email fields; the
		// operator can still uncheck it to enter arbitrary details.
		var me = cfg.currentUser || {};
		var asMeWrap = el( 'label', 'zaverweb-mb__asme' );
		var asMe = document.createElement( 'input' );
		asMe.type = 'checkbox';
		asMeWrap.appendChild( asMe );
		asMeWrap.appendChild( document.createTextNode( ' ' + ( i18n.asMe || 'Add as me' ) + ( me.name ? ' (' + me.name + ')' : '' ) ) );
		if ( ! me.name ) { asMeWrap.style.display = 'none'; }

		asMe.addEventListener( 'change', function () {
			if ( asMe.checked ) {
				name.value = me.name || '';
				email.value = me.email || '';
				name.disabled = true;
				email.disabled = true;
			} else {
				name.disabled = false;
				email.disabled = false;
			}
		} );

		var rating = document.createElement( 'select' );
		[ 5, 4, 3, 2, 1, 0 ].forEach( function ( n ) {
			var o = document.createElement( 'option' );
			o.value = n; o.textContent = n ? stars( n ) : ( i18n.rating + ': —' );
			rating.appendChild( o );
		} );
		var content = document.createElement( 'textarea' );
		content.rows = 2; content.placeholder = i18n.content;
		var save = el( 'button', 'button button-primary', i18n.save );
		save.type = 'button';
		var msg = el( 'span', 'zaverweb-mb__msg' );

		save.addEventListener( 'click', function () {
			if ( ! content.value.trim() ) { return; }
			save.disabled = true;
			api( '/reviews', {
				method: 'POST',
				body: JSON.stringify( {
					object_type: TYPE, object_id: ID,
					rating: parseInt( rating.value, 10 ) || 0,
					// When "add as me" is on, let the server fill identity from the
					// current user (authoritative), ignoring the disabled fields.
					as_current_user: asMe.checked ? 1 : 0,
					author_name: name.value, author_email: email.value,
					content: content.value, status: 'approved'
				} )
			} ).then( function ( res ) {
				save.disabled = false;
				if ( res.ok && res.body && res.body.ok ) { load(); }
				else { msg.textContent = ( res.body && res.body.message ) || i18n.error; }
			} ).catch( function () { save.disabled = false; msg.textContent = i18n.error; } );
		} );

		f.appendChild( asMeWrap );
		f.appendChild( name );
		f.appendChild( email );
		f.appendChild( rating );
		f.appendChild( content );
		f.appendChild( save );
		f.appendChild( msg );
		return f;
	}

	function input( type, ph ) {
		var i = document.createElement( 'input' );
		i.type = type; i.placeholder = ph;
		return i;
	}

	function setStatus( id, status ) {
		api( '/reviews/' + id + '/status', { method: 'POST', body: JSON.stringify( { status: status } ) } ).then( load );
	}
	function remove( id ) {
		api( '/reviews/' + id, { method: 'DELETE' } ).then( load );
	}

	load();
} )();
