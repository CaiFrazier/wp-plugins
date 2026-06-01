/**
 * Pure-JS SHA-256 with an incremental `update()` / `hex()` API.
 *
 * Why hand-rolled instead of crypto.subtle:
 *   1. crypto.subtle has no streaming digest — hashing an 8 GB file with it
 *      means materializing 8 GB in memory, which crashes the tab. This
 *      implementation hashes block-by-block with O(1) memory.
 *   2. crypto.subtle is only exposed in secure contexts. Plenty of WordPress
 *      admins run over plain HTTP; there subtle is undefined and any
 *      subtle-only path silently produces empty digests. This works
 *      everywhere.
 *
 * crypto.subtle is still preferred for one-shot per-chunk hashing (it is much
 * faster on an 8 MB buffer) via sha256Hex(); the streaming class is used for
 * the whole-file digest.
 */

const K = new Uint32Array( [
	0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1,
	0x923f82a4, 0xab1c5ed5, 0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3,
	0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174, 0xe49b69c1, 0xefbe4786,
	0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
	0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147,
	0x06ca6351, 0x14292967, 0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13,
	0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85, 0xa2bfe8a1, 0xa81a664b,
	0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
	0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a,
	0x5b9cca4f, 0x682e6ff3, 0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208,
	0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
] );

export class Sha256 {
	constructor() {
		this._h = new Uint32Array( [
			0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f,
			0x9b05688c, 0x1f83d9ab, 0x5be0cd19,
		] );
		this._buf = new Uint8Array( 64 );
		this._bufLen = 0;
		this._bytes = 0;
		this._w = new Uint32Array( 64 );
	}

	_block( p, off ) {
		const w = this._w;
		for ( let i = 0; i < 16; i++ ) {
			w[ i ] =
				( p[ off + i * 4 ] << 24 ) |
				( p[ off + i * 4 + 1 ] << 16 ) |
				( p[ off + i * 4 + 2 ] << 8 ) |
				p[ off + i * 4 + 3 ];
		}
		for ( let i = 16; i < 64; i++ ) {
			const a = w[ i - 15 ];
			const b = w[ i - 2 ];
			const s0 =
				( ( a >>> 7 ) | ( a << 25 ) ) ^
				( ( a >>> 18 ) | ( a << 14 ) ) ^
				( a >>> 3 );
			const s1 =
				( ( b >>> 17 ) | ( b << 15 ) ) ^
				( ( b >>> 19 ) | ( b << 13 ) ) ^
				( b >>> 10 );
			w[ i ] = ( w[ i - 16 ] + s0 + w[ i - 7 ] + s1 ) | 0;
		}

		let [ h0, h1, h2, h3, h4, h5, h6, h7 ] = this._h;
		for ( let i = 0; i < 64; i++ ) {
			const S1 =
				( ( h4 >>> 6 ) | ( h4 << 26 ) ) ^
				( ( h4 >>> 11 ) | ( h4 << 21 ) ) ^
				( ( h4 >>> 25 ) | ( h4 << 7 ) );
			const ch = ( h4 & h5 ) ^ ( ~h4 & h6 );
			const t1 = ( h7 + S1 + ch + K[ i ] + w[ i ] ) | 0;
			const S0 =
				( ( h0 >>> 2 ) | ( h0 << 30 ) ) ^
				( ( h0 >>> 13 ) | ( h0 << 19 ) ) ^
				( ( h0 >>> 22 ) | ( h0 << 10 ) );
			const maj = ( h0 & h1 ) ^ ( h0 & h2 ) ^ ( h1 & h2 );
			const t2 = ( S0 + maj ) | 0;
			h7 = h6;
			h6 = h5;
			h5 = h4;
			h4 = ( h3 + t1 ) | 0;
			h3 = h2;
			h2 = h1;
			h1 = h0;
			h0 = ( t1 + t2 ) | 0;
		}
		const h = this._h;
		h[ 0 ] = ( h[ 0 ] + h0 ) | 0;
		h[ 1 ] = ( h[ 1 ] + h1 ) | 0;
		h[ 2 ] = ( h[ 2 ] + h2 ) | 0;
		h[ 3 ] = ( h[ 3 ] + h3 ) | 0;
		h[ 4 ] = ( h[ 4 ] + h4 ) | 0;
		h[ 5 ] = ( h[ 5 ] + h5 ) | 0;
		h[ 6 ] = ( h[ 6 ] + h6 ) | 0;
		h[ 7 ] = ( h[ 7 ] + h7 ) | 0;
	}

	update( bytes ) {
		const data = bytes instanceof Uint8Array ? bytes : new Uint8Array( bytes );
		this._bytes += data.length;
		let i = 0;

		if ( this._bufLen > 0 ) {
			while ( i < data.length && this._bufLen < 64 ) {
				this._buf[ this._bufLen++ ] = data[ i++ ];
			}
			if ( this._bufLen === 64 ) {
				this._block( this._buf, 0 );
				this._bufLen = 0;
			}
		}
		while ( data.length - i >= 64 ) {
			this._block( data, i );
			i += 64;
		}
		while ( i < data.length ) {
			this._buf[ this._bufLen++ ] = data[ i++ ];
		}
		return this;
	}

	hex() {
		const bitLen = this._bytes * 8;
		const pad = [ 0x80 ];
		while ( ( this._bytes + pad.length ) % 64 !== 56 ) {
			pad.push( 0 );
		}
		// 64-bit big-endian length (high 32 bits are ~always zero for browser
		// file sizes, but emit them for spec correctness).
		const hi = Math.floor( bitLen / 0x100000000 );
		const lo = bitLen >>> 0;
		pad.push(
			( hi >>> 24 ) & 0xff,
			( hi >>> 16 ) & 0xff,
			( hi >>> 8 ) & 0xff,
			hi & 0xff,
			( lo >>> 24 ) & 0xff,
			( lo >>> 16 ) & 0xff,
			( lo >>> 8 ) & 0xff,
			lo & 0xff
		);
		this.update( new Uint8Array( pad ) );

		let out = '';
		for ( let i = 0; i < 8; i++ ) {
			out += ( this._h[ i ] >>> 0 ).toString( 16 ).padStart( 8, '0' );
		}
		return out;
	}
}

function hexOf( buf ) {
	const v = new Uint8Array( buf );
	let s = '';
	for ( let i = 0; i < v.length; i++ ) {
		s += v[ i ].toString( 16 ).padStart( 2, '0' );
	}
	return s;
}

/**
 * One-shot SHA-256 of a buffer → hex. Prefers crypto.subtle (fast on an
 * 8 MB chunk) and falls back to the pure-JS implementation on non-secure
 * contexts (plain-HTTP wp-admin) where subtle is undefined.
 *
 * @param {ArrayBuffer|Uint8Array} buf
 * @return {Promise<string>}
 */
export async function sha256Hex( buf ) {
	if ( typeof crypto !== 'undefined' && crypto.subtle ) {
		try {
			const d = await crypto.subtle.digest(
				'SHA-256',
				buf instanceof Uint8Array ? buf : new Uint8Array( buf )
			);
			return hexOf( d );
		} catch ( e ) {
			/* fall through to JS */
		}
	}
	return new Sha256().update( buf ).hex();
}
