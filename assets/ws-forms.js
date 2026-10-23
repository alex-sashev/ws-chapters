/* ws-chapters forms: the chapter list only shows chapters in the chosen country. */
(function () {
	'use strict';
	document.querySelectorAll('[data-ws-chapter-filter]').forEach(function (form) {
		var country = form.querySelector('[data-ws-country]');
		var chapter = form.querySelector('[data-ws-chapter]');
		if (!country || !chapter) return;
		var needCountry = form.hasAttribute('data-ws-require-country');
		var groups = Array.prototype.slice.call(chapter.querySelectorAll('optgroup'));

		function apply(e) {
			var c = country.value;
			groups.forEach(function (g) {
				var show = !c || g.getAttribute('data-country') === c;
				g.hidden = !show;
				g.disabled = !show;
			});
			var opt = chapter.options[chapter.selectedIndex];
			if (opt && opt.value && c && opt.getAttribute('data-country') !== c) chapter.value = '';
			if (needCountry) chapter.disabled = !c;
			// One chapter in the country: choose it straight away, but only when the visitor picks the country
			// (a country pre-selected by a link leaves the chapter for the applicant to choose).
			if (e && c && !chapter.value) {
				var visible = chapter.querySelectorAll('optgroup[data-country="' + c + '"] option');
				if (visible.length === 1) chapter.value = visible[0].value;
			}
		}
		country.addEventListener('change', apply);
		chapter.addEventListener('change', function () {
			var opt = chapter.options[chapter.selectedIndex];
			if (opt && opt.value && !country.value) { country.value = opt.getAttribute('data-country'); apply(); }
		});
		form.addEventListener('submit', function () { chapter.disabled = false; });
		apply();
	});
})();

/*
 * Check the form in the browser before sending: a missing or wrong field is pointed out on the spot, without
 * sending the page. (A server error reloads the page, and browsers never refill a file box, so attached photos
 * would be lost.) The server still checks everything again.
 */
(function () {
	'use strict';
	document.querySelectorAll('form.ws-form[novalidate]').forEach(function (form) {
		var start = form.querySelector('[name="ws_start_date"]');
		var end = form.querySelector('[name="ws_end_date"]');
		function checkDates() {
			if (!start || !end) return;
			end.setCustomValidity(end.value && start.value && end.value < start.value ? (end.getAttribute('data-ws-msg') || 'The end date is before the start date.') : '');
		}
		if (start && end) {
			start.addEventListener('change', checkDates);
			end.addEventListener('change', checkDates);
		}
		form.addEventListener('submit', function (e) {
			checkDates();
			if (!form.checkValidity()) {
				e.preventDefault();
				e.stopImmediatePropagation();
				form.reportValidity();
			}
		}, true);
	});
})();
