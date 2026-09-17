<?php declare(strict_types = 1);

namespace Modules\MaintLoader\Actions;

/**
 * Builds and describes Zabbix maintenance timeperiods.
 *
 * Kept apart from Base because this is the only genuinely fiddly part of the
 * module. Zabbix timeperiod objects overload their fields depending on
 * timeperiod_type, and getting the overloading wrong is the failure mode where
 * the API returns success and the host never enters maintenance:
 *
 *   one time (0)  start_date = absolute epoch. start_time is IGNORED.
 *   daily   (2)   start_time = seconds past midnight, every = every N days.
 *   weekly  (3)   start_time, every = every N weeks, dayofweek = bitmask.
 *   monthly (4)   start_time, month = bitmask, then EITHER
 *                   day = 1..31, OR
 *                   dayofweek = bitmask AND every = 1..5 (5 meaning "last").
 *                 Sending both day and dayofweek is how you get a window that
 *                 fires on days you did not ask for.
 *
 * active_since/active_till are the outer envelope. A recurrence only fires
 * inside it, which is why a recurring window needs a date range and a one-time
 * window does not.
 */
trait Schedule {

	private const DOW_LABELS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

	private const MONTH_LABELS = [
		'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'
	];

	private const WEEK_LABELS = [1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', 5 => 'last'];

	/** Zabbix rejects maintenance periods shorter than five minutes. */
	private const MIN_DURATION = 300;

	/**
	 * Validation rules for every schedule field. Shared by Create and Update
	 * so the two can never drift apart.
	 *
	 * Bitmask inputs arrive as comma-joined strings ("1,3,5") rather than
	 * arrays. One less validator to trust, and it round-trips cleanly.
	 */
	protected static function scheduleRules(): array {
		return [
			'sched' => 'required|in onetime,daily,weekly,monthly',
			'duration' => 'required|string',
			'collect' => 'in 0,1',

			// one time
			'start_mode' => 'in now,at',
			'start_at' => 'string',

			// recurring
			'start_time' => 'string',
			'every' => 'string',
			'dayofweek' => 'string',
			'months' => 'string',
			'monthly_mode' => 'in day,dow',
			'day' => 'string',
			'active_from' => 'string',
			'active_to' => 'string'
		];
	}

	/**
	 * @throws \Exception with a message meant for the operator.
	 *
	 * @return array ['timeperiod' => [...], 'active_since' => int, 'active_till' => int]
	 */
	protected function buildSchedule(): array {
		$duration = $this->parseDuration((string) $this->getInput('duration', ''));

		if ($duration === null) {
			throw new \Exception(_('Could not read the duration. Use 30m, 2h, 1d, or 1h30m.'));
		}

		if ($duration < self::MIN_DURATION) {
			throw new \Exception(_('Zabbix will not accept a maintenance period shorter than 5 minutes.'));
		}

		$max_duration = (int) $this->config('max_duration', 604800);

		if ($duration > $max_duration) {
			throw new \Exception(sprintf(
				_('That window is longer than the configured maximum of %s.'),
				$this->humanDuration($max_duration)
			));
		}

		$sched = (string) $this->getInput('sched', 'onetime');

		return $sched === 'onetime'
			? $this->buildOneTime($duration)
			: $this->buildRecurring($sched, $duration);
	}

	private function buildOneTime(int $duration): array {
		if ($this->getInput('start_mode', 'now') === 'now') {
			$start = time();
		}
		else {
			$raw = trim((string) $this->getInput('start_at', ''));

			if ($raw === '') {
				throw new \Exception(_('Pick a start time, or switch to "Now".'));
			}

			// Zabbix has already set the PHP timezone to the logged-in user's
			// profile timezone for this request, so a naive "2026-09-18T22:00"
			// is read in the timezone the operator is looking at.
			$start = strtotime($raw);

			if ($start === false) {
				throw new \Exception(_('Could not read that start time.'));
			}

			if ($start < time() - 300) {
				throw new \Exception(_('That start time is in the past.'));
			}

			if ($start > time() + 31536000) {
				throw new \Exception(_('That start time is more than a year out.'));
			}
		}

		$start -= $start % 60;

		return [
			'timeperiod' => [
				'timeperiod_type' => TIMEPERIOD_TYPE_ONETIME,
				'start_date' => $start,
				'period' => $duration
			],
			'active_since' => $start,
			'active_till' => $start + $duration
		];
	}

	private function buildRecurring(string $sched, int $duration): array {
		$start_time = $this->parseClock((string) $this->getInput('start_time', ''));

		if ($start_time === null) {
			throw new \Exception(_('Pick a time of day for the window to start, as HH:MM.'));
		}

		$every = (int) $this->getInput('every', '1');

		$timeperiod = [
			'start_time' => $start_time,
			'period' => $duration
		];

		if ($sched === 'daily') {
			if ($every < 1 || $every > 999) {
				throw new \Exception(_('"Every N days" has to be between 1 and 999.'));
			}

			$timeperiod['timeperiod_type'] = TIMEPERIOD_TYPE_DAILY;
			$timeperiod['every'] = $every;
		}
		elseif ($sched === 'weekly') {
			if ($every < 1 || $every > 99) {
				throw new \Exception(_('"Every N weeks" has to be between 1 and 99.'));
			}

			$dayofweek = $this->parseBitmask((string) $this->getInput('dayofweek', ''), 7);

			if ($dayofweek === 0) {
				throw new \Exception(_('Pick at least one day of the week.'));
			}

			$timeperiod['timeperiod_type'] = TIMEPERIOD_TYPE_WEEKLY;
			$timeperiod['every'] = $every;
			$timeperiod['dayofweek'] = $dayofweek;
		}
		else {
			$month = $this->parseBitmask((string) $this->getInput('months', ''), 12);

			if ($month === 0) {
				throw new \Exception(_('Pick at least one month.'));
			}

			$timeperiod['timeperiod_type'] = TIMEPERIOD_TYPE_MONTHLY;
			$timeperiod['month'] = $month;

			if ($this->getInput('monthly_mode', 'day') === 'day') {
				$day = (int) $this->getInput('day', '1');

				if ($day < 1 || $day > 31) {
					throw new \Exception(_('Day of month has to be between 1 and 31.'));
				}

				// Day-of-month mode. Leaving dayofweek and every unset is what
				// tells Zabbix which of the two monthly modes this is.
				$timeperiod['day'] = $day;
			}
			else {
				if ($every < 1 || $every > 5) {
					throw new \Exception(_('Pick first, second, third, fourth or last.'));
				}

				$dayofweek = $this->parseBitmask((string) $this->getInput('dayofweek', ''), 7);

				if ($dayofweek === 0) {
					throw new \Exception(_('Pick at least one day of the week.'));
				}

				$timeperiod['every'] = $every;
				$timeperiod['dayofweek'] = $dayofweek;
			}
		}

		// Outer envelope.
		$from = $this->parseDate((string) $this->getInput('active_from', ''));
		$to = $this->parseDate((string) $this->getInput('active_to', ''));

		if ($from === null || $to === null) {
			throw new \Exception(_('Set the date range the recurrence runs between.'));
		}

		// active_to is inclusive of that whole day.
		$to = strtotime('+1 day', $to);

		if ($to <= $from) {
			throw new \Exception(_('The end of the range has to be after the start.'));
		}

		if ($to - $from > 315360000) {
			throw new \Exception(_('Keep the range under ten years.'));
		}

		return [
			'timeperiod' => $timeperiod,
			'active_since' => $from,
			'active_till' => $to
		];
	}

	/*
	 * ----------------------------------------------------------------------
	 * Parsing helpers
	 * ----------------------------------------------------------------------
	 */

	/** "02:30" -> 9000 seconds past midnight. */
	private function parseClock(string $text): ?int {
		if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($text), $m)) {
			return null;
		}

		$hours = (int) $m[1];
		$minutes = (int) $m[2];

		if ($hours > 23 || $minutes > 59) {
			return null;
		}

		return $hours * 3600 + $minutes * 60;
	}

	/** "YYYY-MM-DD" -> epoch at local midnight. */
	private function parseDate(string $text): ?int {
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($text), $m)) {
			return null;
		}

		if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
			return null;
		}

		return mktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]) ?: null;
	}

	/** "1,3,5" with max 7 -> bit 0 | bit 2 | bit 4 = 21. */
	private function parseBitmask(string $text, int $max): int {
		$mask = 0;

		foreach (explode(',', $text) as $part) {
			$n = (int) trim($part);

			if ($n >= 1 && $n <= $max) {
				$mask |= 1 << ($n - 1);
			}
		}

		return $mask;
	}

	/** Inverse of parseBitmask, for loading a window back into the form. */
	private function unpackBitmask(int $mask, int $max): array {
		$out = [];

		for ($n = 1; $n <= $max; $n++) {
			if ($mask & (1 << ($n - 1))) {
				$out[] = $n;
			}
		}

		return $out;
	}

	protected function humanDuration(int $seconds): string {
		if ($seconds > 0 && $seconds % 604800 === 0) {
			return sprintf(_n('%d week', '%d weeks', (int) ($seconds / 604800)), $seconds / 604800);
		}

		if ($seconds > 0 && $seconds % 86400 === 0) {
			return sprintf(_n('%d day', '%d days', (int) ($seconds / 86400)), $seconds / 86400);
		}

		if ($seconds > 0 && $seconds % 3600 === 0) {
			return sprintf(_n('%d hour', '%d hours', (int) ($seconds / 3600)), $seconds / 3600);
		}

		return sprintf(_n('%d minute', '%d minutes', (int) ($seconds / 60)), (int) ($seconds / 60));
	}

	/*
	 * ----------------------------------------------------------------------
	 * Description and round-trip
	 * ----------------------------------------------------------------------
	 */

	/** One-line human summary of a timeperiod, for the window cards. */
	protected function describeTimeperiod(array $tp): string {
		$type = (int) ($tp['timeperiod_type'] ?? 0);
		$period = $this->humanDuration((int) ($tp['period'] ?? 0));
		$clock = $this->clockText((int) ($tp['start_time'] ?? 0));
		$every = (int) ($tp['every'] ?? 1);

		if ($type == TIMEPERIOD_TYPE_DAILY) {
			$cadence = $every > 1 ? sprintf(_('every %d days'), $every) : _('daily');

			return sprintf('%s at %s for %s', ucfirst($cadence), $clock, $period);
		}

		if ($type == TIMEPERIOD_TYPE_WEEKLY) {
			$days = $this->dowText((int) ($tp['dayofweek'] ?? 0));
			$cadence = $every > 1 ? sprintf(_('every %d weeks'), $every) : _('weekly');

			return sprintf('%s on %s at %s for %s', ucfirst($cadence), $days, $clock, $period);
		}

		if ($type == TIMEPERIOD_TYPE_MONTHLY) {
			$months = $this->monthText((int) ($tp['month'] ?? 0));
			$dayofweek = (int) ($tp['dayofweek'] ?? 0);

			$when = $dayofweek
				? sprintf('%s %s', self::WEEK_LABELS[$every] ?? 'first', $this->dowText($dayofweek))
				: sprintf(_('day %d'), (int) ($tp['day'] ?? 1));

			return sprintf('Monthly (%s), %s at %s for %s', $months, $when, $clock, $period);
		}

		return sprintf('One time, %s for %s', date('Y-m-d H:i', (int) ($tp['start_date'] ?? 0)), $period);
	}

	private function clockText(int $seconds): string {
		return sprintf('%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
	}

	private function dowText(int $mask): string {
		$out = [];

		foreach ($this->unpackBitmask($mask, 7) as $n) {
			$out[] = self::DOW_LABELS[$n - 1];
		}

		return $out ? implode(', ', $out) : '-';
	}

	private function monthText(int $mask): string {
		$months = $this->unpackBitmask($mask, 12);

		if (count($months) === 12) {
			return _('all months');
		}

		$out = [];

		foreach ($months as $n) {
			$out[] = self::MONTH_LABELS[$n - 1];
		}

		return $out ? implode(', ', $out) : '-';
	}

	/**
	 * Turn a stored timeperiod back into the flat shape the form posts, so a
	 * window can be loaded into the editor and saved again without drift.
	 */
	protected function scheduleToForm(array $tp, int $active_since, int $active_till): array {
		$type = (int) ($tp['timeperiod_type'] ?? 0);

		$form = [
			'duration' => (string) (int) ($tp['period'] ?? 3600),
			'start_time' => $this->clockText((int) ($tp['start_time'] ?? 0)),
			'every' => (string) (int) ($tp['every'] ?? 1),
			'dayofweek' => implode(',', $this->unpackBitmask((int) ($tp['dayofweek'] ?? 0), 7)),
			'months' => implode(',', $this->unpackBitmask((int) ($tp['month'] ?? 0), 12)),
			'monthly_mode' => ((int) ($tp['dayofweek'] ?? 0)) ? 'dow' : 'day',
			'day' => (string) (int) ($tp['day'] ?? 1),
			'active_from' => date('Y-m-d', $active_since),
			// active_till is exclusive midnight, so step back a day for display.
			'active_to' => date('Y-m-d', max($active_since, $active_till - 86400)),
			'start_mode' => 'at',
			'start_at' => date('Y-m-d\TH:i', (int) ($tp['start_date'] ?? $active_since))
		];

		$form['sched'] = [
			TIMEPERIOD_TYPE_DAILY => 'daily',
			TIMEPERIOD_TYPE_WEEKLY => 'weekly',
			TIMEPERIOD_TYPE_MONTHLY => 'monthly'
		][$type] ?? 'onetime';

		return $form;
	}
}
