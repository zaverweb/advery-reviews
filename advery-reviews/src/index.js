import { createRoot } from '@wordpress/element';
import App from './App';
import './style.scss';

document.addEventListener( 'DOMContentLoaded', () => {
	const root = document.getElementById( 'zaverweb-reviews-root' );
	if ( root ) {
		const screen = root.getAttribute( 'data-screen' ) || 'reviews';
		createRoot( root ).render( <App screen={ screen } /> );
	}
} );
