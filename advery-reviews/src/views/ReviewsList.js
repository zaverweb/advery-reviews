import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	SearchControl,
	Spinner,
	CheckboxControl,
	TextareaControl,
} from '@wordpress/components';
import { api } from '../api';
import { ContentTypeSelect, ObjectSearch, parseContentType } from './ItemFilters';

const STATUS_TABS = [
	{ key: '', label: __( 'All', 'advery-reviews' ) },
	{ key: 'pending', label: __( 'Pending', 'advery-reviews' ) },
	{ key: 'approved', label: __( 'Approved', 'advery-reviews' ) },
	{ key: 'spam', label: __( 'Spam', 'advery-reviews' ) },
	{ key: 'trash', label: __( 'Trash', 'advery-reviews' ) },
];

function stars( n ) {
	n = Math.max( 0, Math.min( 5, parseInt( n, 10 ) || 0 ) );
	return '★'.repeat( n ) + '☆'.repeat( 5 - n );
}

export default function ReviewsList( { boot, counts, setCounts, notify } ) {
	const [ status, setStatus ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ contentType, setContentType ] = useState( '' );
	const [ objectSel, setObjectSel ] = useState( null );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( { items: [], total: 0 } );
	const [ loading, setLoading ] = useState( false );
	const [ selected, setSelected ] = useState( [] );
	const [ replyFor, setReplyFor ] = useState( 0 );
	const [ replyText, setReplyText ] = useState( '' );
	const [ aiBusy, setAiBusy ] = useState( '' );
	const perPage = 20;

	const load = useCallback( async () => {
		setLoading( true );
		try {
			const { post_type: postType, taxonomy } = parseContentType( contentType );
			const res = await api.listReviews( {
				status,
				search,
				post_type: postType,
				taxonomy,
				object_type: objectSel ? objectSel.object_type : '',
				object_id: objectSel ? objectSel.object_id : 0,
				page,
				per_page: perPage,
			} );
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
	}, [ status, search, contentType, objectSel, page, setCounts, notify ] );

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

	const openReply = ( r ) => {
		setReplyFor( r.id );
		setReplyText( ( r.meta && r.meta.reply ) || '' );
	};
	const draftReply = async ( r ) => {
		setAiBusy( 'draft' );
		try {
			const res = await api.ai( 'reply', { review_id: r.id } );
			setReplyText( res.text || '' );
		} catch ( e ) {
			notify( 'error', e.message );
		} finally {
			setAiBusy( '' );
		}
	};
	const saveReply = async ( r ) => {
		try {
			await api.saveReply( r.id, replyText );
			notify( 'success', __( 'Reply saved.', 'advery-reviews' ) );
			setReplyFor( 0 );
			await load();
		} catch ( e ) {
			notify( 'error', e.message );
		}
	};
	const aiModerate = async ( r ) => {
		setAiBusy( 'mod' + r.id );
		try {
			const res = await api.ai( 'moderate', { review_id: r.id } );
			notify( 'success', __( 'AI verdict: ', 'advery-reviews' ) + ( res.verdict || '' ) );
		} catch ( e ) {
			notify( 'error', e.message );
		} finally {
			setAiBusy( '' );
		}
	};

	const totalPages = Math.max( 1, Math.ceil( data.total / perPage ) );

	return (
		<div className="advery-rv-list">
			<div className="advery-rv-toolbar">
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
				<div className="advery-rv-filters">
					<ContentTypeSelect
						boot={ boot }
						value={ contentType }
						onChange={ ( v ) => {
							setContentType( v );
							setPage( 1 );
						} }
					/>
					<ObjectSearch
						onSelect={ ( it ) => {
							setObjectSel( it );
							setPage( 1 );
						} }
					/>
					<SearchControl
						value={ search }
						onChange={ ( v ) => {
							setSearch( v );
							setPage( 1 );
						} }
						placeholder={ __( 'Search review text…', 'advery-reviews' ) }
						__nextHasNoMarginBottom
					/>
				</div>
			</div>

			{ selected.length > 0 && (
				<div className="advery-rv-bulk">
					<span>{ sprintf( __( '%d selected', 'advery-reviews' ), selected.length ) }</span>
					<Button variant="secondary" onClick={ () => runBulk( 'approved' ) }>{ __( 'Approve', 'advery-reviews' ) }</Button>
					<Button variant="secondary" onClick={ () => runBulk( 'pending' ) }>{ __( 'Pending', 'advery-reviews' ) }</Button>
					<Button variant="secondary" onClick={ () => runBulk( 'spam' ) }>{ __( 'Spam', 'advery-reviews' ) }</Button>
					<Button isDestructive variant="secondary" onClick={ () => runBulk( 'delete' ) }>{ __( 'Delete', 'advery-reviews' ) }</Button>
				</div>
			) }

			{ loading ? (
				<div className="advery-rv-loading"><Spinner /></div>
			) : data.items.length === 0 ? (
				<div className="advery-rv-empty">{ __( 'No reviews found.', 'advery-reviews' ) }</div>
			) : (
				<div className="advery-rv-cards">
					{ data.items.map( ( r ) => (
						<article key={ r.id } className={ 'advery-rv-card is-' + r.status }>
							<div className="advery-rv-card__select">
								<CheckboxControl
									checked={ selected.includes( r.id ) }
									onChange={ () => toggle( r.id ) }
									__nextHasNoMarginBottom
								/>
							</div>
							<div className="advery-rv-card__avatar" aria-hidden="true">
								{ ( r.author_name || '?' ).trim().charAt( 0 ).toUpperCase() }
							</div>
							<div className="advery-rv-card__body">
								<div className="advery-rv-card__head">
									<span className="advery-rv-card__author">{ r.author_name }</span>
									<span className="advery-rv-card__stars">{ stars( r.rating ) }</span>
									<span className={ 'advery-rv-badge is-' + r.status }>{ r.status }</span>
									{ r.spam_score > 0 && (
										<span className="advery-rv-spam" title={ r.meta && r.meta.spam_reasons ? r.meta.spam_reasons.join( ', ' ) : '' }>
											{ __( 'spam', 'advery-reviews' ) } { r.spam_score }
										</span>
									) }
								</div>
								{ r.title && <div className="advery-rv-card__title">{ r.title }</div> }
								<div className="advery-rv-card__content">{ r.content.replace( /<[^>]+>/g, '' ) }</div>
								<div className="advery-rv-card__meta">
									{ r.author_email } · { r.created_at } ·{ ' ' }
									{ r.link ? <a href={ r.link } target="_blank" rel="noreferrer">{ r.label }</a> : r.label }
									<span className="advery-rv-card__type"> ({ r.object_type })</span>
								</div>

								{ replyFor !== r.id && r.meta && r.meta.reply && (
									<div className="advery-rv-existing-reply">
										<strong>{ __( 'Your reply:', 'advery-reviews' ) }</strong> { r.meta.reply }
									</div>
								) }

								{ replyFor === r.id && (
									<div className="advery-rv-reply-editor">
										<TextareaControl
											label={ __( 'Owner reply', 'advery-reviews' ) }
											rows={ 3 }
											value={ replyText }
											onChange={ setReplyText }
											__nextHasNoMarginBottom
										/>
										<div className="advery-rv-reply-actions">
											<Button variant="secondary" isBusy={ aiBusy === 'draft' } onClick={ () => draftReply( r ) }>{ __( 'Draft with AI', 'advery-reviews' ) }</Button>
											<Button variant="primary" onClick={ () => saveReply( r ) }>{ __( 'Save reply', 'advery-reviews' ) }</Button>
											<Button variant="tertiary" onClick={ () => setReplyFor( 0 ) }>{ __( 'Cancel', 'advery-reviews' ) }</Button>
										</div>
									</div>
								) }

								<div className="advery-rv-card__actions">
									{ r.status !== 'approved' && <Button variant="link" onClick={ () => act( r.id, 'approved' ) }>{ __( 'Approve', 'advery-reviews' ) }</Button> }
									{ r.status !== 'pending' && <Button variant="link" onClick={ () => act( r.id, 'pending' ) }>{ __( 'Pending', 'advery-reviews' ) }</Button> }
									{ r.status !== 'spam' && <Button variant="link" onClick={ () => act( r.id, 'spam' ) }>{ __( 'Spam', 'advery-reviews' ) }</Button> }
									{ r.status !== 'trash' && <Button variant="link" onClick={ () => act( r.id, 'trash' ) }>{ __( 'Trash', 'advery-reviews' ) }</Button> }
									<Button variant="link" isDestructive onClick={ () => act( r.id, 'delete' ) }>{ __( 'Delete', 'advery-reviews' ) }</Button>
									<span className="advery-rv-card__sep" />
									<Button variant="link" onClick={ () => openReply( r ) }>{ ( r.meta && r.meta.reply ) ? __( 'Edit reply', 'advery-reviews' ) : __( 'Reply', 'advery-reviews' ) }</Button>
									<Button variant="link" isBusy={ aiBusy === 'mod' + r.id } onClick={ () => aiModerate( r ) }>{ __( 'AI check', 'advery-reviews' ) }</Button>
								</div>
							</div>
						</article>
					) ) }
				</div>
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
