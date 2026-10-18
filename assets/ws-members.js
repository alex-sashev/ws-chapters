/* Members library: the Upload button opens the form; the Link type asks for a web address instead of a file. */
(function () {
	'use strict';
	var box = document.getElementById('ws-doc-upload');
	// With JavaScript the top button is the only control; the form's own expander is hidden.
	if (box) box.classList.add('ws-upload--js');
	document.querySelectorAll('[data-ws-upload-toggle]').forEach(function (btn) {
		btn.addEventListener('click', function (e) {
			if (!box) return;
			e.preventDefault();
			box.open = true;
			box.scrollIntoView({ behavior: 'smooth', block: 'start' });
			var first = box.querySelector('input[type=text]');
			if (first) setTimeout(function () { first.focus(); }, 300);
		});
	});
	var form = document.querySelector('[data-ws-doc-form]');
	if (!form) return;
	var type = form.querySelector('[data-ws-doc-type]');
	var file = form.querySelector('[data-ws-doc-file]');
	var url = form.querySelector('[data-ws-doc-url]');
	function apply() {
		var isLink = type.value === 'link';
		file.hidden = isLink;
		url.hidden = !isLink;
		file.querySelector('input').required = !isLink && type.value !== '';
		url.querySelector('input').required = isLink;
	}
	type.addEventListener('change', apply);
	apply();
})();
