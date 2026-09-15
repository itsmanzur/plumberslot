import { getConfig } from './config';

export class ApiError extends Error {
	constructor(
		message,
		{ code = 'plumberslot_error', status = 0, data = null } = {}
	) {
		super( message );
		this.name = 'ApiError';
		this.code = code;
		this.status = status;
		this.data = data;
	}
}

function normalizeError( payload, status ) {
	if ( payload && typeof payload === 'object' ) {
		return new ApiError( payload.message || 'Request failed.', {
			code: payload.code || 'plumberslot_error',
			status: payload.data?.status || status,
			data: payload.data || null,
		} );
	}

	return new ApiError( 'Request failed.', { status } );
}

/**
 * REST client with nonce + error normalization.
 *
 * @param {string} path
 * @param {Object} [options]
 */
export async function api( path, options = {} ) {
	const { raw = false, headers = {}, ...rest } = options;
	const config = getConfig();
	const url = path.startsWith( 'http' )
		? path
		: `${ config.root.replace( /\/$/, '' ) }/${ path.replace(
				/^\//,
				''
			) }`;

	const response = await fetch( url, {
		credentials: 'same-origin',
		...rest,
		headers: {
			Accept: 'application/json',
			'Content-Type': 'application/json',
			'X-WP-Nonce': config.nonce,
			...headers,
		},
	} );

	if ( raw ) {
		if ( ! response.ok ) {
			let payload = null;
			try {
				payload = await response.json();
			} catch {
				payload = null;
			}
			throw normalizeError( payload, response.status );
		}
		return response;
	}

	let payload = null;
	const text = await response.text();

	if ( text ) {
		try {
			payload = JSON.parse( text );
		} catch {
			payload = text;
		}
	}

	if ( ! response.ok ) {
		throw normalizeError( payload, response.status );
	}

	return payload;
}

export function get( path, query = {} ) {
	const params = new URLSearchParams();
	Object.entries( query ).forEach( ( [ key, value ] ) => {
		if ( value !== undefined && value !== null && value !== '' ) {
			params.set( key, String( value ) );
		}
	} );
	const qs = params.toString();
	return api( qs ? `${ path }?${ qs }` : path );
}

export function post( path, body ) {
	return api( path, { method: 'POST', body: JSON.stringify( body ?? {} ) } );
}

export function put( path, body ) {
	return api( path, { method: 'PUT', body: JSON.stringify( body ?? {} ) } );
}

export function patch( path, body ) {
	return api( path, { method: 'PATCH', body: JSON.stringify( body ?? {} ) } );
}

export function del( path ) {
	return api( path, { method: 'DELETE' } );
}
