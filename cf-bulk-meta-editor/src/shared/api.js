/**
 * Wrapper around @wordpress/api-fetch that logs every request and response,
 * including timing and shape information for the response payload.
 *
 * Use this everywhere instead of importing apiFetch directly (the only
 * exception is logger.js itself, to avoid circular logging).
 */

import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import bmeLog from './logger';

let counter = 0;

function shape( value ) {
	if ( Array.isArray( value ) ) return { type: 'array', length: value.length };
	if ( value === null )         return { type: 'null' };
	if ( typeof value === 'object' ) {
		return { type: 'object', keys: Object.keys( value ).slice( 0, 20 ) };
	}
	return { type: typeof value };
}

export async function bmeApi( options ) {
	const id      = ++counter;
	const started = performance.now();
	const path    = options.path || '(unknown)';
	const method  = options.method || 'GET';

	// `@wordpress/api-fetch` does not serialize `data` into the query string
	// for GET requests — it only ever attaches `data` as a JSON body. For GETs
	// that means our params silently disappear before they hit the REST server,
	// and routes with `required: true` args reject the call in ~4ms with
	// "Could not get a valid response from the server." Move GET params into
	// the URL via addQueryArgs before delegating so every caller can keep
	// using the natural `{ data: { ... } }` shape.
	let outgoing = options;
	if ( method === 'GET' && options.data && options.path ) {
		const { data, ...rest } = options;
		outgoing = { ...rest, path: addQueryArgs( options.path, data ) };
	}

	bmeLog.debug( 'api', `→ ${ method } ${ path }`, {
		id,
		path,
		method,
		hasBody: 'data' in options,
	} );

	try {
		const response = await apiFetch( outgoing );
		const duration = Math.round( performance.now() - started );
		bmeLog.debug( 'api', `← ${ method } ${ path } ${ duration }ms`, {
			id,
			duration_ms: duration,
			response_shape: shape( response ),
		} );
		return response;
	} catch ( err ) {
		const duration = Math.round( performance.now() - started );
		// WP REST errors come back as objects with `code` / `message` / `data`.
		const errMessage = err?.message || String( err );
		bmeLog.error( 'api', `✗ ${ method } ${ path } ${ duration }ms`, {
			id,
			duration_ms: duration,
			code: err?.code,
			error_message: errMessage,
			error_data: err?.data,
		} );
		throw err;
	}
}

export default bmeApi;
