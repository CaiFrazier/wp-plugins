import { createRoot } from '@wordpress/element';
import './diagnostics.css';
import App from './components/App';

const root = document.getElementById( 'bme-diagnostics' );
if ( root ) {
	createRoot( root ).render( <App /> );
}
