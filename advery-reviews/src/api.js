import apiFetch from '@wordpress/api-fetch';

const config = window.AdveryReviewsConfig || {};

apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );
apiFetch.use(
	apiFetch.createRootURLMiddleware(
		( config.restUrl || '' ).replace( /\/advery-reviews\/v1$/, '/' )
	)
);

const base = 'advery-reviews/v1';

export const api = {
	bootstrap: () => apiFetch( { path: `${ base }/bootstrap` } ),
	listReviews: ( params ) => {
		const q = new URLSearchParams(
			Object.entries( params ).filter( ( [ , v ] ) => v !== '' && v !== 0 && v != null )
		).toString();
		return apiFetch( { path: `${ base }/reviews${ q ? '?' + q : '' }` } );
	},
	setStatus: ( id, status ) =>
		apiFetch( { path: `${ base }/reviews/${ id }/status`, method: 'POST', data: { status } } ),
	remove: ( id ) => apiFetch( { path: `${ base }/reviews/${ id }`, method: 'DELETE' } ),
	bulk: ( ids, action ) =>
		apiFetch( { path: `${ base }/reviews/bulk`, method: 'POST', data: { ids, action } } ),
	saveSettings: ( settings ) =>
		apiFetch( { path: `${ base }/settings`, method: 'POST', data: { settings } } ),
	maintenance: ( action ) =>
		apiFetch( { path: `${ base }/maintenance`, method: 'POST', data: { action } } ),
	migrationPreview: () => apiFetch( { path: `${ base }/migration/preview` } ),
	migrationImport: ( data ) =>
		apiFetch( { path: `${ base }/migration/import`, method: 'POST', data } ),
	migrationExport: ( data ) =>
		apiFetch( { path: `${ base }/migration/export`, method: 'POST', data } ),
	exportCsv: () => apiFetch( { path: `${ base }/export-csv` } ),
	importData: ( data ) =>
		apiFetch( { path: `${ base }/migration/import-data`, method: 'POST', data } ),
};
