import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Panel,
	PanelBody,
	CheckboxControl,
	ToggleControl,
	Button,
	Notice,
	Spinner,
} from '@wordpress/components';
import { api } from '../api';

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
		if ( deleteSource && ! window.confirm( __( 'Delete the source comments after importing? This cannot be undone.', 'advery-reviews' ) ) ) {
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
			notify( 'success', sprintf( __( 'Imported %1$d, updated %2$d, skipped %3$d.', 'advery-reviews' ), totals.imported, totals.updated, totals.skipped ) );
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
			notify( 'success', sprintf( __( 'Exported %d reviews to comments.', 'advery-reviews' ), exported ) );
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
			a.download = res.filename || 'advery-reviews.csv';
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
		return <div className="advery-rv-loading"><Spinner /></div>;
	}

	return (
		<div className="advery-rv-migration">
			<Panel>
				<PanelBody title={ __( 'Import comments → reviews', 'advery-reviews' ) } initialOpen>
					<p className="advery-rv-hint">
						{ sprintf(
							__( 'Available: %1$d WordPress comments, %2$d WooCommerce reviews. Already imported: %3$d. Importing copies them (non-destructive) and de-duplicates on re-run.', 'advery-reviews' ),
							preview.import.wp,
							preview.import.wc,
							preview.import.imported
						) }
					</p>
					<CheckboxControl
						label={ sprintf( __( 'WordPress post comments (%d)', 'advery-reviews' ), preview.import.wp ) }
						checked={ sources.includes( 'wp_comment' ) }
						onChange={ ( on ) => toggleSource( 'wp_comment', on ) }
						__nextHasNoMarginBottom
					/>
					<CheckboxControl
						label={ sprintf( __( 'WooCommerce product reviews (%d)', 'advery-reviews' ), preview.import.wc ) }
						checked={ sources.includes( 'wc_review' ) }
						onChange={ ( on ) => toggleSource( 'wc_review', on ) }
						__nextHasNoMarginBottom
					/>
					<ToggleControl
						label={ __( 'Update reviews already imported (otherwise skip)', 'advery-reviews' ) }
						checked={ updateExisting }
						onChange={ setUpdateExisting }
						__nextHasNoMarginBottom
					/>
					<ToggleControl
						label={ __( 'Delete the source comments after importing (destructive)', 'advery-reviews' ) }
						checked={ deleteSource }
						onChange={ setDeleteSource }
						__nextHasNoMarginBottom
					/>
					{ deleteSource && (
						<Notice status="warning" isDismissible={ false }>
							{ __( 'The original comments will be permanently deleted after import.', 'advery-reviews' ) }
						</Notice>
					) }
					<div style={ { marginTop: 12 } }>
						<Button variant="primary" isBusy={ running === 'import' } disabled={ !! running || ! sources.length } onClick={ runImport }>
							{ __( 'Import now', 'advery-reviews' ) }
						</Button>
						{ running === 'import' && progress && (
							<span style={ { marginInlineStart: 10 } }>
								{ sprintf( __( 'imported %1$d, updated %2$d, skipped %3$d…', 'advery-reviews' ), progress.imported, progress.updated, progress.skipped ) }
							</span>
						) }
					</div>
				</PanelBody>

				<PanelBody title={ __( 'Export reviews → comments', 'advery-reviews' ) } initialOpen={ false }>
					<p className="advery-rv-hint">
						{ sprintf(
							__( '%1$d natively-collected reviews can be recreated as WordPress/WooCommerce comments (reversible; loop-guarded). Already exported: %2$d.', 'advery-reviews' ),
							preview.export.eligible,
							preview.export.exported
						) }
					</p>
					<Button variant="secondary" isBusy={ running === 'export' } disabled={ !! running } onClick={ runExport }>
						{ __( 'Export to comments', 'advery-reviews' ) }
					</Button>
					{ running === 'export' && progress && (
						<span style={ { marginInlineStart: 10 } }>{ sprintf( __( 'exported %d…', 'advery-reviews' ), progress.exported ) }</span>
					) }
				</PanelBody>

				<PanelBody title={ __( 'Backup', 'advery-reviews' ) } initialOpen={ false }>
					<p className="advery-rv-hint">{ __( 'Download every review as a CSV file.', 'advery-reviews' ) }</p>
					<Button variant="secondary" isBusy={ running === 'csv' } disabled={ !! running } onClick={ downloadCsv }>
						{ __( 'Download CSV', 'advery-reviews' ) }
					</Button>
				</PanelBody>
			</Panel>
		</div>
	);
}
