import { createRoot } from '@wordpress/element';
import App from './components/App';
import './index.css';

const root = document.getElementById( 'som-settings-root' );
if ( root ) {
	createRoot( root ).render( <App /> );
}
