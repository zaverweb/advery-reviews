import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	CheckboxControl,
	ToggleControl,
	Button,
	Notice,
	Spinner,
} from '@wordpress/components';
import { api } from '../api';
import DataImportPanel from './DataImportPanel';

export default function MigrationPanel( { boot, notify } ) {
	const [ preview, setPreview ] = useState( null );
	const [ sources, setSources ] = useState( [ 'wp_comment', 'wc_review' ] );
	const [ updateExisting, setUpdateExisting ] = useState( false );
	const [ deleteSource, setDeleteSource ] = useState( false );
	const [ running, setRunning ] = useState( '' );
	const [ progress, setProgress ] = useState( null );

	const loadPreview = useCallback( async () => {
		try {
			setPreview( await api.migrationPreview() );
		} catch ( e ) {
			notify( 'error', e.message );
		}
	}, [ notify ] );

	useEffect( () => {
		loadPreview();
	}, [ loadPreview ] );

	const toggleSource = ( slug, on ) =>
		setSources( ( prev ) => ( on ? [ ...new Set( [ ...prev, slug ] ) ] : prev.filter( ( x ) => x !== slug ) ) );

	const runImport = async () => {
		if ( ! sources.length ) {
			return;
		}
		if ( deleteSource && ! window.confirm( __( 'Delete the source comments after importing? This cannot be undone.', 'zaverweb-reviews' ) ) ) {
			return;
		}
		setRunning( 'import' );
		const totals = { imported: 0, updated: 0, skipped: 0 };
		let offset = 0;
		try {
			// Loop batches until the server says it's done.
			// eslint-disable-next-line no-constant-condition
			while ( true ) {
				const res = await api.migrationImport( {
					sources,
					update_existing: updateExisting,
					delete_source: deleteSource,
					limit: 100,
					offset,
				} );
				totals.imported += res.imported;
				totals.updated += res.updated;
				totals.skipped += res.skipped;
				setProgress( { ...totals } );
				offset = res.next_offset;
				if ( res.done ) {
					break;
				}
			}
			notify( 'success', sprintf( __( 'Imported %1$d, updated %2$d, skipped %3$d.', 'zaverweb-reviews' ), totals.imported, totals.updated, totals.skipped ) );
			await loadPreview();
		} catch ( e ) {
			notify( 'error', e.message );
		} finally {
			setRunning( '' );
			setProgress( null );
		}
	};

	const runExport = async () => {
		setRunning( 'export' );
		let offset = 0;
		let exported = 0;
		try {
			// eslint-disable-next-line no-constant-condition
			while ( true ) {
				const res = await api.migrationExport( { limit: 100, offset } );
				exported += res.exported;
				setProgress( { exported } );
				offset = res.next_offset;
				if ( res.done ) {
					break;
				}
			}
			notify( 'success', sprintf( __( 'Exported %d reviews to comments.', 'zaverweb-reviews' ), exported ) );
			await loadPreview();
		} catch ( e ) {
			notify( 'error', e.message );
		} finally {
			setRunning( '' );
			setProgress( null );
		}
	};

	const downloadCsv = async () => {
		setRunning( 'csv' );
		try {
			const res = await api.exportCsv();
			const blob = new Blob( [ res.csv ], { type: 'text/csv' } );
			const url = URL.createObjectURL( blob );
			const a = document.createElement( 'a' );
			a.href = url;
			a.download = res.filename || 'zaverweb-reviews.csv';
			document.body.appendChild( a );
			a.click();
			document.body.removeChild( a );
			URL.revokeObjectURL( url );
		} catch ( e ) {
			notify( 'error', e.message );
		} finally {
			setRunning( '' );
		}
	};

	if ( ! preview ) {
		return <div className="zaverweb-rv-loading"><Spinner /></div>;
	}

	return (
		<div className="zaverweb-rv-migration">
			<p className="zaverweb-rv-mig-intro">
				{ __( 'Move reviews in and out of Zaver Web Reviews. Everything here is safe to run more than once — imports copy your data and never create duplicates.', 'zaverweb-reviews' ) }
			</p>

			<div className="zaverweb-rv-mig-card">
				<div className="zaverweb-rv-mig-card__head">
					<span className="zaverweb-rv-mig-card__icon" aria-hidden="true">⬇️</span>
					<div>
						<h3 className="zaverweb-rv-mig-card__title">{ __( 'Import existing comments', 'zaverweb-reviews' ) }</h3>
						<p className="zaverweb-rv-mig-card__desc">
							{ __( 'Bring your current WordPress comments and WooCommerce reviews into Zaver Web Reviews. Your originals stay in place, and running this again only updates — it never duplicates.', 'zaverweb-reviews' ) }
						</p>
					</div>
				</div>
				<div className="zaverweb-rv-mig-card__body">
					<p className="zaverweb-rv-stat-row">
						<span>{ sprintf( __( '%d WordPress comments', 'zaverweb-reviews' ), preview.import.wp ) }</span>
						<span>{ sprintf( __( '%d WooCommerce reviews', 'zaverweb-reviews' ), preview.import.wc ) }</span>
						<span>{ sprintf( __( '%d already imported', 'zaverweb-reviews' ), preview.import.imported ) }</span>
					</p>
					<CheckboxControl label={ sprintf( __( 'Include WordPress post comments (%d)', 'zaverweb-reviews' ), preview.import.wp ) } checked={ sources.includes( 'wp_comment' ) } onChange={ ( on ) => toggleSource( 'wp_comment', on ) } __nextHasNoMarginBottom />
					<CheckboxControl label={ sprintf( __( 'Include WooCommerce product reviews (%d)', 'zaverweb-reviews' ), preview.import.wc ) } checked={ sources.includes( 'wc_review' ) } onChange={ ( on ) => toggleSource( 'wc_review', on ) } __nextHasNoMarginBottom />
					<ToggleControl label={ __( 'Update items already imported', 'zaverweb-reviews' ) } help={ __( 'On: re-importing refreshes reviews you imported before. Off: they’re skipped.', 'zaverweb-reviews' ) } checked={ updateExisting } onChange={ setUpdateExisting } __nextHasNoMarginBottom />
					<ToggleControl label={ __( 'Delete the original comments after importing', 'zaverweb-reviews' ) } help={ __( 'Off (recommended): keep a copy in the comment tables. On: permanently remove the source comments once imported.', 'zaverweb-reviews' ) } checked={ deleteSource } onChange={ setDeleteSource } __nextHasNoMarginBottom />
					{ deleteSource && (
						<Notice status="warning" isDismissible={ false }>{ __( 'The original comments will be permanently deleted after import.', 'zaverweb-reviews' ) }</Notice>
					) }
					<div className="zaverweb-rv-mig-actions">
						<Button variant="primary" isBusy={ running === 'import' } disabled={ !! running || ! sources.length } onClick={ runImport }>{ __( 'Import now', 'zaverweb-reviews' ) }</Button>
						{ running === 'import' && progress && (
							<span className="zaverweb-rv-mig-progress">{ sprintf( __( 'imported %1$d · updated %2$d · skipped %3$d…', 'zaverweb-reviews' ), progress.imported, progress.updated, progress.skipped ) }</span>
						) }
					</div>
				</div>
			</div>

			<div className="zaverweb-rv-mig-card">
				<div className="zaverweb-rv-mig-card__head">
					<span className="zaverweb-rv-mig-card__icon" aria-hidden="true">⬆️</span>
					<div>
						<h3 className="zaverweb-rv-mig-card__title">{ __( 'Export reviews back to comments', 'zaverweb-reviews' ) }</h3>
						<p className="zaverweb-rv-mig-card__desc">
							{ __( 'Recreate native WordPress / WooCommerce comments from the reviews you collected here — handy if another tool reads the comment tables. Safe to run repeatedly; it won’t loop or duplicate.', 'zaverweb-reviews' ) }
						</p>
					</div>
				</div>
				<div className="zaverweb-rv-mig-card__body">
					<p className="zaverweb-rv-stat-row">
						<span>{ sprintf( __( '%d reviews eligible', 'zaverweb-reviews' ), preview.export.eligible ) }</span>
						<span>{ sprintf( __( '%d already exported', 'zaverweb-reviews' ), preview.export.exported ) }</span>
					</p>
					<div className="zaverweb-rv-mig-actions">
						<Button variant="secondary" isBusy={ running === 'export' } disabled={ !! running } onClick={ runExport }>{ __( 'Export to comments', 'zaverweb-reviews' ) }</Button>
						{ running === 'export' && progress && (
							<span className="zaverweb-rv-mig-progress">{ sprintf( __( 'exported %d…', 'zaverweb-reviews' ), progress.exported ) }</span>
						) }
					</div>
				</div>
			</div>

			<div className="zaverweb-rv-mig-card">
				<div className="zaverweb-rv-mig-card__head">
					<span className="zaverweb-rv-mig-card__icon" aria-hidden="true">💾</span>
					<div>
						<h3 className="zaverweb-rv-mig-card__title">{ __( 'Backup (CSV)', 'zaverweb-reviews' ) }</h3>
						<p className="zaverweb-rv-mig-card__desc">{ __( 'Download every review as a CSV file — a spreadsheet you can keep as a backup or move to another site.', 'zaverweb-reviews' ) }</p>
					</div>
				</div>
				<div className="zaverweb-rv-mig-card__body">
					<div className="zaverweb-rv-mig-actions">
						<Button variant="secondary" isBusy={ running === 'csv' } disabled={ !! running } onClick={ downloadCsv }>{ __( 'Download CSV', 'zaverweb-reviews' ) }</Button>
					</div>
				</div>
			</div>

			<DataImportPanel notify={ notify } />
		</div>
	);
}
