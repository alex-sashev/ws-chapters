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
