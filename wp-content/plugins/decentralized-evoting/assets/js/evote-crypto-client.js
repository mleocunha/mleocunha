/**
 * Client-side modular ElGamal — Brazil ballot encodings.
 */
(function (global) {
	'use strict';

	const ENC_NUMBER = 'br-number';
	const ENC_BLANK = 'br-blank';
	const ENC_NULL = 'br-nulo';
	const ENC_EXP_ONE_HOT = 'br-exp-one-hot';
	const ENC_EXP_BIT = 'br-exp-bit';

	function hexToBigInt(hex) {
		hex = String(hex).replace(/^0x/i, '').replace(/\s+/g, '');
		return hex === '' ? 0n : BigInt('0x' + hex);
	}

	function bigIntToHex(n) {
		if (n === 0n) return '0';
		let hex = n.toString(16);
		return hex.length % 2 === 1 ? '0' + hex : hex;
	}

	function modPow(base, exp, mod) {
		base = ((base % mod) + mod) % mod;
		let result = 1n;
		let b = base;
		let e = exp;
		while (e > 0n) {
			if (e & 1n) result = (result * b) % mod;
			e >>= 1n;
			b = (b * b) % mod;
		}
		return result;
	}

	function randomBigIntBelow(max) {
		const byteLen = 32;
		let n = 0n;
		do {
			const buf = new Uint8Array(byteLen);
			crypto.getRandomValues(buf);
			let hex = '';
			for (let i = 0; i < buf.length; i++) hex += buf[i].toString(16).padStart(2, '0');
			n = BigInt('0x' + hex) % max;
		} while (n < 1n);
		return n;
	}

	function normalizeGenerator(g, p) {
		const two = 2n;
		let h = g < two ? two : g;
		let gen = modPow(h, two, p);
		if (gen === 1n) gen = modPow(two, two, p);
		return gen;
	}

	function encryptPayload(publicKey, encoding, message) {
		const p = hexToBigInt(publicKey.p);
		const q = hexToBigInt(publicKey.q);
		const g = normalizeGenerator(hexToBigInt(publicKey.g), p);
		const y = hexToBigInt(publicKey.y);
		let m;
		if (encoding === ENC_BLANK) {
			m = 0n;
			message = '';
		} else if (encoding === ENC_NULL) {
			m = 1n;
			message = '';
		} else {
			message = String(message).replace(/\D/g, '');
			m = BigInt(message);
		}
		const k = randomBigIntBelow(q);
		const c1 = modPow(g, k, p);
		const c2 = (m * modPow(y, k, p)) % p;
		return {
			schema: 'evote-encrypted-ballot',
			version: '1',
			key_id: publicKey.key_id || null,
			message_encoding: encoding,
			message: message,
			c1: bigIntToHex(c1),
			c2: bigIntToHex(c2),
		};
	}

	function encryptVote(publicKey, voteInteger) {
		return encryptPayload(publicKey, ENC_NUMBER, String(voteInteger));
	}

	function encryptExponentialBit(publicKey, bit) {
		const p = hexToBigInt(publicKey.p);
		const q = hexToBigInt(publicKey.q);
		const g = normalizeGenerator(hexToBigInt(publicKey.g), p);
		const y = hexToBigInt(publicKey.y);
		const m = bit ? 1n : 0n;
		const gm = modPow(g, m, p);
		const k = randomBigIntBelow(q);
		const c1 = modPow(g, k, p);
		const c2 = (gm * modPow(y, k, p)) % p;
		return {
			c1: bigIntToHex(c1),
			c2: bigIntToHex(c2),
		};
	}

	function buildOneHotBallot(publicKey, candidates, chosenCode) {
		chosenCode = String(chosenCode || '').replace(/\D/g, '');
		const slots = [];
		candidates.forEach(function (c) {
			const code = String(c.ballot_number || '').replace(/\D/g, '');
			if (!code) {
				return;
			}
			const bit = code === chosenCode && chosenCode !== '' ? 1 : 0;
			const ct = encryptExponentialBit(publicKey, bit);
			slots.push({
				code: code,
				bit: bit,
				c1: ct.c1,
				c2: ct.c2,
			});
		});
		return {
			schema: 'evote-encrypted-ballot',
			version: '2',
			key_id: publicKey.key_id || null,
			message_encoding: ENC_EXP_ONE_HOT,
			selected_code: chosenCode,
			slots: slots,
		};
	}

	function encryptReferendumBit(publicKey, yes) {
		const ct = encryptExponentialBit(publicKey, yes ? 1 : 0);
		return {
			schema: 'evote-encrypted-ballot',
			version: '2',
			key_id: publicKey.key_id || null,
			message_encoding: ENC_EXP_BIT,
			message: yes ? '1' : '0',
			c1: ct.c1,
			c2: ct.c2,
		};
	}

	global.EVoteCryptoClient = {
		ENC_NUMBER: ENC_NUMBER,
		ENC_BLANK: ENC_BLANK,
		ENC_NULL: ENC_NULL,
		ENC_EXP_ONE_HOT: ENC_EXP_ONE_HOT,
		ENC_EXP_BIT: ENC_EXP_BIT,
		encryptPayload: encryptPayload,
		encryptVote: encryptVote,
		encryptExponentialBit: encryptExponentialBit,
		buildOneHotBallot: buildOneHotBallot,
		encryptReferendumBit: encryptReferendumBit,
	};
})(typeof window !== 'undefined' ? window : globalThis);
