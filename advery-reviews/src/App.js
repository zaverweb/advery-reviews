import { useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Spinner, Notice } from '@wordpress/components';
import { api } from './api';
import ReviewsList from './views/ReviewsList';
import ReportsPanel from './views/ReportsPanel';
import SettingsPanel from './views/SettingsPanel';
import MigrationPanel from './views/MigrationPanel';

const cfg = window.AdveryReviewsConfig || {};

/**
 * Which screen a WordPress admin URL points at (from its `page` query var), so
 * back/forward and direct loads resolve to the right view.
 */
function screenFromLocation() {
	let page = '';
	try {
		page = new URLSearchParams( window.location.search ).get( 'page' ) || '';
	} catch ( e ) {
		page = '';
	}
	if ( page.endsWith( '-reports' ) ) {
		return 'reports';
	}
	if ( page.endsWith( '-settings' ) ) {
		return 'settings';
	}
	if ( page.endsWith( '-migration' ) ) {
		return 'migration';
	}
	return 'reviews';
}

export default function App( { screen: initialScreen = 'reviews' } ) {
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ boot, setBoot ] = useState( null );
	const [ counts, setCounts ] = useState( {} );
	const [ flash, setFlash ] = useState( null );
	// The active screen is state now, so switching between Reviews / Reports /
	// Settings / Migration is instant (no full page reload) while the URL still
	// updates via the History API. The four WP sub-pages all load this one app.
	const [ screen, setScreen ] = useState( initialScreen );

	// Client-side navigation: change the URL + the screen without reloading.
	const navTo = useCallback( ( key, href, e ) => {
		if ( e ) {
			// Let modified clicks (new tab, etc.) behave natively.
			if ( e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button ) {
				return;
			}
			e.preventDefault();
		}
		if ( href && window.history && window.history.pushState ) {
			window.history.pushState( { adveryScreen: key }, '', href );
			setScreen( key );
			window.scrollTo( 0, 0 );
		} else if ( href ) {
			window.location.href = href;
		}
	}, [] );

	// Back / forward buttons.
	useEffect( () => {
		const onPop = () => setScreen( screenFromLocation() );
		window.addEventListener( 'popstate', onPop );
		return () => window.removeEventListener( 'popstate', onPop );
	}, [] );

	// Keep the WordPress admin sub-menu highlight in sync with the active screen
	// (pushState doesn't reload, so WP wouldn't otherwise move the "current" mark).
	useEffect( () => {
		const menuSlug = cfg.menuSlug || 'advery-reviews';
		const want = 'reviews' === screen ? menuSlug : menuSlug + '-' + screen;
		try {
			document.querySelectorAll( '#adminmenu a[href*="page=' + menuSlug + '"]' ).forEach( ( a ) => {
				const page = new URLSearchParams( a.search ).get( 'page' );
				const li = a.closest( 'li' );
				const on = page === want;
				a.classList.toggle( 'current', on );
				if ( li ) {
					li.classList.toggle( 'current', on );
				}
			} );
		} catch ( e ) {
			// Non-fatal: the in-app nav still shows the active screen.
		}
	}, [ screen ] );

	const load = useCallback( async () => {
		setLoading( true );
		try {
			const data = await api.bootstrap();
			setBoot( data );
			setCounts( data.counts || {} );
			setError( null );
		} catch ( e ) {
			setError( e.message || __( 'Failed to load.', 'advery-reviews' ) );
		} finally {
			setLoading( false );
		}
	}, [] );

	useEffect( () => {
		load();
	}, [ load ] );

	const notify = useCallback( ( status, message ) => {
		setFlash( { status, message } );
		window.clearTimeout( notify._t );
		notify._t = window.setTimeout( () => setFlash( null ), 4000 );
	}, [] );

	if ( loading ) {
		return (
			<div className="advery-rv-loading">
				<Spinner />
			</div>
		);
	}
	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}

	const total = ( counts.pending || 0 ) + ( counts.approved || 0 ) + ( counts.spam || 0 ) + ( counts.trash || 0 );
	const tiles = [
		{ key: 'pending', label: __( 'Pending', 'advery-reviews' ), value: counts.pending || 0, tone: 'amber' },
		{ key: 'approved', label: __( 'Approved', 'advery-reviews' ), value: counts.approved || 0, tone: 'green' },
		{ key: 'spam', label: __( 'Spam', 'advery-reviews' ), value: counts.spam || 0, tone: 'red' },
		{ key: 'total', label: __( 'Total', 'advery-reviews' ), value: total, tone: 'blue' },
	];

	const nav = [
		{ key: 'reviews', label: __( 'Reviews', 'advery-reviews' ), href: cfg.reviewsUrl },
		{ key: 'reports', label: __( 'Reports', 'advery-reviews' ), href: cfg.reportsUrl },
		{ key: 'settings', label: __( 'Settings', 'advery-reviews' ), href: cfg.settingsUrl },
		{ key: 'migration', label: __( 'Migration', 'advery-reviews' ), href: cfg.migrationUrl },
	];

	const titles = {
		reviews: __( 'Moderate and reply to reviews', 'advery-reviews' ),
		reports: __( 'See which pages and businesses get the most reviews', 'advery-reviews' ),
		settings: __( 'Configure how reviews work', 'advery-reviews' ),
		migration: __( 'Import, export and migrate reviews', 'advery-reviews' ),
	};

	return (
		<div className="advery-rv">
			<header className="advery-rv__head">
				<div className="advery-rv__brand">
					<span className="advery-rv__logo" aria-hidden="true">★</span>
					<div>
						<h1 className="advery-rv__title">{ __( 'Advery Reviews', 'advery-reviews' ) }</h1>
						<p className="advery-rv__sub">{ titles[ screen ] || '' }</p>
					</div>
				</div>
				<div className="advery-rv__tiles">
					{ tiles.map( ( t ) => (
						<a key={ t.key } className={ 'advery-rv__tile is-' + t.tone } href={ cfg.reviewsUrl || '#' } onClick={ ( e ) => navTo( 'reviews', cfg.reviewsUrl, e ) }>
							<span className="advery-rv__tile-value">{ t.value }</span>
							<span className="advery-rv__tile-label">{ t.label }</span>
						</a>
					) ) }
				</div>
			</header>

			<nav className="advery-rv__nav">
				{ nav.map( ( n ) => (
					<a key={ n.key } href={ n.href || '#' } className={ 'advery-rv__navlink' + ( screen === n.key ? ' is-active' : '' ) } onClick={ ( e ) => navTo( n.key, n.href, e ) }>
						{ n.label }
					</a>
				) ) }
			</nav>

			{ flash && (
				<Notice status={ flash.status } isDismissible onRemove={ () => setFlash( null ) }>
					{ flash.message }
				</Notice>
			) }

			{ screen === 'reports' && <ReportsPanel boot={ boot } notify={ notify } /> }
			{ screen === 'settings' && <SettingsPanel boot={ boot } notify={ notify } /> }
			{ screen === 'migration' && <MigrationPanel boot={ boot } notify={ notify } /> }
			{ screen === 'reviews' && (
				<ReviewsList boot={ boot } counts={ counts } setCounts={ setCounts } notify={ notify } />
			) }
		</div>
	);
}
