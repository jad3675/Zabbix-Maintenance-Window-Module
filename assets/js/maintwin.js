/**
 * Maintenance Windows.
 *
 * Module assets load on every frontend page, so bail out immediately unless
 * we are actually on the loader page.
 */
(function () {
	'use strict';

	var DOW = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
	var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
	var WEEKS = {1: 'first', 2: 'second', 3: 'third', 4: 'fourth', 5: 'last'};

	function boot() {
		var root = document.querySelector('.ml-page');

		if (!root || !window.MAINTWIN) {
			return;
		}

		var CFG = window.MAINTWIN;
		var state = {
			rows: [],
			hostids: [],
			sched: 'onetime',
			start_mode: 'now',
			monthly_mode: 'day',
			collect: CFG.default_collect_data ? 1 : 0,
			editing: null,
			busy: false
		};

		/* ---------------------------------------------------------------- */
		/* helpers                                                          */
		/* ---------------------------------------------------------------- */

		function $(id) {
			return document.getElementById(id);
		}

		function all(selector, scope) {
			return Array.prototype.slice.call((scope || root).querySelectorAll(selector));
		}

		function el(tag, attrs, children) {
			var node = document.createElement(tag);

			if (attrs) {
				Object.keys(attrs).forEach(function (key) {
					if (key === 'class') {
						node.className = attrs[key];
					}
					else if (key === 'text') {
						node.textContent = attrs[key];
					}
					else if (attrs[key] !== null && attrs[key] !== undefined) {
						node.setAttribute(key, attrs[key]);
					}
				});
			}

			(children || []).forEach(function (child) {
				if (child === null || child === undefined) {
					return;
				}
				node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
			});

			return node;
		}

		function clear(node) {
			while (node.firstChild) {
				node.removeChild(node.firstChild);
			}
		}

		function post(action, params) {
			var body = new URLSearchParams();

			Object.keys(params || {}).forEach(function (key) {
				var value = params[key];

				if (Array.isArray(value)) {
					value.forEach(function (item) {
						body.append(key + '[]', item);
					});
				}
				else {
					body.append(key, value);
				}
			});

			return fetch('zabbix.php?action=' + encodeURIComponent(action), {
				method: 'POST',
				headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
				body: body.toString(),
				credentials: 'same-origin'
			})
				.then(function (response) {
					return response.text();
				})
				.then(function (text) {
					try {
						return JSON.parse(text);
					}
					catch (err) {
						if (/^\s*<(!doctype|html)/i.test(text)) {
							// Zabbix rendered a full page: the request was
							// rejected before the module answered. Session
							// expired, no permission for the action, or the
							// controller failed input validation.
							throw new Error(action + ' returned a Zabbix HTML page instead of JSON. '
								+ 'The request was rejected before it reached the module. '
								+ 'Reload the page and sign in again if your session has expired.');
						}

						// Usually a stray PHP notice ahead of the JSON.
						throw new Error('Unexpected response from ' + action + ': '
							+ text.substring(0, 200));
					}
				});
		}

		function parseDuration(text) {
			text = String(text || '').toLowerCase().trim();

			if (text === '') {
				return null;
			}

			if (/^\d+$/.test(text)) {
				return parseInt(text, 10);
			}

			if (!/^(\d+[smhdw])+$/.test(text)) {
				return null;
			}

			var units = {s: 1, m: 60, h: 3600, d: 86400, w: 604800};
			var total = 0;
			var re = /(\d+)([smhdw])/g;
			var match;

			while ((match = re.exec(text)) !== null) {
				total += parseInt(match[1], 10) * units[match[2]];
			}

			return total;
		}

		function humanDuration(seconds) {
			if (seconds % 604800 === 0) {
				return (seconds / 604800) + 'w';
			}
			if (seconds % 86400 === 0) {
				return (seconds / 86400) + 'd';
			}
			if (seconds % 3600 === 0) {
				return (seconds / 3600) + 'h';
			}

			return Math.round(seconds / 60) + 'm';
		}

		function pad(n) {
			return (n < 10 ? '0' : '') + n;
		}

		function fmt(date) {
			return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate())
				+ ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes());
		}

		function isoDate(date) {
			return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
		}

		function banner(container, kind, message) {
			clear(container);
			container.appendChild(el('div', {class: 'ml-banner ml-banner-' + kind, text: message}));
		}

		/* ================================================================ */
		/* SCHEDULE PAGE                                                    */
		/* ================================================================ */

		function wireSchedule() {

		/* ---------------------------------------------------------------- */
		/* checkbox rows                                                    */
		/* ---------------------------------------------------------------- */

		function buildCheckrow(container, labels) {
			clear(container);

			labels.forEach(function (label, i) {
				var box = el('input', {type: 'checkbox', value: String(i + 1)});
				box.addEventListener('change', updatePreview);

				container.appendChild(el('label', {class: 'ml-checkbox'}, [box, el('span', {text: label})]));
			});

			var toggle = el('button', {type: 'button', class: 'ml-btn ml-btn-plain ml-mini', text: 'All'});

			toggle.addEventListener('click', function () {
				var boxes = all('input', container);
				var turn_on = boxes.some(function (b) {
					return !b.checked;
				});

				boxes.forEach(function (b) {
					b.checked = turn_on;
				});

				updatePreview();
			});

			container.appendChild(toggle);
		}

		function readCheckrow(container) {
			return all('input', container)
				.filter(function (box) {
					return box.checked;
				})
				.map(function (box) {
					return box.value;
				});
		}

		function writeCheckrow(container, csv) {
			var wanted = String(csv || '').split(',').filter(Boolean);

			all('input', container).forEach(function (box) {
				box.checked = wanted.indexOf(box.value) !== -1;
			});
		}

		buildCheckrow($('ml-dow'), DOW);
		buildCheckrow($('ml-months'), MONTHS);

		// Sensible defaults: Sunday, all months, a year of runway.
		all('input', $('ml-dow'))[6].checked = true;
		all('input', $('ml-months')).forEach(function (b) {
			b.checked = true;
		});

		$('ml-active-from').value = isoDate(new Date());
		$('ml-active-to').value = isoDate(new Date(Date.now() + 365 * 86400000));

		var input = $('ml-input');

		function countEntries() {
			// One line is one entry, whatever is inside it.
			var rows = input.value.split(/\r\n|\r|\n/).filter(function (line) {
				return line.trim() !== '';
			});

			$('ml-input-count').textContent = rows.length
				? rows.length + (rows.length === 1 ? ' row' : ' rows')
				: '';
		}

		function resetForm() {
			input.value = '';
			state.rows = [];
			state.hostids = [];
			state.editing = null;
			countEntries();
			$('ml-step-verify').classList.add('ml-hidden');
			$('ml-step-window').classList.add('ml-hidden');
			$('ml-editing').classList.add('ml-hidden');
			$('ml-step3-label').textContent = 'Set the window';
			clear($('ml-create-result'));
			input.focus();
		}

		input.addEventListener('input', countEntries);
		$('ml-clear').addEventListener('click', resetForm);
		$('ml-cancel-edit').addEventListener('click', resetForm);

		$('ml-file').addEventListener('change', function (event) {
			var file = event.target.files && event.target.files[0];

			if (!file) {
				return;
			}

			var reader = new FileReader();

			reader.onload = function () {
				input.value = (input.value ? input.value.replace(/\s*$/, '') + '\n' : '') + reader.result;
				countEntries();
				event.target.value = '';
				verify();
			};

			reader.readAsText(file);
		});

		$('ml-verify').addEventListener('click', function () {
			verify();
		});

		input.addEventListener('keydown', function (event) {
			if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
				event.preventDefault();
				verify();
			}
		});

		/* ---------------------------------------------------------------- */
		/* step 2: verify                                                   */
		/* ---------------------------------------------------------------- */

		function verify() {
			if (state.busy || input.value.trim() === '') {
				return Promise.resolve(null);
			}

			state.busy = true;
			$('ml-verify').disabled = true;
			$('ml-verify').textContent = 'Checking...';

			return post('maintwin.verify', {hosts: input.value})
				.then(function (data) {
					if (data.error) {
						$('ml-step-verify').classList.remove('ml-hidden');
						banner($('ml-summary'), 'bad', data.error);
						clear($('ml-results').tBodies[0]);
						$('ml-step-window').classList.add('ml-hidden');
						return null;
					}

					state.rows = data.rows;
					state.hostids = data.hostids;

					$('ml-step-verify').classList.remove('ml-hidden');
					renderSummary(data.counts);
					renderRows();

					$('ml-step-window').classList.toggle('ml-hidden', state.hostids.length === 0);
					updatePreview();

					return data;
				})
				.catch(function (err) {
					$('ml-step-verify').classList.remove('ml-hidden');
					banner($('ml-summary'), 'bad', err.message);
					return null;
				})
				.then(function (data) {
					state.busy = false;
					$('ml-verify').disabled = false;
					$('ml-verify').textContent = 'Verify';
					return data;
				});
		}

		function chip(kind, count, label) {
			if (!count) {
				return null;
			}

			return el('span', {class: 'ml-chip ml-chip-' + kind}, [
				el('b', {text: String(count)}),
				' ' + label
			]);
		}

		function renderSummary(counts) {
			var box = $('ml-summary');
			clear(box);

			[
				chip('good', counts.ok, 'will go into maintenance'),
				chip('bad', counts.notfound, 'no column matched'),
				chip('warn', counts.ambiguous, 'ambiguous'),
				chip('grey', counts.duplicate, 'duplicate row'),
				chip('grey', counts.header, 'header row'),
				chip('warn', counts.conflict, 'columns disagree'),
				chip('warn', counts.disabled, 'disabled in Zabbix'),
				chip('warn', counts.already, 'already in maintenance')
			].forEach(function (node) {
				if (node) {
					box.appendChild(node);
				}
			});

			if (!counts.ok) {
				box.appendChild(el('div', {
					class: 'ml-banner ml-banner-bad',
					text: 'Nothing resolved. Check the names, and check that your account has permission to these hosts.'
				}));
			}

			$('ml-copy-missing').classList.toggle('ml-hidden', !counts.notfound);
		}

		var STATUS_META = {
			ok: {icon: '\u2713', cls: 'ok', note: ''},
			notfound: {icon: '\u2717', cls: 'bad', note: 'No column matched a host you can see'},
			ambiguous: {icon: '!', cls: 'warn', note: 'Matches more than one host'},
			duplicate: {icon: '\u21BA', cls: 'grey', note: 'Same host as an earlier row'},
			header: {icon: '\u2014', cls: 'grey', note: 'Looks like a column header, skipped'}
		};

		function renderRows() {
			var tbody = $('ml-results').tBodies[0];
			var only_problems = $('ml-only-problems').checked;

			clear(tbody);

			state.rows.forEach(function (row) {
				var meta = STATUS_META[row.status] || STATUS_META.notfound;
				var interesting = row.status !== 'ok' || !row.enabled || row.in_maintenance
					|| (row.conflicts || []).length > 0;

				if (only_problems && !interesting) {
					return;
				}

				var note = meta.note;

				var matched = '';

				if (row.status === 'ambiguous') {
					note = 'Matches: ' + row.candidates.join(', ');
					matched = 'col ' + row.column;
				}
				else if (row.status === 'duplicate') {
					note = 'Same host as "' + row.duplicate_of + '"';
					matched = 'col ' + row.column + ' \u00B7 ' + row.matched_on;
				}
				else if (row.status === 'ok') {
					var notes = [];

					// Column order decides, so say when a later column would
					// have decided differently. That is a stale CSV, not a
					// tie, and it is worth seeing before the window goes in.
					(row.conflicts || []).forEach(function (c) {
						notes.push(c);
					});

					if (!row.enabled) {
						notes.push('host is disabled');
					}
					if (row.in_maintenance) {
						notes.push('already in maintenance');
					}

					note = notes.join('; ');
					matched = row.columns > 1
						? 'col ' + row.column + ' \u00B7 ' + row.matched_on
						: row.matched_on;
				}

				if ((row.conflicts || []).length && meta.cls === 'ok') {
					meta = {icon: meta.icon, cls: 'warn'};
				}

				tbody.appendChild(el('tr', {class: 'ml-row-' + meta.cls}, [
					el('td', {class: 'ml-col-status'}, [
						el('span', {class: 'ml-dot ml-dot-' + meta.cls, text: meta.icon})
					]),
					el('td', {class: 'ml-mono', text: row.token}),
					el('td', {class: 'ml-muted', text: matched}),
					el('td', {text: row.status === 'ok' ? (row.name || row.host) : ''}),
					el('td', {class: 'ml-mono ml-muted', text: row.status === 'ok' ? (row.ip || '') : ''}),
					el('td', {class: 'ml-muted', text: note})
				]));
			});
		}

		$('ml-only-problems').addEventListener('change', renderRows);

		$('ml-copy-missing').addEventListener('click', function () {
			var missing = state.rows
				.filter(function (row) {
					return row.status === 'notfound';
				})
				.map(function (row) {
					return row.token;
				})
				.join('\n');

			if (navigator.clipboard) {
				navigator.clipboard.writeText(missing);
				$('ml-copy-missing').textContent = 'Copied';
				setTimeout(function () {
					$('ml-copy-missing').textContent = 'Copy unmatched rows';
				}, 1500);
			}
		});

		/* ---------------------------------------------------------------- */
		/* step 3: schedule                                                 */
		/* ---------------------------------------------------------------- */

		function applySchedType() {
			var sched = state.sched;
			var recurring = sched !== 'onetime';

			all('.ml-sched-onetime').forEach(function (node) {
				node.classList.toggle('ml-hidden', recurring);
			});

			all('.ml-sched-recurring').forEach(function (node) {
				node.classList.toggle('ml-hidden', !recurring);
			});

			all('.ml-sched-daily').forEach(function (node) {
				node.classList.toggle('ml-hidden', sched !== 'daily' && sched !== 'weekly');
			});

			all('.ml-sched-monthly').forEach(function (node) {
				node.classList.toggle('ml-hidden', sched !== 'monthly');
			});

			// The day-of-week checkboxes serve weekly, and monthly in "day of
			// week" mode. One control, two owners.
			var want_dow = sched === 'weekly'
				|| (sched === 'monthly' && state.monthly_mode === 'dow');

			all('.ml-dow-block').forEach(function (node) {
				node.classList.toggle('ml-hidden', !want_dow);
			});

			$('ml-every-unit').textContent = sched === 'weekly' ? 'week(s)' : 'day(s)';
			$('ml-day').classList.toggle('ml-hidden', state.monthly_mode !== 'day');
			$('ml-week').classList.toggle('ml-hidden', state.monthly_mode !== 'dow');

			updatePreview();
		}

		$('ml-sched').addEventListener('change', function () {
			state.sched = $('ml-sched').value;
			applySchedType();
		});

		all('.ml-seg-btn', $('ml-monthly-mode')).forEach(function (btn) {
			btn.addEventListener('click', function () {
				state.monthly_mode = btn.getAttribute('data-mmode');

				all('.ml-seg-btn', $('ml-monthly-mode')).forEach(function (other) {
					other.classList.toggle('ml-seg-active', other === btn);
				});

				applySchedType();
			});
		});

		all('.ml-seg-btn', $('ml-start-mode')).forEach(function (btn) {
			btn.addEventListener('click', function () {
				state.start_mode = btn.getAttribute('data-mode');

				all('.ml-seg-btn', $('ml-start-mode')).forEach(function (other) {
					other.classList.toggle('ml-seg-active', other === btn);
				});

				var picker = $('ml-start-at');
				picker.classList.toggle('ml-hidden', state.start_mode !== 'at');

				if (state.start_mode === 'at' && !picker.value) {
					var soon = new Date(Date.now() + 3600000);
					soon.setSeconds(0, 0);
					picker.value = fmt(soon).replace(' ', 'T');
				}

				updatePreview();
			});
		});

		all('.ml-seg-btn', $('ml-collect')).forEach(function (btn) {
			btn.addEventListener('click', function () {
				setCollect(parseInt(btn.getAttribute('data-collect'), 10));
				updatePreview();
			});
		});

		$('ml-duration').addEventListener('change', function () {
			var custom = $('ml-duration').value === 'custom';

			$('ml-duration-custom').classList.toggle('ml-hidden', !custom);

			if (custom) {
				$('ml-duration-custom').focus();
			}

			updatePreview();
		});

		['ml-duration-custom', 'ml-start-at', 'ml-start-time', 'ml-every', 'ml-day',
			'ml-week', 'ml-active-from', 'ml-active-to'
		].forEach(function (id) {
			$(id).addEventListener('input', updatePreview);
			$(id).addEventListener('change', updatePreview);
		});

		function currentDuration() {
			var value = $('ml-duration').value;

			return parseDuration(value === 'custom' ? $('ml-duration-custom').value : value);
		}

		function setCollect(value) {
			state.collect = value ? 1 : 0;

			all('.ml-seg-btn', $('ml-collect')).forEach(function (btn) {
				btn.classList.toggle('ml-seg-active',
					parseInt(btn.getAttribute('data-collect'), 10) === state.collect);
			});
		}

		/** Everything the Create and Update controllers need. */
		function readSchedule() {
			return {
				sched: state.sched,
				duration: String(currentDuration()),
				collect: String(state.collect),
				start_mode: state.start_mode,
				start_at: state.start_mode === 'at' ? $('ml-start-at').value : '',
				start_time: $('ml-start-time').value,
				// "every" is overloaded exactly as it is in the API: repeat
				// interval for daily/weekly, week-of-month for monthly-by-dow.
				every: (state.sched === 'monthly' && state.monthly_mode === 'dow')
					? $('ml-week').value
					: $('ml-every').value,
				dayofweek: readCheckrow($('ml-dow')).join(','),
				months: readCheckrow($('ml-months')).join(','),
				monthly_mode: state.monthly_mode,
				day: $('ml-day').value,
				active_from: $('ml-active-from').value,
				active_to: $('ml-active-to').value
			};
		}

		/** Inverse, for loading an existing window back into the form. */
		function writeSchedule(form) {
			state.sched = form.sched;
			state.monthly_mode = form.monthly_mode;
			state.start_mode = form.start_mode;

			$('ml-sched').value = form.sched;
			$('ml-start-at').value = form.start_at;
			$('ml-start-time').value = form.start_time;
			$('ml-day').value = form.day;
			$('ml-active-from').value = form.active_from;
			$('ml-active-to').value = form.active_to;

			if (form.sched === 'monthly' && form.monthly_mode === 'dow') {
				$('ml-week').value = form.every;
			}
			else {
				$('ml-every').value = form.every;
			}

			writeCheckrow($('ml-dow'), form.dayofweek);
			writeCheckrow($('ml-months'), form.months);

			// Durations come back as raw seconds. Snap to a preset where one
			// matches so the operator sees "2 hours" rather than "7200".
			var seconds = parseInt(form.duration, 10);
			var preset = all('option', $('ml-duration')).filter(function (opt) {
				return opt.value !== 'custom' && parseDuration(opt.value) === seconds;
			})[0];

			if (preset) {
				$('ml-duration').value = preset.value;
				$('ml-duration-custom').classList.add('ml-hidden');
			}
			else {
				$('ml-duration').value = 'custom';
				$('ml-duration-custom').value = humanDuration(seconds);
				$('ml-duration-custom').classList.remove('ml-hidden');
			}

			all('.ml-seg-btn', $('ml-start-mode')).forEach(function (btn) {
				btn.classList.toggle('ml-seg-active', btn.getAttribute('data-mode') === form.start_mode);
			});

			$('ml-start-at').classList.toggle('ml-hidden', form.start_mode !== 'at');

			all('.ml-seg-btn', $('ml-monthly-mode')).forEach(function (btn) {
				btn.classList.toggle('ml-seg-active', btn.getAttribute('data-mmode') === form.monthly_mode);
			});

			applySchedType();
		}

		/** Mirror of describeTimeperiod() in PHP, for the live preview. */
		function describeSchedule(seconds) {
			var period = humanDuration(seconds);
			var clock = $('ml-start-time').value || '00:00';
			var every = parseInt($('ml-every').value, 10) || 1;
			var days = readCheckrow($('ml-dow')).map(function (n) {
				return DOW[parseInt(n, 10) - 1];
			});

			if (state.sched === 'daily') {
				return (every > 1 ? 'Every ' + every + ' days' : 'Daily')
					+ ' at ' + clock + ' for ' + period;
			}

			if (state.sched === 'weekly') {
				if (!days.length) {
					return null;
				}

				return (every > 1 ? 'Every ' + every + ' weeks' : 'Weekly')
					+ ' on ' + days.join(', ') + ' at ' + clock + ' for ' + period;
			}

			if (state.sched === 'monthly') {
				var months = readCheckrow($('ml-months'));

				if (!months.length) {
					return null;
				}

				var when;

				if (state.monthly_mode === 'day') {
					when = 'day ' + ($('ml-day').value || '1');
				}
				else {
					if (!days.length) {
						return null;
					}

					when = (WEEKS[$('ml-week').value] || 'first') + ' ' + days.join(', ');
				}

				var month_text = months.length === 12
					? 'all months'
					: months.map(function (n) {
						return MONTHS[parseInt(n, 10) - 1];
					}).join(', ');

				return 'Monthly (' + month_text + '), ' + when + ' at ' + clock + ' for ' + period;
			}

			return null;
		}

		function updatePreview() {
			var box = $('ml-preview');
			clear(box);

			$('ml-collect-hint').textContent = state.collect
				? 'Metrics keep flowing, alerts are suppressed. Use this unless the box is going dark.'
				: 'Polling stops entirely. Nothing is collected for the window, so your graphs will have a hole.';

			function fail(message) {
				box.appendChild(el('span', {class: 'ml-preview-bad', text: message}));
				$('ml-create').disabled = true;
				return null;
			}

			var seconds = currentDuration();

			if (seconds === null) {
				return fail('Duration is not readable. Try 30m, 2h, 1d or 1h30m.');
			}

			if (seconds < 300) {
				return fail('Zabbix will not take a window shorter than 5 minutes.');
			}

			if (seconds > CFG.max_duration) {
				return fail('Longer than the configured maximum of ' + humanDuration(CFG.max_duration) + '.');
			}

			var when;

			if (state.sched === 'onetime') {
				var start;

				if (state.start_mode === 'at') {
					if (!$('ml-start-at').value) {
						return fail('Pick a start time.');
					}

					start = new Date($('ml-start-at').value);

					if (isNaN(start.getTime())) {
						return fail('That start time is not valid.');
					}
				}
				else {
					start = new Date();
				}

				var end = new Date(start.getTime() + seconds * 1000);

				when = (state.start_mode === 'at' ? fmt(start) : 'now') + '  \u2192  ' + fmt(end)
					+ '  (' + humanDuration(seconds) + ')';
			}
			else {
				when = describeSchedule(seconds);

				if (when === null) {
					return fail('Pick at least one day, and at least one month for a monthly window.');
				}

				if (!$('ml-active-from').value || !$('ml-active-to').value) {
					return fail('Set the date range the recurrence runs between.');
				}

				if ($('ml-active-to').value < $('ml-active-from').value) {
					return fail('The end of the range has to be after the start.');
				}

				when += '  \u00B7  ' + $('ml-active-from').value + ' to ' + $('ml-active-to').value;
			}

			$('ml-create').disabled = false;
			$('ml-create').textContent = state.editing
				? 'Save changes'
				: (state.sched === 'onetime' && state.start_mode === 'now'
					? 'Put them in maintenance'
					: 'Schedule the window');

			box.appendChild(el('span', {class: 'ml-preview-count'}, [
				el('b', {text: String(state.hostids.length)}),
				state.hostids.length === 1 ? ' host' : ' hosts'
			]));
			box.appendChild(el('span', {
				class: 'ml-preview-when',
				text: when + '  \u00B7  ' + (state.collect ? 'data collected' : 'no data')
			}));

			return null;
		}

		/* ---------------------------------------------------------------- */
		/* submit                                                           */
		/* ---------------------------------------------------------------- */

		$('ml-create').addEventListener('click', function () {
			if (state.busy || !state.hostids.length || currentDuration() === null) {
				return;
			}

			var payload = readSchedule();

			payload.hostids = state.hostids;
			payload.window_name = $('ml-name').value;
			payload.ticket = $('ml-ticket').value;
			payload.note = $('ml-note').value;

			var action = 'maintwin.create';

			if (state.editing) {
				action = 'maintwin.update';
				payload.op = 'edit';
				payload.maintenanceid = state.editing.maintenanceid;
			}

			state.busy = true;
			$('ml-create').disabled = true;
			$('ml-create').textContent = 'Working...';

			post(action, payload)
				.then(function (data) {
					if (data.error) {
						banner($('ml-create-result'), 'bad', data.error);
						return;
					}

					var lines = [data.message];

					if (data.dropped > 0) {
						lines.push(data.dropped + ' host(s) were dropped because your account cannot read them.');
					}

					var note = el('div', {class: 'ml-banner ml-banner-good'}, [
						el('div', {class: 'ml-banner-head', text: lines.join(' ')}),
						el('div', {class: 'ml-mono ml-muted', text: data.name}),
						el('div', {class: 'ml-banner-foot'}, [
							el('a', {class: 'ml-btn ml-btn-link', href: CFG.windows_url, text: 'View it in flight'})
						])
					]);

					// resetForm clears the result area, so put the receipt back
					// afterwards. The operator needs to see the window landed.
					resetForm();
					$('ml-create-result').appendChild(note);
				})
				.catch(function (err) {
					banner($('ml-create-result'), 'bad', err.message);
				})
				.then(function () {
					state.busy = false;
					$('ml-create').disabled = false;
					updatePreview();
				});
		});

		/* ---------------------------------------------------------------- */
		/* edit an existing window                                          */
		/* ---------------------------------------------------------------- */

		function startEdit(maintenanceid) {
			return post('maintwin.update', {op: 'load', maintenanceid: maintenanceid})
				.then(function (data) {
					if (data.error) {
						banner($('ml-create-result'), 'bad', data.error);
						$('ml-create-result').scrollIntoView({behavior: 'smooth', block: 'nearest'});
						return null;
					}

					state.editing = {maintenanceid: data.maintenanceid, name: data.name};

					$('ml-editing').classList.remove('ml-hidden');
					$('ml-editing-name').textContent = data.name;
					$('ml-step3-label').textContent = 'Change the window';

					$('ml-name').value = data.label;
					$('ml-ticket').value = data.ticket;
					$('ml-note').value = data.note;
					setCollect(data.collect);
					writeSchedule(data.form);

					input.value = data.hosts.join('\n');
					countEntries();

					return verify().then(function () {
						if (data.hidden_count) {
							$('ml-summary').appendChild(el('div', {
								class: 'ml-banner',
								text: data.hidden_count + ' host(s) in this window are outside your permissions. '
									+ 'They stay in it whatever you do here.'
							}));
						}

						$('ml-step-window').scrollIntoView({behavior: 'smooth', block: 'nearest'});
					});
				})
				.catch(function (err) {
					banner($('ml-create-result'), 'bad', err.message);
				});
		}

		if (CFG.blocked) {
			$('ml-verify').disabled = true;
			$('ml-create').disabled = true;
		}

		applySchedType();
		countEntries();

		// Arrived from the Edit button on the In flight page.
		if (CFG.edit) {
			startEdit(CFG.edit);
		}
		else {
			input.focus();
		}

		} /* end wireSchedule */

		/* ================================================================ */
		/* IN FLIGHT PAGE                                                   */
		/* ================================================================ */

		function wireWindows() {

		$('ml-refresh').addEventListener('click', loadActive);
		$('ml-show-expired').addEventListener('change', loadActive);

		function loadActive() {
			var list = $('ml-active-list');

			return post('maintwin.list', {show_expired: $('ml-show-expired').checked ? '1' : '0'})
				.then(function (data) {
					if (data.error) {
						banner(list, 'bad', data.error);
						return;
					}

					renderActive(data.rows);
				})
				.catch(function (err) {
					banner(list, 'bad', err.message);
				});
		}

		function extendMenu(row, anchor) {
			var open = anchor.parentNode.querySelector('.ml-menu');

			if (open) {
				open.parentNode.removeChild(open);
				return;
			}

			var menu = el('div', {class: 'ml-menu'});

			['15m', '30m', '1h', '2h', '4h', '8h'].forEach(function (delta) {
				var item = el('button', {type: 'button', class: 'ml-menu-item', text: '+' + delta});

				item.addEventListener('click', function () {
					if (menu.parentNode) {
						menu.parentNode.removeChild(menu);
					}

					post('maintwin.update', {
						op: 'extend',
						maintenanceid: row.maintenanceid,
						delta: delta
					})
						.then(function (data) {
							if (data.error) {
								window.alert(data.error);
								return;
							}

							loadActive();
						})
						.catch(function (err) {
							window.alert(err.message);
						});
				});

				menu.appendChild(item);
			});

			anchor.parentNode.appendChild(menu);
		}

		function renderActive(rows) {
			var list = $('ml-active-list');
			clear(list);

			if (!rows.length) {
				list.appendChild(el('p', {
					class: 'ml-muted',
					text: 'Nothing here. Windows created on this page show up in this list.'
				}));
				return;
			}

			rows.forEach(function (row) {
				var hosts = el('div', {class: 'ml-card-hosts'});

				row.hosts.forEach(function (name) {
					hosts.appendChild(el('span', {class: 'ml-hostchip', text: name}));
				});

				if (row.truncated) {
					hosts.appendChild(el('span', {class: 'ml-hostchip ml-muted', text: '...'}));
				}

				if (row.hidden_count) {
					hosts.appendChild(el('span', {
						class: 'ml-hostchip ml-muted',
						text: '+' + row.hidden_count + ' you cannot see'
					}));
				}

				var actions = el('div', {class: 'ml-card-actions'});

				if (row.state !== 'expired' && row.onetime) {
					var extend_btn = el('button', {type: 'button', class: 'ml-btn', text: 'Extend \u25BE'});
					extend_btn.addEventListener('click', function () {
						extendMenu(row, extend_btn);
					});
					actions.appendChild(extend_btn);
				}

				if (row.editable) {
					// Editing happens on the Schedule page, which owns the
					// form. Send them there with the window id rather than
					// duplicating the whole form on this page.
					actions.appendChild(el('a', {
						class: 'ml-btn ml-btn-link',
						href: CFG.schedule_url + '&edit=' + encodeURIComponent(row.maintenanceid),
						text: 'Edit'
					}));
				}

				var end_btn = el('button', {
					type: 'button',
					class: 'ml-btn ml-btn-danger',
					text: row.state === 'scheduled'
						? 'Cancel'
						: (row.onetime ? 'End now' : 'Delete')
				});

				end_btn.addEventListener('click', function () {
					var warning = row.onetime
						? row.host_count + ' host(s) will start alerting again within a minute.'
						: 'This is a recurring window. Deleting it cancels every future occurrence.';

					if (!window.confirm('End "' + row.name + '"?\n\n' + warning)) {
						return;
					}

					end_btn.disabled = true;
					end_btn.textContent = 'Ending...';

					post('maintwin.end', {maintenanceid: row.maintenanceid})
						.then(function (data) {
							if (data.error) {
								end_btn.disabled = false;
								end_btn.textContent = 'End now';
								window.alert(data.error);
								return;
							}

							loadActive();
						})
						.catch(function (err) {
							end_btn.disabled = false;
							end_btn.textContent = 'End now';
							window.alert(err.message);
						});
				});

				actions.appendChild(end_btn);

				var meta = [row.schedule];

				if (row.created_by) {
					meta.push('by ' + row.created_by);
				}

				if (row.ticket) {
					meta.push(row.ticket);
				}

				meta.push(row.collect ? 'data collected' : 'no data');

				list.appendChild(el('div', {class: 'ml-card ml-card-' + row.state}, [
					el('div', {class: 'ml-card-top'}, [
						el('div', {class: 'ml-card-title'}, [
							el('span', {class: 'ml-pill ml-pill-' + row.state, text: row.state}),
							el('span', {text: row.name})
						]),
						actions
					]),
					el('div', {class: 'ml-card-meta', text: meta.join('  \u00B7  ')}),
					el('div', {class: 'ml-card-meta ml-muted', text: row.since_text + ' \u2192 ' + row.till_text}),
					el('div', {class: 'ml-card-count', text: row.host_count + (row.host_count === 1 ? ' host' : ' hosts')}),
					hosts
				]));
			});
		}

		if (!CFG.blocked) {
			loadActive();
		}

		} /* end wireWindows */

		/* ---------------------------------------------------------------- */

		if (CFG.page === 'windows') {
			wireWindows();
		}
		else {
			wireSchedule();
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	}
	else {
		boot();
	}
})();
