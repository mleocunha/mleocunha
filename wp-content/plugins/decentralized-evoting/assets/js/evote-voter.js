/**
 * Voting UI — encrypt choices client-side, submit via AJAX.
 */
(function () {
	'use strict';

	if (typeof evoteConfig === 'undefined' || typeof EVoteCryptoClient === 'undefined') {
		return;
	}

	const form = document.getElementById('evote-ballot-form');
	const statusEl = document.getElementById('evote-status');
	if (!form || !statusEl) {
		return;
	}

	function setStatus(message, isError) {
		statusEl.textContent = message;
		statusEl.className = 'evote-poll__status' + (isError ? ' evote-poll__status--error' : ' evote-poll__status--ok');
	}

	function getSelectedVotes() {
		const modality = evoteConfig.modalityType || 'single';
		const inputs = form.querySelectorAll('input[name="evote_choice"]');
		const selected = [];
		inputs.forEach(function (input) {
			if (input.checked) {
				selected.push(parseInt(input.value, 10));
			}
		});
		if (selected.length === 0) {
			throw new Error('Please select at least one choice.');
		}
		if (modality === 'single' && selected.length !== 1) {
			throw new Error('Please select exactly one candidate.');
		}
		const max = evoteConfig.maxChoices || 1;
		if (selected.length > max) {
			throw new Error('Too many choices selected (max ' + max + ').');
		}
		return selected;
	}

	form.addEventListener('submit', function (e) {
		e.preventDefault();
		setStatus('', false);

		const tokenInput = document.getElementById('evote-token');
		const token = tokenInput ? tokenInput.value.trim() : '';
		if (!token) {
			setStatus('Voting token required.', true);
			return;
		}

		let votes;
		try {
			votes = getSelectedVotes();
		} catch (err) {
			setStatus(err.message || 'Invalid selection.', true);
			return;
		}

		let ballots;
		try {
			ballots = votes.map(function (voteId) {
				return EVoteCryptoClient.encryptVote(evoteConfig.publicKey, voteId);
			});
		} catch (err) {
			setStatus('Encryption failed: ' + (err.message || 'unknown error'), true);
			return;
		}

		const submitBtn = form.querySelector('.evote-poll__submit');
		if (submitBtn) {
			submitBtn.disabled = true;
		}
		setStatus('Submitting encrypted vote…', false);

		const body = new FormData();
		body.append('action', 'evote_cast_ballot');
		body.append('nonce', evoteConfig.nonce);
		body.append('running_id', String(evoteConfig.runningId));
		body.append('token', token);
		body.append('ballots', JSON.stringify(ballots));

		fetch(evoteConfig.ajaxUrl, {
			method: 'POST',
			body: body,
			credentials: 'same-origin',
		})
			.then(function (res) {
				return res.json();
			})
			.then(function (data) {
				if (data.success) {
					setStatus(data.data && data.data.message ? data.data.message : 'Vote recorded.', false);
					form.reset();
				} else {
					const msg = data.data && data.data.message ? data.data.message : 'Submission failed.';
					setStatus(msg, true);
					if (submitBtn) {
						submitBtn.disabled = false;
					}
				}
			})
			.catch(function () {
				setStatus('Network error. Please try again.', true);
				if (submitBtn) {
					submitBtn.disabled = false;
				}
			});
	});
})();
