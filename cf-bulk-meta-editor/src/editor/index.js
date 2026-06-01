import { createRoot } from '@wordpress/element';
import './editor.css';
import App from './components/App';

const root = document.getElementById( 'bme-editor' );
if ( root ) {
	createRoot( root ).render( <App /> );
}
