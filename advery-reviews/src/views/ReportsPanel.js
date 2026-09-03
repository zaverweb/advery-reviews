import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { SelectControl, Spinner, Button } from '@wordpress/components';
import { api } from '../api';
import { ContentTypeSelect, parseContentType } from './ItemFilters';

const RANGES = [
	{ label: __( 'Last 30 days', 'zaverweb-reviews' ), value: '30' },
	{ label: __( 'Last 90 days', 'zaverweb-reviews' ), value: '90' },
	{ label: __( 'Last 12 months', 'zaverweb-reviews' ), value: '365' },
	{ label: __( 'All time', 'zaverweb-reviews' ), value: '0' },
];

const TYPE_LABELS = {
	post: __( 'Post', 'zaverweb-reviews' ),
	product: __( 'Product', 'zaverweb-reviews' ),
	term: __( 'Category', 'zaverweb-reviews' ),
};

function typeLabel( t ) {
	return TYPE_LABELS[ t ] || t;
}

function stars( n ) {
	const v = Math.round( ( parseFloat( n ) || 0 ) * 2 ) / 2;
	const full = Math.floor( v );
	const half = v - full >= 0.5;
	return '★'.repeat( full ) + ( half ? '½' : '' );
}

function monthLabel( ym ) {
	// ym is 'YYYY-MM'. Render as a short "Mon 'YY".
	const [ y, m ] = ( ym || '' ).split( '-' );
	const names = [ '', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' ];
	const mi = parseInt( m, 10 );
	return ( names[ mi ] || m ) + " '" + ( y || '' ).slice( 2 );
}

export default function ReportsPanel( { boot, notify } ) {
	const [ range, setRange ] = useState( '0' );
	const [ contentType, setContentType ] = useState( '' );
	const [ data, setData ] = useState( null );
	const [ loading, setLoading ] = useState( true );

	const load = useCallback( async () => {
		setLoading( true );
		try {
			const { post_type: postType, taxonomy } = parseContentType( contentType );
			const res = await api.reports( { days: range, post_type: postType, taxonomy, limit: 10 } );
			setData( res );
		} catch ( e ) {
			notify( 'error', e.message );
		} finally {
			setLoading( false );
		}
	}, [ range, contentType, notify ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const summary = data?.summary || {};
	const top = data?.top || [];
	const byType = data?.byType || [];
	const ratings = data?.ratings || {};
	const monthly = data?.monthly || [];

	const topMax = top.reduce( ( m, r ) => Math.max( m, r.total ), 0 ) || 1;
	const ratingMax = Object.values( ratings ).reduce( ( m, n ) => Math.max( m, n ), 0 ) || 1;
	const ratingTotal = Object.values( ratings ).reduce( ( s, n ) => s + n, 0 );
	const monthMax = monthly.reduce( ( m, r ) => Math.max( m, r.total ), 0 ) || 1;
	const typeMax = byType.reduce( ( m, r ) => Math.max( m, r.total ), 0 ) || 1;

	const tiles = [
		{ label: __( 'Total reviews', 'zaverweb-reviews' ), value: summary.total || 0, tone: 'blue' },
		{ label: __( 'Approved', 'zaverweb-reviews' ), value: summary.approved || 0, tone: 'green' },
		{ label: __( 'Pending', 'zaverweb-reviews' ), value: summary.pending || 0, tone: 'amber' },
		{ label: __( 'Spam', 'zaverweb-reviews' ), value: summary.spam || 0, tone: 'red' },
		{ label: __( 'Items reviewed', 'zaverweb-reviews' ), value: summary.objects || 0, tone: 'violet' },
		{
			label: __( 'Average rating', 'zaverweb-reviews' ),
			value: ( summary.avg_rating || 0 ).toFixed( 2 ),
			tone: 'gold',
			sub: stars( summary.avg_rating || 0 ),
		},
	];

	return (
		<div className="zaverweb-rv-reports">
			<div className="zaverweb-rv-rep-toolbar">
				<SelectControl
					label={ __( 'Time range', 'zaverweb-reviews' ) }
					value={ range }
					options={ RANGES }
					onChange={ setRange }
					__nextHasNoMarginBottom
				/>
				<ContentTypeSelect boot={ boot } value={ contentType } onChange={ setContentType } />
				<div className="zaverweb-rv-rep-toolbar__spacer" />
				<Button variant="secondary" onClick={ load } disabled={ loading }>
					{ __( 'Refresh', 'zaverweb-reviews' ) }
				</Button>
			</div>

			{ loading || ! data ? (
				<div className="zaverweb-rv-loading"><Spinner /></div>
			) : (
				<>
					<div className="zaverweb-rv-rep-tiles">
						{ tiles.map( ( t ) => (
							<div key={ t.label } className={ 'zaverweb-rv-rep-tile is-' + t.tone }>
								<span className="zaverweb-rv-rep-tile__value">{ t.value }</span>
								<span className="zaverweb-rv-rep-tile__label">{ t.label }</span>
								{ t.sub && <span className="zaverweb-rv-rep-tile__sub">{ t.sub }</span> }
							</div>
						) ) }
					</div>

					<section className="zaverweb-rv-rep-card">
						<h3 className="zaverweb-rv-rep-card__title">
							{ __( 'Most-reviewed items', 'zaverweb-reviews' ) }
						</h3>
						<p className="zaverweb-rv-rep-card__hint">
							{ __( 'Which pages, products and categories collected the most reviews in this range.', 'zaverweb-reviews' ) }
						</p>
						{ top.length === 0 ? (
							<div className="zaverweb-rv-empty">{ __( 'No reviews in this range yet.', 'zaverweb-reviews' ) }</div>
						) : (
							<ol className="zaverweb-rv-rep-rank">
								{ top.map( ( r, i ) => (
									<li key={ r.object_type + ':' + r.object_id } className="zaverweb-rv-rep-rankrow">
										<span className="zaverweb-rv-rep-rankrow__num">{ i + 1 }</span>
										<div className="zaverweb-rv-rep-rankrow__main">
											<div className="zaverweb-rv-rep-rankrow__label">
												{ r.link ? (
													<a href={ r.link } target="_blank" rel="noreferrer">{ r.label }</a>
												) : (
													r.label
												) }
												<span className="zaverweb-rv-rep-chip">{ typeLabel( r.object_type ) }</span>
											</div>
											<div className="zaverweb-rv-rep-bar">
												<span
													className="zaverweb-rv-rep-bar__fill is-approved"
													style={ { width: ( ( r.approved / topMax ) * 100 ) + '%' } }
												/>
												<span
													className="zaverweb-rv-rep-bar__fill is-rest"
													style={ {
														width: ( ( ( r.total - r.approved ) / topMax ) * 100 ) + '%',
													} }
												/>
											</div>
											<div className="zaverweb-rv-rep-rankrow__meta">
												{ sprintf(
													/* translators: 1: total reviews, 2: approved count */
													__( '%1$d reviews · %2$d approved', 'zaverweb-reviews' ),
													r.total,
													r.approved
												) }
												{ r.avg_rating > 0 && (
													<span className="zaverweb-rv-rep-rankrow__avg">
														{ ' · ' }{ stars( r.avg_rating ) } { r.avg_rating.toFixed( 1 ) }
													</span>
												) }
											</div>
										</div>
										<span className="zaverweb-rv-rep-rankrow__count">{ r.total }</span>
									</li>
								) ) }
							</ol>
						) }
					</section>

					<div className="zaverweb-rv-rep-grid">
						<section className="zaverweb-rv-rep-card">
							<h3 className="zaverweb-rv-rep-card__title">
								{ __( 'Rating breakdown', 'zaverweb-reviews' ) }
							</h3>
							<p className="zaverweb-rv-rep-card__hint">
								{ __( 'Approved reviews by star rating.', 'zaverweb-reviews' ) }
							</p>
							<div className="zaverweb-rv-rep-dist">
								{ [ 5, 4, 3, 2, 1 ].map( ( s ) => {
									const n = ratings[ s ] || 0;
									const pct = ratingTotal ? Math.round( ( n / ratingTotal ) * 100 ) : 0;
									return (
										<div key={ s } className="zaverweb-rv-rep-distrow">
											<span className="zaverweb-rv-rep-distrow__star">{ s } ★</span>
											<div className="zaverweb-rv-rep-bar">
												<span
													className="zaverweb-rv-rep-bar__fill is-star"
													style={ { width: ( ( n / ratingMax ) * 100 ) + '%' } }
												/>
											</div>
											<span className="zaverweb-rv-rep-distrow__n">
												{ n }{ ratingTotal > 0 && <em> ({ pct }%)</em> }
											</span>
										</div>
									);
								} ) }
							</div>
						</section>

						<section className="zaverweb-rv-rep-card">
							<h3 className="zaverweb-rv-rep-card__title">
								{ __( 'By item type', 'zaverweb-reviews' ) }
							</h3>
							<p className="zaverweb-rv-rep-card__hint">
								{ __( 'How reviews split across posts, products and categories.', 'zaverweb-reviews' ) }
							</p>
							{ byType.length === 0 ? (
								<div className="zaverweb-rv-empty">{ __( 'Nothing yet.', 'zaverweb-reviews' ) }</div>
							) : (
								<div className="zaverweb-rv-rep-dist">
									{ byType.map( ( r ) => (
										<div key={ r.object_type } className="zaverweb-rv-rep-distrow">
											<span className="zaverweb-rv-rep-distrow__star">{ typeLabel( r.object_type ) }</span>
											<div className="zaverweb-rv-rep-bar">
												<span
													className="zaverweb-rv-rep-bar__fill is-type"
													style={ { width: ( ( r.total / typeMax ) * 100 ) + '%' } }
												/>
											</div>
											<span className="zaverweb-rv-rep-distrow__n">{ r.total }</span>
										</div>
									) ) }
								</div>
							) }
						</section>
					</div>

					<section className="zaverweb-rv-rep-card">
						<h3 className="zaverweb-rv-rep-card__title">
							{ __( 'Reviews over time', 'zaverweb-reviews' ) }
						</h3>
						<p className="zaverweb-rv-rep-card__hint">
							{ __( 'New reviews per month (last 12 months, all statuses except trash).', 'zaverweb-reviews' ) }
						</p>
						{ monthly.length === 0 ? (
							<div className="zaverweb-rv-empty">{ __( 'No activity in the last 12 months.', 'zaverweb-reviews' ) }</div>
						) : (
							<div className="zaverweb-rv-rep-trend">
								{ monthly.map( ( m ) => (
									<div key={ m.ym } className="zaverweb-rv-rep-trend__col" title={ m.ym + ': ' + m.total }>
										<span className="zaverweb-rv-rep-trend__n">{ m.total }</span>
										<span
											className="zaverweb-rv-rep-trend__bar"
											style={ { height: Math.max( 4, ( m.total / monthMax ) * 120 ) + 'px' } }
										/>
										<span className="zaverweb-rv-rep-trend__label">{ monthLabel( m.ym ) }</span>
									</div>
								) ) }
							</div>
						) }
					</section>
				</>
			) }
		</div>
	);
}
