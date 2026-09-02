import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { SelectControl, TextControl, Button, Spinner, Notice } from '@wordpress/components';
import { api } from '../api';

const cfg = window.AdveryReviewsConfig || {};

const SOURCE_LABELS = {
	review: __( 'Review form', 'advery-reviews' ),
	comment: __( 'Native comment', 'advery-reviews' ),
};
const OUTCOME_LABELS = {
	reject: __( 'Rejected', 'advery-reviews' ),
	spam: __( 'Spam', 'advery-reviews' ),
	hold: __( 'Held', 'advery-reviews' ),
};

function fmtDate( s ) {
	if ( ! s ) {
		return '';
	}
	// created_at is 'YYYY-MM-DD HH:MM:SS'; render in the site's locale.
	const d = new Date( s.replace( ' ', 'T' ) );
	if ( isNaN( d.getTime() ) ) {
		return s;
	}
	try {
		return d.toLocaleString();
	} catch ( e ) {
		return s;
	}
}

export default function SpamLogPanel( { notify } ) {
	const [ source, setSource ] = useState( '' );
	const [ outcome, setOutcome ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ busy, setBusy ] = useState( false );

	const load = useCallback( async () => {
		setLoading( true );
		try {
			const res = await api.spamLog( { source, outcome, search, page } );
			setData( res );
		} catch ( e ) {
			notify( 'error', e.message );
		} finally {
			setLoading( false );
		}
	}, [ source, outcome, search, page, notify ] );

	useEffect( () => {
		load();
	}, [ load ] );

	// Reset to page 1 whenever a filter changes.
	useEffect( () => {
		setPage( 1 );
	}, [ source, outcome, search ] );

	const clearLog = useCallback( async () => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( __( 'Permanently delete every row in the spam log? This cannot be undone.', 'advery-reviews' ) ) ) {
			return;
		}
		setBusy( true );
		try {
			await api.spamLogClear();
			notify( 'success', __( 'Spam log cleared.', 'advery-reviews' ) );
			setPage( 1 );
			load();
		} catch ( e ) {
			notify( 'error', e.message );
		} finally {
			setBusy( false );
		}
	}, [ notify, load ] );

	const items = ( data && data.items ) || [];
	const total = ( data && data.total ) || 0;
	const perPage = ( data && data.per_page ) || 20;
	const totalPages = Math.max( 1, Math.ceil( total / perPage ) );
	const enabled = data ? data.enabled : true;

	return (
		<div className="advery-rv-panel advery-rv-spamlog">
			{ ! enabled && (
				<Notice status="warning" isDismissible={ false }>
					{ __( 'The spam log is turned off, so nothing new is being recorded. Turn on “Keep a spam log” under Settings → Anti-spam to start logging blocked submissions.', 'advery-reviews' ) }
					{ cfg.settingsUrl && (
						<>
							{ ' ' }
							<a href={ cfg.settingsUrl }>{ __( 'Open settings', 'advery-reviews' ) }</a>
						</>
					) }
				</Notice>
			) }

			<div className="advery-rv-spamlog__bar">
				<SelectControl
					label={ __( 'Source', 'advery-reviews' ) }
					value={ source }
					options={ [
						{ label: __( 'All sources', 'advery-reviews' ), value: '' },
						{ label: SOURCE_LABELS.review, value: 'review' },
						{ label: SOURCE_LABELS.comment, value: 'comment' },
					] }
					onChange={ setSource }
					__nextHasNoMarginBottom
				/>
				<SelectControl
					label={ __( 'Result', 'advery-reviews' ) }
					value={ outcome }
					options={ [
						{ label: __( 'All results', 'advery-reviews' ), value: '' },
						{ label: OUTCOME_LABELS.reject, value: 'reject' },
						{ label: OUTCOME_LABELS.spam, value: 'spam' },
						{ label: OUTCOME_LABELS.hold, value: 'hold' },
					] }
					onChange={ setOutcome }
					__nextHasNoMarginBottom
				/>
				<TextControl
					label={ __( 'Search (text, IP, email, reason)', 'advery-reviews' ) }
					value={ search }
					onChange={ setSearch }
					__nextHasNoMarginBottom
				/>
				<div className="advery-rv-spamlog__bar-actions">
					<Button variant="secondary" onClick={ load } disabled={ loading }>
						{ __( 'Refresh', 'advery-reviews' ) }
					</Button>
					<Button variant="secondary" isDestructive onClick={ clearLog } disabled={ busy || total === 0 }>
						{ __( 'Clear log', 'advery-reviews' ) }
					</Button>
				</div>
			</div>

			{ loading ? (
				<div className="advery-rv-loading"><Spinner /></div>
			) : items.length === 0 ? (
				<p className="advery-rv-hint">
					{ enabled
						? __( 'Nothing logged yet. Blocked, held, or spam submissions will appear here.', 'advery-reviews' )
						: __( 'No entries.', 'advery-reviews' ) }
				</p>
			) : (
				<>
					<p className="advery-rv-hint">
						{ sprintf(
							/* translators: %d: number of log rows */
							__( '%d entries', 'advery-reviews' ),
							total
						) }
					</p>
					<div className="advery-rv-tablewrap">
						<table className="advery-rv-table advery-rv-spamlog__table">
							<thead>
								<tr>
									<th>{ __( 'When', 'advery-reviews' ) }</th>
									<th>{ __( 'Source', 'advery-reviews' ) }</th>
									<th>{ __( 'Result', 'advery-reviews' ) }</th>
									<th>{ __( 'Target', 'advery-reviews' ) }</th>
									<th>{ __( 'IP', 'advery-reviews' ) }</th>
									<th>{ __( 'Reason', 'advery-reviews' ) }</th>
									<th>{ __( 'Content', 'advery-reviews' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ items.map( ( r ) => (
									<tr key={ r.id }>
										<td className="advery-rv-spamlog__when">{ fmtDate( r.created_at ) }</td>
										<td>{ SOURCE_LABELS[ r.source ] || r.source }</td>
										<td>
											<span className={ 'advery-rv-pill is-' + r.outcome }>
												{ OUTCOME_LABELS[ r.outcome ] || r.outcome }
											</span>
										</td>
										<td>
											{ r.label ? (
												r.link ? (
													<a href={ r.link } target="_blank" rel="noreferrer">{ r.label }</a>
												) : (
													r.label
												)
											) : (
												<span className="advery-rv-muted">—</span>
											) }
										</td>
										<td className="advery-rv-spamlog__ip">{ r.author_ip || '—' }</td>
										<td className="advery-rv-spamlog__reason">{ r.reason || '—' }</td>
										<td className="advery-rv-spamlog__content" title={ r.content }>{ r.content }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>

					{ totalPages > 1 && (
						<div className="advery-rv-pager">
							<Button variant="secondary" disabled={ page <= 1 } onClick={ () => setPage( ( p ) => Math.max( 1, p - 1 ) ) }>
								{ __( 'Previous', 'advery-reviews' ) }
							</Button>
							<span className="advery-rv-pager__info">
								{ sprintf(
									/* translators: 1: current page, 2: total pages */
									__( 'Page %1$d of %2$d', 'advery-reviews' ),
									page,
									totalPages
								) }
							</span>
							<Button variant="secondary" disabled={ page >= totalPages } onClick={ () => setPage( ( p ) => Math.min( totalPages, p + 1 ) ) }>
								{ __( 'Next', 'advery-reviews' ) }
							</Button>
						</div>
					) }
				</>
			) }
		</div>
	);
}
