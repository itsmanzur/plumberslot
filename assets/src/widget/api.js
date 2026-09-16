const boot =
	typeof window !== 'undefined' && window.plumberslotWidget
		? window.plumberslotWidget
		: {
				root: '/wp-json/plumberslot/v1',
				nonce: '',
				loggedIn: false,
				loginUrl: '/wp-login.php',
				dashboardUrl: '/',
				user: null,
				payments: { stripe: false, bkash: false },
				i18n: {},
			};

export function getBoot() {
	return boot;
}

export class ApiError extends Error {
	constructor( message, { code = 'error', status = 0 } = {} ) {
		super( message );
		this.code = code;
		this.status = status;
	}
}

export async function api( path, options = {} ) {
	const { method = 'GET', body, headers = {}, keepalive = false } = options;
	const bootCfg = getBoot();
	const url = `${ bootCfg.root.replace( /\/$/, '' ) }/${ path.replace(
		/^\//,
		''
	) }`;
	const response = await fetch( url, {
		method,
		credentials: 'same-origin',
		headers: {
			Accept: 'application/json',
			'Content-Type': 'application/json',
			...( bootCfg.nonce ? { 'X-WP-Nonce': bootCfg.nonce } : {} ),
			...headers,
		},
		body: body ? JSON.stringify( body ) : undefined,
		keepalive,
	} );

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
		throw new ApiError( payload?.message || 'Request failed.', {
			code: payload?.code || 'error',
			status: response.status,
		} );
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
	return api( path, { method: 'POST', body } );
}

export function remove( path, body, { keepalive = false } = {} ) {
	return api( path, { method: 'DELETE', body, keepalive } );
}

/**
 * Upload one photo as multipart form data. Separate from api()/post() above
 * because those always JSON-encode the body and force a JSON Content-Type —
 * a multipart upload needs the browser to set its own boundary, so the
 * Content-Type header is left out entirely here.
 *
 * @param {File} file
 * @return {Promise<{id:number,url:string}>} The new attachment id and a preview URL.
 */
export async function uploadPhoto( file ) {
	const bootCfg = getBoot();
	const url = `${ bootCfg.root.replace( /\/$/, '' ) }/public/uploads`;
	const formData = new FormData();
	formData.append( 'file', file );

	const response = await fetch( url, {
		method: 'POST',
		credentials: 'same-origin',
		headers: {
			Accept: 'application/json',
			...( bootCfg.nonce ? { 'X-WP-Nonce': bootCfg.nonce } : {} ),
		},
		body: formData,
	} );

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
		throw new ApiError( payload?.message || 'Upload failed.', {
			code: payload?.code || 'error',
			status: response.status,
		} );
	}

	return payload;
}
