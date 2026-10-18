/* ws-chapters — accessible tabs. Without JavaScript all panels show in order. */
(function () {
	'use strict';
	function init(root) {
		var tabs = Array.prototype.slice.call(root.querySelectorAll('[role="tab"]'));
		var panels = tabs.map(function (t) { return document.getElementById(t.getAttribute('aria-controls')); });
		root.classList.add('is-enhanced');
		function select(i, focus) {
			tabs.forEach(function (t, j) {
				var on = i === j;
				t.setAttribute('aria-selected', on ? 'true' : 'false');
				t.tabIndex = on ? 0 : -1;
				panels[j].hidden = !on;
			});
			if (focus) tabs[i].focus();
			window.dispatchEvent(new Event('resize')); // Maps inside a newly shown panel recalculate their size.
		}
		tabs.forEach(function (t, i) {
			t.addEventListener('click', function (e) { e.preventDefault(); select(i); history.replaceState(null, '', '#' + panels[i].id); });
			t.addEventListener('keydown', function (e) {
				var k = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
				if (k) { e.preventDefault(); select((i + k + tabs.length) % tabs.length, true); }
			});
		});
		var start = panels.findIndex(function (p) { return '#' + p.id === location.hash; });
		select(start < 0 ? 0 : start);
	}
	document.querySelectorAll('[data-ws-tabs]').forEach(init);
})();
