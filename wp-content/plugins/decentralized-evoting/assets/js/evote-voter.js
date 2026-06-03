/**
 * Brazil numeric ballot UI: enter code → confirm → encrypt → submit.
 */
(function () {
	'use strict';

	if (typeof evoteConfig === 'undefined' || typeof EVoteCryptoClient === 'undefined') {
		return;
	}

	const statusEl = document.getElementById('evote-status');
	const screenEntry = document.getElementById('evote-screen-entry');
	const screenConfirm = document.getElementById('evote-screen-confirm');
	const codeInput = document.getElementById('evote-code-input');
	const hintEnter = document.getElementById('evote-hint-enter');
	const keypad = document.getElementById('evote-keypad');
	const btnClear = document.getElementById('evote-btn-clear');
	const btnBranco = document.getElementById('evote-btn-branco');
	const btnNulo = document.getElementById('evote-btn-nulo');
	const btnConfirm = document.getElementById('evote-btn-confirm');
	const btnBack = document.getElementById('evote-btn-back');
	const confirmName = document.getElementById('evote-confirm-name');
	const confirmCode = document.getElementById('evote-confirm-code');
	const confirmWarn = document.getElementById('evote-confirm-warn');
	const confirmPhoto = document.getElementById('evote-confirm-photo');
	const confirmLogo = document.getElementById('evote-confirm-party-logo');
	const tokenInput = document.getElementById('evote-token');

	const i18n = evoteConfig.i18n || {};
	const codeLength = evoteConfig.codeLength || 5;
	let pending = null;
	let timeoutId = null;

	function setStatus(msg, isError) {
		if (!statusEl) return;
		statusEl.textContent = msg || '';
		statusEl.className = 'evote-poll__status' + (isError ? ' evote-poll__status--error' : ' evote-poll__status--ok');
	}

	function resetTimeout() {
		if (timeoutId) clearTimeout(timeoutId);
		const sec = evoteConfig.blankTimeoutSeconds || 0;
		if (sec > 0 && evoteConfig.allowBlank) {
			timeoutId = setTimeout(function () {
				submitBallot(EVoteCryptoClient.ENC_BLANK, '');
			}, sec * 1000);
		}
	}

	function buildKeypad() {
		if (!keypad) return;
		keypad.innerHTML = '';
		'1234567890'.split('').forEach(function (d) {
			const b = document.createElement('button');
			b.type = 'button';
			b.className = 'evote-key';
			b.textContent = d;
			b.addEventListener('click', function () {
				if (!codeInput) return;
				if (codeInput.value.length < codeLength) {
					codeInput.value += d;
					onCodeChanged();
				}
			});
			keypad.appendChild(b);
		});
	}

	function onCodeChanged() {
		resetTimeout();
		const code = (codeInput && codeInput.value) || '';
		if (code.length === codeLength) {
			showConfirmForCode(code, false);
		}
	}

	function showEntry() {
		if (screenEntry) screenEntry.classList.remove('evote-poll__screen--hidden');
		if (screenConfirm) screenConfirm.classList.add('evote-poll__screen--hidden');
		pending = null;
		if (codeInput) codeInput.value = '';
		resetTimeout();
	}

	function showConfirmScreen(data, isInvalid) {
		if (screenEntry) screenEntry.classList.add('evote-poll__screen--hidden');
		if (screenConfirm) screenConfirm.classList.remove('evote-poll__screen--hidden');
		pending = data;
		if (confirmName) confirmName.textContent = data.name || (isInvalid ? '—' : '');
		if (confirmCode) confirmCode.textContent = data.code ? 'Nº ' + data.code : '';
		if (confirmWarn) {
			if (isInvalid) {
				confirmWarn.textContent = i18n.invalidWarn || '';
				confirmWarn.classList.remove('evote-poll__warn--hidden');
			} else {
				confirmWarn.classList.add('evote-poll__warn--hidden');
			}
		}
		if (confirmPhoto) {
			if (data.photo_url) {
				confirmPhoto.src = data.photo_url;
				confirmPhoto.hidden = false;
			} else {
				confirmPhoto.hidden = true;
			}
		}
		if (confirmLogo) {
			if (data.party_logo_url) {
				confirmLogo.src = data.party_logo_url;
				confirmLogo.hidden = false;
			} else {
				confirmLogo.hidden = true;
			}
		}
	}

	function showConfirmForCode(code, forceInvalid) {
		const local = evoteConfig.candidateIndex && evoteConfig.candidateIndex[code];
		if (local && !forceInvalid) {
			showConfirmScreen({
				valid: true,
				code: code,
				name: local.title,
				photo_url: local.photo_url,
				party_logo_url: local.party_logo_url,
				encoding: EVoteCryptoClient.ENC_NUMBER,
			}, false);
			return;
		}
		const body = new FormData();
		body.append('action', 'evote_lookup_code');
		body.append('nonce', evoteConfig.lookupNonce);
		body.append('running_id', String(evoteConfig.runningId));
		body.append('code', code);
		fetch(evoteConfig.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (res.success && res.data && res.data.valid) {
					showConfirmScreen({
						valid: true,
						code: res.data.code,
						name: res.data.name,
						photo_url: res.data.photo_url,
						party_logo_url: res.data.party_logo_url,
						encoding: EVoteCryptoClient.ENC_NUMBER,
					}, false);
				} else {
					showConfirmScreen({
						valid: false,
						code: code,
						name: res.data && res.data.message ? res.data.message : (i18n.invalidWarn || ''),
						encoding: EVoteCryptoClient.ENC_NULL,
					}, true);
				}
			})
			.catch(function () {
				setStatus('Erro de rede.', true);
			});
	}

	function submitBallot(encoding, message) {
		if (timeoutId) clearTimeout(timeoutId);
		const token = tokenInput ? tokenInput.value.trim() : '';
		if (!token) {
			setStatus('Informe o token.', true);
			return;
		}
		let ballot;
		try {
			ballot = EVoteCryptoClient.encryptPayload(evoteConfig.publicKey, encoding, message);
		} catch (e) {
			setStatus('Falha na criptografia.', true);
			return;
		}
		const body = new FormData();
		body.append('action', 'evote_cast_ballot');
		body.append('nonce', evoteConfig.nonce);
		body.append('running_id', String(evoteConfig.runningId));
		body.append('token', token);
		body.append('ballots', JSON.stringify([ballot]));
		setStatus('Enviando…', false);
		fetch(evoteConfig.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (data.success) {
					setStatus(data.data && data.data.message ? data.data.message : (i18n.success || 'OK'), false);
					showEntry();
				} else {
					setStatus(data.data && data.data.message ? data.data.message : 'Erro', true);
				}
			})
			.catch(function () {
				setStatus('Erro de rede.', true);
			});
	}

	if (hintEnter) {
		hintEnter.textContent = (i18n.enterCode || 'Digite') + ' (' + codeLength + ' dígitos)';
	}
	buildKeypad();

	if (codeInput) {
		codeInput.addEventListener('input', onCodeChanged);
	}
	if (btnClear) {
		btnClear.addEventListener('click', showEntry);
	}
	if (btnBack) {
		btnBack.addEventListener('click', showEntry);
	}
	if (btnBranco) {
		btnBranco.addEventListener('click', function () {
			submitBallot(EVoteCryptoClient.ENC_BLANK, '');
		});
	}
	if (btnNulo) {
		btnNulo.addEventListener('click', function () {
			showConfirmScreen({
				valid: false,
				code: '',
				name: i18n.nulo || 'Nulo',
				encoding: EVoteCryptoClient.ENC_NULL,
			}, false);
		});
	}
	if (btnConfirm) {
		btnConfirm.addEventListener('click', function () {
			if (!pending) return;
			if (!pending.valid && pending.encoding === EVoteCryptoClient.ENC_NULL) {
				if (!evoteConfig.allowNull) {
					setStatus('Voto nulo não permitido.', true);
					return;
				}
				submitBallot(EVoteCryptoClient.ENC_NULL, '');
			} else if (pending.valid) {
				submitBallot(EVoteCryptoClient.ENC_NUMBER, pending.code);
			}
		});
	}

	showEntry();
})();
