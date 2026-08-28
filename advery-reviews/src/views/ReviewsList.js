import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	SearchControl,
	Spinner,
	CheckboxControl,
	Flex,
	FlexItem,
} from '@wordpress/components';
import { api } from '../api';

const STATUS_TABS = [
	{ key: '', label: __( 'All', 'advery-reviews' ) },
	{ key: 'pending', label: __( 'Pending', 'advery-reviews' ) },
	{ key: 'approved', label: __( 'Approved', 'advery-reviews' ) },
	{ key: 'spam', label: __( 'Spam', 'advery-reviews' ) },
	{ key: 'trash', label: __( 'Trash', 'advery-reviews' ) },
];

function stars( n ) {
	return n > 0 ? '★'.repeat( n ) + '☆'.repeat( 5 - n ) : '—';
}

export default function ReviewsList( { counts, setCounts, notify } ) {
	const [ status, setStatus ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( { items: [], total: 0 } );
	const [ loading, setLoading ] = useState( false );
	const [ selected, setSelected ] = useState( [] );
	const perPage = 20;

	const load = useCallback( async () => {
		setLoading( true );
		try {
			const res = await api.listReviews( { status, search, page, per_page: perPage } );
			setData( res );
			if ( res.counts ) {
				setCounts( res.counts );
			}
			setSelected( [] );
		} catch ( e ) {
			notify( 'error', e.message );
		} finally {
			setLoading( false );
		}
	}, [ status, search, page, setCounts, notify ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const act = async ( id, action ) => {
		try {
			if ( action === 'delete' ) {
				await api.remove( id );
			} else {
				await api.setStatus( id, action );
			}
			await load();
		} catch ( e ) {
			notify( 'error', e.message );
		}
	};

	const runBulk = async ( action ) => {
		if ( ! selected.length ) {
			return;
		}
		try {
			const res = await api.bulk( selected, action );
			notify( 'success', sprintf( __( '%d updated.', 'advery-reviews' ), res.done ) );
			await load();
		} catch ( e ) {
			notify( 'error', e.message );
		}
	};

	const toggle = ( id ) =>
		setSelected( ( prev ) =>
			prev.includes( id ) ? prev.filter( ( x ) => x !== id ) : [ ...prev, id ]
		);

	const totalPages = Math.max( 1, Math.ceil( data.total / perPage ) );

	return (
		<div className="advery-rv-list">
			<Flex className="advery-rv-list__filters" align="center" wrap>
				<FlexItem>
					<div className="advery-rv-tabs">
						{ STATUS_TABS.map( ( t ) => (
							<button
								key={ t.key }
								className={ 'advery-rv-tab' + ( status === t.key ? ' is-active' : '' ) }
								onClick={ () => {
									setStatus( t.key );
									setPage( 1 );
								} }
							>
								{ t.label }
								{ t.key && counts[ t.key ] != null && (
									<span className="advery-rv-tab__count">{ counts[ t.key ] }</span>
								) }
							</button>
						) ) }
					</div>
				</FlexItem>
				<FlexItem>
					<SearchControl
						value={ search }
						onChange={ ( v ) => {
							setSearch( v );
							setPage( 1 );
						} }
						placeholder={ __( 'Search reviews…', 'advery-reviews' ) }
						__nextHasNoMarginBottom
					/>
				</FlexItem>
			</Flex>

			{ selected.length > 0 && (
				<div className="advery-rv-bulk">
					<span>{ sprintf( __( '%d selected:', 'advery-reviews' ), selected.length ) }</span>
					<Button variant="secondary" onClick={ () => runBulk( 'approved' ) }>{ __( 'Approve', 'advery-reviews' ) }</Button>
					<Button variant="secondary" onClick={ () => runBulk( 'pending' ) }>{ __( 'Pending', 'advery-reviews' ) }</Button>
					<Button variant="secondary" onClick={ () => runBulk( 'spam' ) }>{ __( 'Spam', 'advery-reviews' ) }</Button>
					<Button isDestructive variant="secondary" onClick={ () => runBulk( 'delete' ) }>{ __( 'Delete', 'advery-reviews' ) }</Button>
				</div>
			) }

			{ loading ? (
				<div className="advery-rv-loading"><Spinner /></div>
			) : (
				<table className="advery-rv-table widefat striped">
					<thead>
						<tr>
							<th className="check-column"></th>
							<th>{ __( 'Rating', 'advery-reviews' ) }</th>
							<th>{ __( 'Review', 'advery-reviews' ) }</th>
							<th>{ __( 'Item', 'advery-reviews' ) }</th>
							<th>{ __( 'Status', 'advery-reviews' ) }</th>
							<th>{ __( 'Actions', 'advery-reviews' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ data.items.length === 0 && (
							<tr><td colSpan={ 6 }>{ __( 'No reviews found.', 'advery-reviews' ) }</td></tr>
						) }
						{ data.items.map( ( r ) => (
							<tr key={ r.id }>
								<td className="check-column">
									<CheckboxControl
										checked={ selected.includes( r.id ) }
										onChange={ () => toggle( r.id ) }
										__nextHasNoMarginBottom
									/>
								</td>
								<td className="advery-rv-stars">{ stars( r.rating ) }</td>
								<td>
									<strong>{ r.author_name }</strong>
									{ r.title && <div className="advery-rv-rtitle">{ r.title }</div> }
									<div className="advery-rv-excerpt">{ r.content.replace( /<[^>]+>/g, '' ).slice( 0, 140 ) }</div>
									<div className="advery-rv-meta">{ r.author_email } · { r.created_at }</div>
								</td>
								<td>
									{ r.link ? <a href={ r.link } target="_blank" rel="noreferrer">{ r.label }</a> : r.label }
									<div className="advery-rv-meta">{ r.object_type }</div>
								</td>
								<td><span className={ 'advery-rv-badge is-' + r.status }>{ r.status }</span></td>
								<td className="advery-rv-actions">
									{ r.status !== 'approved' && <Button variant="link" onClick={ () => act( r.id, 'approved' ) }>{ __( 'Approve', 'advery-reviews' ) }</Button> }
									{ r.status !== 'pending' && <Button variant="link" onClick={ () => act( r.id, 'pending' ) }>{ __( 'Pending', 'advery-reviews' ) }</Button> }
									{ r.status !== 'spam' && <Button variant="link" onClick={ () => act( r.id, 'spam' ) }>{ __( 'Spam', 'advery-reviews' ) }</Button> }
									{ r.status !== 'trash' && <Button variant="link" onClick={ () => act( r.id, 'trash' ) }>{ __( 'Trash', 'advery-reviews' ) }</Button> }
									<Button variant="link" isDestructive onClick={ () => act( r.id, 'delete' ) }>{ __( 'Delete', 'advery-reviews' ) }</Button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			{ totalPages > 1 && (
				<div className="advery-rv-pager">
					<Button variant="secondary" disabled={ page <= 1 } onClick={ () => setPage( page - 1 ) }>{ __( 'Previous', 'advery-reviews' ) }</Button>
					<span>{ sprintf( __( 'Page %1$d of %2$d', 'advery-reviews' ), page, totalPages ) }</span>
					<Button variant="secondary" disabled={ page >= totalPages } onClick={ () => setPage( page + 1 ) }>{ __( 'Next', 'advery-reviews' ) }</Button>
				</div>
			) }
		</div>
	);
}
