import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { SelectControl, TextControl, Button, Spinner, Notice } from '@wordpress/components';
import { api } from '../api';

const cfg = window.ZaverWebReviewsConfig || {};

const SOURCE_LABELS = {
	review: __( 'Review form', 'zaverweb-reviews' ),
	comment: __( 'Native comment', 'zaverweb-reviews' ),
};
const OUTCOME_LABELS = {
	reject: __( 'Rejected', 'zaverweb-reviews' ),
	spam: __( 'Spam', 'zaverweb-reviews' ),
	hold: __( 'Held', 'zaverweb-reviews' ),
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
		if ( ! window.confirm( __( 'Permanently delete every row in the spam log? This cannot be undone.', 'zaverweb-reviews' ) ) ) {
			return;
		}
		setBusy( true );
		try {
			await api.spamLogClear();
			notify( 'success', __( 'Spam log cleared.', 'zaverweb-reviews' ) );
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
		<div className="zaverweb-rv-panel zaverweb-rv-spamlog">
			{ ! enabled && (
				<Notice status="warning" isDismissible={ false }>
					{ __( 'The spam log is turned off, so nothing new is being recorded. Turn on “Keep a spam log” under Settings → Anti-spam to start logging blocked submissions.', 'zaverweb-reviews' ) }
					{ cfg.settingsUrl && (
						<>
							{ ' ' }
							<a href={ cfg.settingsUrl }>{ __( 'Open settings', 'zaverweb-reviews' ) }</a>
						</>
					) }
				</Notice>
			) }

			<div className="zaverweb-rv-spamlog__bar">
				<SelectControl
					label={ __( 'Source', 'zaverweb-reviews' ) }
					value={ source }
					options={ [
						{ label: __( 'All sources', 'zaverweb-reviews' ), value: '' },
						{ label: SOURCE_LABELS.review, value: 'review' },
						{ label: SOURCE_LABELS.comment, value: 'comment' },
					] }
					onChange={ setSource }
					__nextHasNoMarginBottom
				/>
				<SelectControl
					label={ __( 'Result', 'zaverweb-reviews' ) }
					value={ outcome }
					options={ [
						{ label: __( 'All results', 'zaverweb-reviews' ), value: '' },
						{ label: OUTCOME_LABELS.reject, value: 'reject' },
						{ label: OUTCOME_LABELS.spam, value: 'spam' },
						{ label: OUTCOME_LABELS.hold, value: 'hold' },
					] }
					onChange={ setOutcome }
					__nextHasNoMarginBottom
				/>
				<TextControl
					label={ __( 'Search (text, IP, email, reason)', 'zaverweb-reviews' ) }
					value={ search }
					onChange={ setSearch }
					__nextHasNoMarginBottom
				/>
				<div className="zaverweb-rv-spamlog__bar-actions">
					<Button variant="secondary" onClick={ load } disabled={ loading }>
						{ __( 'Refresh', 'zaverweb-reviews' ) }
					</Button>
					<Button variant="secondary" isDestructive onClick={ clearLog } disabled={ busy || total === 0 }>
						{ __( 'Clear log', 'zaverweb-reviews' ) }
					</Button>
				</div>
			</div>

			{ loading ? (
				<div className="zaverweb-rv-loading"><Spinner /></div>
			) : items.length === 0 ? (
				<p className="zaverweb-rv-hint">
					{ enabled
						? __( 'Nothing logged yet. Blocked, held, or spam submissions will appear here.', 'zaverweb-reviews' )
						: __( 'No entries.', 'zaverweb-reviews' ) }
				</p>
			) : (
				<>
					<p className="zaverweb-rv-hint">
						{ sprintf(
							/* translators: %d: number of log rows */
							__( '%d entries', 'zaverweb-reviews' ),
							total
						) }
					</p>
					<div className="zaverweb-rv-tablewrap">
						<table className="zaverweb-rv-table zaverweb-rv-spamlog__table">
							<thead>
								<tr>
									<th>{ __( 'When', 'zaverweb-reviews' ) }</th>
									<th>{ __( 'Source', 'zaverweb-reviews' ) }</th>
									<th>{ __( 'Result', 'zaverweb-reviews' ) }</th>
									<th>{ __( 'Target', 'zaverweb-reviews' ) }</th>
									<th>{ __( 'IP', 'zaverweb-reviews' ) }</th>
									<th>{ __( 'Reason', 'zaverweb-reviews' ) }</th>
									<th>{ __( 'Content', 'zaverweb-reviews' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ items.map( ( r ) => (
									<tr key={ r.id }>
										<td className="zaverweb-rv-spamlog__when">{ fmtDate( r.created_at ) }</td>
										<td>{ SOURCE_LABELS[ r.source ] || r.source }</td>
										<td>
											<span className={ 'zaverweb-rv-pill is-' + r.outcome }>
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
												<span className="zaverweb-rv-muted">—</span>
											) }
										</td>
										<td className="zaverweb-rv-spamlog__ip">{ r.author_ip || '—' }</td>
										<td className="zaverweb-rv-spamlog__reason">{ r.reason || '—' }</td>
										<td className="zaverweb-rv-spamlog__content" title={ r.content }>{ r.content }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>

					{ totalPages > 1 && (
						<div className="zaverweb-rv-pager">
							<Button variant="secondary" disabled={ page <= 1 } onClick={ () => setPage( ( p ) => Math.max( 1, p - 1 ) ) }>
								{ __( 'Previous', 'zaverweb-reviews' ) }
							</Button>
							<span className="zaverweb-rv-pager__info">
								{ sprintf(
									/* translators: 1: current page, 2: total pages */
									__( 'Page %1$d of %2$d', 'zaverweb-reviews' ),
									page,
									totalPages
								) }
							</span>
							<Button variant="secondary" disabled={ page >= totalPages } onClick={ () => setPage( ( p ) => Math.min( totalPages, p + 1 ) ) }>
								{ __( 'Next', 'zaverweb-reviews' ) }
							</Button>
						</div>
					) }
				</>
			) }
		</div>
	);
}
