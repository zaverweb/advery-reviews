import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	SelectControl,
	TextControl,
	ToggleControl,
	Button,
	Notice,
} from '@wordpress/components';
import { api } from '../api';

/* Minimal CSV parser: handles quoted fields, embedded commas/newlines, and
   escaped double-quotes. Returns { columns, rows(objects keyed by header) }. */
function parseCsv( text ) {
	const records = [];
	let field = '';
	let record = [];
	let inQuotes = false;
	for ( let i = 0; i < text.length; i++ ) {
		const ch = text[ i ];
		if ( inQuotes ) {
			if ( ch === '"' ) {
				if ( text[ i + 1 ] === '"' ) {
					field += '"';
					i++;
				} else {
					inQuotes = false;
				}
			} else {
				field += ch;
			}
		} else if ( ch === '"' ) {
			inQuotes = true;
		} else if ( ch === ',' ) {
			record.push( field );
			field = '';
		} else if ( ch === '\n' || ch === '\r' ) {
			if ( ch === '\r' && text[ i + 1 ] === '\n' ) {
				i++;
			}
			record.push( field );
			field = '';
			if ( record.length > 1 || record[ 0 ] !== '' ) {
				records.push( record );
			}
			record = [];
		} else {
			field += ch;
		}
	}
	if ( field !== '' || record.length ) {
		record.push( field );
		records.push( record );
	}
	if ( ! records.length ) {
		return { columns: [], rows: [] };
	}
	const columns = records[ 0 ].map( ( c ) => c.trim() );
	const rows = records.slice( 1 ).map( ( r ) => {
		const obj = {};
		columns.forEach( ( c, idx ) => {
			obj[ c ] = r[ idx ] != null ? r[ idx ] : '';
		} );
		return obj;
	} );
	return { columns, rows };
}

function parseJson( text ) {
	let data = JSON.parse( text );
	if ( ! Array.isArray( data ) ) {
		data = data.items || data.reviews || [];
	}
	const columns = data.length ? Object.keys( data[ 0 ] ) : [];
	return { columns, rows: data };
}

export default function DataImportPanel( { notify } ) {
	const [ rows, setRows ] = useState( [] );
	const [ columns, setColumns ] = useState( [] );
	const [ mapping, setMapping ] = useState( {
		target_mode: 'post_id',
		target_column: '',
		lookup_by: 'slug',
		lookup_meta_key: '',
		object_type: 'auto',
		external_source: 'import',
		external_id_column: '',
		default_status: 'approved',
		columns: {},
	} );
	const [ updateExisting, setUpdateExisting ] = useState( true );
	const [ running, setRunning ] = useState( false );
	const [ progress, setProgress ] = useState( null );

	const setMap = ( patch ) => setMapping( ( m ) => ( { ...m, ...patch } ) );
	const setCol = ( key, val ) => setMapping( ( m ) => ( { ...m, columns: { ...m.columns, [ key ]: val } } ) );

	const onFile = ( e ) => {
		const file = e.target.files && e.target.files[ 0 ];
		if ( ! file ) {
			return;
		}
		const reader = new FileReader();
		reader.onload = () => {
			try {
				const text = String( reader.result );
				const isJson = file.name.endsWith( '.json' ) || /^\s*[[{]/.test( text );
				const parsed = isJson ? parseJson( text ) : parseCsv( text );
				setRows( parsed.rows );
				setColumns( parsed.columns );
				// Best-effort auto-map by common header names.
				const guess = {};
				parsed.columns.forEach( ( c ) => {
					const l = c.toLowerCase();
					if ( /rating|stars|score/.test( l ) ) { guess.rating = c; }
					else if ( /author|name|reviewer/.test( l ) ) { guess.author_name = c; }
					else if ( /email/.test( l ) ) { guess.author_email = c; }
					else if ( /title|subject/.test( l ) ) { guess.title = c; }
					else if ( /content|review|text|body|comment/.test( l ) ) { guess.content = c; }
					else if ( /date|time|created/.test( l ) ) { guess.created_at = c; }
				} );
				setMapping( ( m ) => ( { ...m, columns: guess } ) );
				notify( 'success', sprintf( __( 'Loaded %1$d rows, %2$d columns.', 'advery-reviews' ), parsed.rows.length, parsed.columns.length ) );
			} catch ( err ) {
				notify( 'error', __( 'Could not parse the file: ', 'advery-reviews' ) + err.message );
			}
		};
		reader.readAsText( file );
	};

	const colOptions = [ { label: __( '— none —', 'advery-reviews' ), value: '' }, ...columns.map( ( c ) => ( { label: c, value: c } ) ) ];

	const runImport = async () => {
		if ( ! rows.length || ! mapping.target_column ) {
			notify( 'error', __( 'Load a file and choose the target column first.', 'advery-reviews' ) );
			return;
		}
		setRunning( true );
		const totals = { imported: 0, updated: 0, skipped: 0 };
		try {
			for ( let i = 0; i < rows.length; i += 100 ) {
				const batch = rows.slice( i, i + 100 );
				const res = await api.importData( { rows: batch, mapping, update_existing: updateExisting } );
				totals.imported += res.imported;
				totals.updated += res.updated;
				totals.skipped += res.skipped;
				setProgress( { ...totals } );
			}
			notify( 'success', sprintf( __( 'Imported %1$d, updated %2$d, skipped %3$d.', 'advery-reviews' ), totals.imported, totals.updated, totals.skipped ) );
		} catch ( e ) {
			notify( 'error', e.message );
		} finally {
			setRunning( false );
			setProgress( null );
		}
	};

	return (
		<div className="advery-rv-mig-card">
			<div className="advery-rv-mig-card__head">
				<span className="advery-rv-mig-card__icon" aria-hidden="true">📄</span>
				<div>
					<h3 className="advery-rv-mig-card__title">{ __( 'Import from a file (CSV / JSON)', 'advery-reviews' ) }</h3>
					<p className="advery-rv-mig-card__desc">
						{ __( 'Import review data prepared elsewhere — a spreadsheet, another platform’s export, or a dataset collected outside WordPress. After choosing a file you’ll map its columns and set a unique key so re-imports update instead of duplicating.', 'advery-reviews' ) }
					</p>
				</div>
			</div>
			<div className="advery-rv-mig-card__body">
			<input type="file" accept=".csv,.json,text/csv,application/json" onChange={ onFile } />

			{ columns.length > 0 && (
				<>
					<p style={ { marginTop: 12 } }><strong>{ sprintf( __( '%1$d rows · %2$d columns', 'advery-reviews' ), rows.length, columns.length ) }</strong></p>

					<hr />
					<strong>{ __( 'Target (which post each review belongs to)', 'advery-reviews' ) }</strong>
					<SelectControl
						label={ __( 'Identify the target by', 'advery-reviews' ) }
						value={ mapping.target_mode }
						options={ [
							{ label: __( 'Post ID', 'advery-reviews' ), value: 'post_id' },
							{ label: __( 'Lookup (slug / title / meta)', 'advery-reviews' ), value: 'lookup' },
						] }
						onChange={ ( v ) => setMap( { target_mode: v } ) }
						__nextHasNoMarginBottom
					/>
					<SelectControl
						label={ __( 'Column holding that value', 'advery-reviews' ) }
						value={ mapping.target_column }
						options={ colOptions }
						onChange={ ( v ) => setMap( { target_column: v } ) }
						__nextHasNoMarginBottom
					/>
					{ mapping.target_mode === 'lookup' && (
						<>
							<SelectControl
								label={ __( 'Match by', 'advery-reviews' ) }
								value={ mapping.lookup_by }
								options={ [
									{ label: __( 'Slug', 'advery-reviews' ), value: 'slug' },
									{ label: __( 'Title', 'advery-reviews' ), value: 'title' },
									{ label: __( 'Custom field (meta)', 'advery-reviews' ), value: 'meta' },
								] }
								onChange={ ( v ) => setMap( { lookup_by: v } ) }
								__nextHasNoMarginBottom
							/>
							{ mapping.lookup_by === 'meta' && (
								<TextControl
									label={ __( 'Meta key', 'advery-reviews' ) }
									value={ mapping.lookup_meta_key }
									onChange={ ( v ) => setMap( { lookup_meta_key: v } ) }
									__nextHasNoMarginBottom
								/>
							) }
						</>
					) }
					<SelectControl
						label={ __( 'Treat targets as', 'advery-reviews' ) }
						value={ mapping.object_type }
						options={ [
							{ label: __( 'Auto (by post type)', 'advery-reviews' ), value: 'auto' },
							{ label: __( 'Posts', 'advery-reviews' ), value: 'post' },
							{ label: __( 'Products', 'advery-reviews' ), value: 'product' },
						] }
						onChange={ ( v ) => setMap( { object_type: v } ) }
						__nextHasNoMarginBottom
					/>

					<hr />
					<strong>{ __( 'Unique key (for de-duplication)', 'advery-reviews' ) }</strong>
					<TextControl
						label={ __( 'Source label', 'advery-reviews' ) }
						help={ __( 'e.g. google, trustpilot — stored with each imported review.', 'advery-reviews' ) }
						value={ mapping.external_source }
						onChange={ ( v ) => setMap( { external_source: v } ) }
						__nextHasNoMarginBottom
					/>
					<SelectControl
						label={ __( 'Column with the source review id', 'advery-reviews' ) }
						value={ mapping.external_id_column }
						options={ colOptions }
						onChange={ ( v ) => setMap( { external_id_column: v } ) }
						__nextHasNoMarginBottom
					/>

					<hr />
					<strong>{ __( 'Field mapping', 'advery-reviews' ) }</strong>
					{ [
						[ 'rating', __( 'Rating', 'advery-reviews' ) ],
						[ 'author_name', __( 'Author name', 'advery-reviews' ) ],
						[ 'author_email', __( 'Author email', 'advery-reviews' ) ],
						[ 'title', __( 'Title', 'advery-reviews' ) ],
						[ 'content', __( 'Review text', 'advery-reviews' ) ],
						[ 'created_at', __( 'Date', 'advery-reviews' ) ],
					].map( ( [ key, label ] ) => (
						<SelectControl
							key={ key }
							label={ label }
							value={ mapping.columns[ key ] || '' }
							options={ colOptions }
							onChange={ ( v ) => setCol( key, v ) }
							__nextHasNoMarginBottom
						/>
					) ) }
					<SelectControl
						label={ __( 'Default status', 'advery-reviews' ) }
						value={ mapping.default_status }
						options={ [
							{ label: __( 'Approved', 'advery-reviews' ), value: 'approved' },
							{ label: __( 'Pending', 'advery-reviews' ), value: 'pending' },
						] }
						onChange={ ( v ) => setMap( { default_status: v } ) }
						__nextHasNoMarginBottom
					/>
					<ToggleControl
						label={ __( 'Update reviews already imported (same source + id)', 'advery-reviews' ) }
						checked={ updateExisting }
						onChange={ setUpdateExisting }
						__nextHasNoMarginBottom
					/>

					{ ! mapping.external_id_column && (
						<Notice status="warning" isDismissible={ false }>
							{ __( 'Without a unique id column, re-imports can create duplicates.', 'advery-reviews' ) }
						</Notice>
					) }

					<div style={ { marginTop: 12 } }>
						<Button variant="primary" isBusy={ running } disabled={ running || ! mapping.target_column } onClick={ runImport }>
							{ __( 'Import rows', 'advery-reviews' ) }
						</Button>
						{ running && progress && (
							<span style={ { marginInlineStart: 10 } }>
								{ sprintf( __( 'imported %1$d, updated %2$d, skipped %3$d…', 'advery-reviews' ), progress.imported, progress.updated, progress.skipped ) }
							</span>
						) }
					</div>
				</>
			) }
			</div>
		</div>
	);
}
