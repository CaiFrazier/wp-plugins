/**
 * SHA-256 correctness suite. The streaming hasher is hand-rolled (crypto.subtle
 * has no incremental API and is absent on plain-HTTP wp-admin), so it is
 * cross-validated against NIST vectors and Node's crypto for every padding
 * edge case around the 64-byte block boundary.
 *
 * Run: npm run test:js
 */
const crypto = require( 'crypto' );
const { Sha256, sha256Hex } = require( './sha256' );

const enc = ( s ) => new Uint8Array( Buffer.from( s, 'utf8' ) );
const nodeHex = ( buf ) =>
	crypto.createHash( 'sha256' ).update( Buffer.from( buf ) ).digest( 'hex' );

describe( 'Sha256 — known answers', () => {
	test( 'empty string', () => {
		expect( new Sha256().update( new Uint8Array( 0 ) ).hex() ).toBe(
			'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'
		);
	} );

	test( '"abc"', () => {
		expect( new Sha256().update( enc( 'abc' ) ).hex() ).toBe(
			'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad'
		);
	} );

	test( '56-byte NIST vector (padding boundary)', () => {
		expect(
			new Sha256()
				.update(
					enc(
						'abcdbcdecdefdefgefghfghighijhijkijkljklmklmnlmnomnopnopq'
					)
				)
				.hex()
		).toBe(
			'248d6a61d20638b8e5c026930c3e6039a33ce45964ff2167f6ecedd419db06c1'
		);
	} );
} );

describe( 'Sha256 — padding edge cases vs Node crypto', () => {
	test.each( [
		0, 1, 2, 55, 56, 57, 63, 64, 65, 119, 120, 127, 128, 129, 1000,
		1048576, 1048577,
	] )( 'length %i', ( n ) => {
		const data = crypto.randomBytes( n );
		expect( new Sha256().update( new Uint8Array( data ) ).hex() ).toBe(
			nodeHex( data )
		);
	} );
} );

describe( 'Sha256 — incremental updates equal one-shot', () => {
	test( 'split into 7-byte writes', () => {
		const big = crypto.randomBytes( 200000 );
		const h = new Sha256();
		for ( let i = 0; i < big.length; i += 7 ) {
			h.update( new Uint8Array( big.subarray( i, i + 7 ) ) );
		}
		expect( h.hex() ).toBe( nodeHex( big ) );
	} );

	test( 'split on block-aligned and unaligned offsets', () => {
		const big = crypto.randomBytes( 200000 );
		const h = new Sha256();
		let off = 0;
		for ( const cut of [ 0, 1, 63, 64, 65, 100000 ] ) {
			h.update( new Uint8Array( big.subarray( off, cut ) ) );
			off = cut;
		}
		h.update( new Uint8Array( big.subarray( off ) ) );
		expect( h.hex() ).toBe( nodeHex( big ) );
	} );
} );

describe( 'sha256Hex helper', () => {
	test( 'falls back to JS impl when crypto.subtle is unavailable', async () => {
		expect( await sha256Hex( enc( 'abc' ) ) ).toBe(
			'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad'
		);
	} );
} );
