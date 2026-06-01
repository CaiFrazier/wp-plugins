import { createRoot } from '@wordpress/element';
import './settings.css';
import App from './components/App';

const root = document.getElementById( 'bme-settings' );
if ( root ) {
	createRoot( root ).render( <App /> );
}
