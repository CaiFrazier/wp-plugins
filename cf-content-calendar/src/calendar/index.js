import { createElement, createRoot } from '@wordpress/element';
import CalendarApp from './components/CalendarApp';
import './calendar.css';

const root = document.getElementById( 'cf-cal-root' );
if ( root ) {
	createRoot( root ).render( createElement( CalendarApp ) );
}
