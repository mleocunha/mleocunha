/**
 * Client-side modular ElGamal encryption (RFC 3526 parameters from public key JSON).
 */
(function (global) {
	'use strict';

	function hexToBigInt(hex) {
		hex = String(hex).replace(/^0x/i, '').replace(/\s+/g, '');
		if (hex === '') {
			return 0n;
		}
		return BigInt('0x' + hex);
	}

	function bigIntToHex(n) {
		if (n === 0n) {
			return '0';
		}
		let hex = n.toString(16);
		return hex.length % 2 === 1 ? '0' + hex : hex;
	}

	function modPow(base, exp, mod) {
		base = ((base % mod) + mod) % mod;
		let result = 1n;
		let b = base;
		let e = exp;
		while (e > 0n) {
			if (e & 1n) {
				result = (result * b) % mod;
			}
			e >>= 1n;
			b = (b * b) % mod;
		}
		return result;
	}

	function randomBigIntBelow(max) {
		const maxBits = max.toString(2).length;
		const byteLen = Math.ceil(maxBits / 8) + 8;
		let n = 0n;
		do {
			const buf = new Uint8Array(byteLen);
			crypto.getRandomValues(buf);
			let hex = '';
			for (let i = 0; i < buf.length; i++) {
				hex += buf[i].toString(16).padStart(2, '0');
			}
			n = BigInt('0x' + hex) % max;
		} while (n < 1n);
		return n;
	}

	function normalizeGenerator(g, p) {
		const two = 2n;
		let h = g < two ? two : g;
		let gen = modPow(h, two, p);
		if (gen === 1n) {
			gen = modPow(two, two, p);
		}
		return gen;
	}

	/**
	 * Encrypt vote integer with exported public key.
	 *
	 * @param {object} publicKey evote-public-key JSON
	 * @param {number} voteInteger candidate id
	 * @returns {object} evote-encrypted-ballot
	 */
	function encryptVote(publicKey, voteInteger) {
		const p = hexToBigInt(publicKey.p);
		const q = hexToBigInt(publicKey.q);
		const g = normalizeGenerator(hexToBigInt(publicKey.g), p);
		const y = hexToBigInt(publicKey.y);
		const m = BigInt(Math.max(1, parseInt(voteInteger, 10) || 0));

		if (m <= 0n || m >= p) {
			throw new Error('Invalid vote encoding');
		}

		const k = randomBigIntBelow(q);
		const c1 = modPow(g, k, p);
		const c2 = (m * modPow(y, k, p)) % p;

		return {
			schema: 'evote-encrypted-ballot',
			version: '1',
			key_id: publicKey.key_id || null,
			message_encoding: 'vote-integer',
			message: String(voteInteger),
			c1: bigIntToHex(c1),
			c2: bigIntToHex(c2),
		};
	}

	global.EVoteCryptoClient = {
		encryptVote: encryptVote,
	};
})(typeof window !== 'undefined' ? window : globalThis);
