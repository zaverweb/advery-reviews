import { useState, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { ComboboxControl } from '@wordpress/components';
import { api } from '../api';

/**
 * Parse the grouped content-type value ("pt:doctor" / "tax:scpecialist") into
 * the REST filter params. Empty string → no type filter.
 *
 * @param {string} value
 * @return {{post_type:string, taxonomy:string}}
 */
export function parseContentType( value ) {
	if ( ! value ) {
		return { post_type: '', taxonomy: '' };
	}
	if ( value.indexOf( 'pt:' ) === 0 ) {
		return { post_type: value.slice( 3 ), taxonomy: '' };
	}
	if ( value.indexOf( 'tax:' ) === 0 ) {
		return { post_type: '', taxonomy: value.slice( 4 ) };
	}
	return { post_type: '', taxonomy: '' };
}

/**
 * A native grouped <select> of every registered public post type and taxonomy
 * (so custom post types / custom taxonomies are all selectable, not just the
 * three coarse buckets). Values are "pt:<slug>" and "tax:<slug>".
 */
export function ContentTypeSelect( { boot, value, onChange } ) {
	const postTypes = ( boot && boot.postTypes ) || [];
	const taxonomies = ( boot && boot.taxonomies ) || [];
	return (
		<label className="advery-rv-itemfilter">
			<span className="advery-rv-itemfilter__label">{ __( 'Item type', 'advery-reviews' ) }</span>
			<select
				className="advery-rv-itemfilter__select"
				value={ value }
				onChange={ ( e ) => onChange( e.target.value ) }
			>
				<option value="">{ __( 'All item types', 'advery-reviews' ) }</option>
				<optgroup label={ __( 'Post types', 'advery-reviews' ) }>
					{ postTypes.map( ( pt ) => (
						<option key={ 'pt:' + pt.slug } value={ 'pt:' + pt.slug }>
							{ pt.label }
						</option>
					) ) }
				</optgroup>
				<optgroup label={ __( 'Taxonomies', 'advery-reviews' ) }>
					{ taxonomies.map( ( tx ) => (
						<option key={ 'tax:' + tx.slug } value={ 'tax:' + tx.slug }>
							{ tx.label }
						</option>
					) ) }
				</optgroup>
			</select>
		</label>
	);
}

/**
 * Autocomplete that finds one specific reviewed item (any post/product/term)
 * and reports it as { object_type, object_id, label } — or null when cleared.
 * Lets an operator filter the list down to reviews of a single page/product/term.
 */
export function ObjectSearch( { onSelect } ) {
	const [ value, setValue ] = useState( null );
	const [ options, setOptions ] = useState( [] );
	const mapRef = useRef( {} );
	const tRef = useRef( null );

	const onFilter = ( input ) => {
		window.clearTimeout( tRef.current );
		if ( ! input || input.length < 2 ) {
			setOptions( [] );
			return;
		}
		tRef.current = window.setTimeout( async () => {
			try {
				const res = await api.searchObjects( input );
				const map = {};
				const opts = ( res.items || [] ).map( ( it ) => {
					const key = it.object_type + ':' + it.object_id;
					map[ key ] = it;
					return { value: key, label: it.label + ' — ' + it.sub };
				} );
				mapRef.current = map;
				setOptions( opts );
			} catch ( e ) {
				setOptions( [] );
			}
		}, 250 );
	};

	return (
		<div className="advery-rv-objsearch">
			<ComboboxControl
				label={ __( 'Filter by a specific item', 'advery-reviews' ) }
				value={ value }
				options={ options }
				onFilterValueChange={ onFilter }
				onChange={ ( v ) => {
					setValue( v );
					const it = v ? mapRef.current[ v ] : null;
					onSelect( it || null );
				} }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
		</div>
	);
}
