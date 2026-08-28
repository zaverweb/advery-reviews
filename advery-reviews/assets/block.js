/* Advery Reviews — a dynamic (server-rendered) Gutenberg block.
   Plain browser JS against the wp.* globals, so no separate build step. */
( function ( blocks, element, blockEditor, serverSideRender, i18n ) {
	if ( ! blocks || ! serverSideRender ) {
		return;
	}
	var el = element.createElement;
	var __ = i18n.__;
	var useBlockProps = blockEditor && blockEditor.useBlockProps ? blockEditor.useBlockProps : function () { return {}; };

	blocks.registerBlockType( 'advery/reviews', {
		apiVersion: 2,
		title: __( 'Advery Reviews', 'advery-reviews' ),
		description: __( 'Ratings and reviews for this page.', 'advery-reviews' ),
		icon: 'star-filled',
		category: 'widgets',
		keywords: [ __( 'reviews', 'advery-reviews' ), __( 'ratings', 'advery-reviews' ) ],
		attributes: {
			source: { type: 'string', default: 'current' },
			postId: { type: 'number', default: 0 }
		},
		edit: function ( props ) {
			return el(
				'div',
				useBlockProps(),
				el( serverSideRender, {
					block: 'advery/reviews',
					attributes: props.attributes
				} )
			);
		},
		save: function () {
			return null; // dynamic — rendered by PHP
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.serverSideRender, window.wp.i18n );
