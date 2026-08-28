import { useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Spinner, Notice, TabPanel } from '@wordpress/components';
import { api } from './api';
import ReviewsList from './views/ReviewsList';
import SettingsPanel from './views/SettingsPanel';
import MigrationPanel from './views/MigrationPanel';

export default function App() {
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ boot, setBoot ] = useState( null );
	const [ counts, setCounts ] = useState( {} );
	const [ flash, setFlash ] = useState( null );

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

	return (
		<div className="advery-rv">
			<header className="advery-rv__head">
				<div className="advery-rv__brand">
					<span className="advery-rv__logo" aria-hidden="true">★</span>
					<div>
						<h1 className="advery-rv__title">{ __( 'Advery Reviews', 'advery-reviews' ) }</h1>
						<p className="advery-rv__sub">{ __( 'Ratings, moderation, replies and migration', 'advery-reviews' ) }</p>
					</div>
				</div>
				<div className="advery-rv__tiles">
					{ tiles.map( ( t ) => (
						<div key={ t.key } className={ 'advery-rv__tile is-' + t.tone }>
							<span className="advery-rv__tile-value">{ t.value }</span>
							<span className="advery-rv__tile-label">{ t.label }</span>
						</div>
					) ) }
				</div>
			</header>

			{ flash && (
				<Notice status={ flash.status } isDismissible onRemove={ () => setFlash( null ) }>
					{ flash.message }
				</Notice>
			) }

			<TabPanel
				className="advery-rv__tabs"
				tabs={ [
					{ name: 'reviews', title: __( 'Reviews', 'advery-reviews' ) },
					{ name: 'settings', title: __( 'Settings', 'advery-reviews' ) },
					{ name: 'migration', title: __( 'Migration', 'advery-reviews' ) },
				] }
			>
				{ ( tab ) => {
					if ( tab.name === 'reviews' ) {
						return <ReviewsList counts={ counts } setCounts={ setCounts } notify={ notify } />;
					}
					if ( tab.name === 'migration' ) {
						return <MigrationPanel boot={ boot } notify={ notify } />;
					}
					return <SettingsPanel boot={ boot } notify={ notify } />;
				} }
			</TabPanel>
		</div>
	);
}
