/**
 * Настройки организатора — динамическое управление соцсетями.
 */
(function () {
	'use strict';

	var container = document.getElementById('idpg-social-container');
	var addBtn    = document.getElementById('idpg-social-add');

	if ( ! container || ! addBtn ) {
		return;
	}

	var networks = [
		{ value: 'vk',       label: 'ВКонтакте'   },
		{ value: 'telegram', label: 'Telegram'    },
		{ value: 'youtube',  label: 'YouTube'     },
		{ value: 'rutube',   label: 'Rutube'      },
		{ value: 'ok',       label: 'Одноклассники' },
		{ value: 'dzen',     label: 'Дзен'        },
	];

	var nextIndex = container.children.length;

	/**
	 * Создать HTML новой строки соцсети.
	 */
	function createRow() {
		var row = document.createElement('div');
		row.className = 'idpg-social-row';
		row.style.cssText = 'display:flex;gap:10px;align-items:center;margin-bottom:8px;';

		var select = document.createElement('select');
		select.name = 'idpg_venue_social[' + nextIndex + '][network]';
		select.style.width = '200px';

		var optEmpty = document.createElement('option');
		optEmpty.value = '';
		optEmpty.textContent = '— Выберите сеть —';
		select.appendChild(optEmpty);

		networks.forEach(function (n) {
			var opt = document.createElement('option');
			opt.value = n.value;
			opt.textContent = n.label;
			select.appendChild(opt);
		});

		var input = document.createElement('input');
		input.type = 'url';
		input.name = 'idpg_venue_social[' + nextIndex + '][url]';
		input.placeholder = 'https://';
		input.style.flex = '1';

		var removeBtn = document.createElement('button');
		removeBtn.type = 'button';
		removeBtn.className = 'button idpg-social-remove';
		removeBtn.textContent = 'Удалить';
		removeBtn.addEventListener('click', function () {
			row.remove();
		});

		row.appendChild(select);
		row.appendChild(input);
		row.appendChild(removeBtn);

		return row;
	}

	/**
	 * Кнопка «Добавить соцсеть».
	 */
	addBtn.addEventListener('click', function () {
		container.appendChild(createRow());
		nextIndex++;
	});

	/**
	 * Удаление существующих строк.
	 */
	container.addEventListener('click', function (e) {
		var btn = e.target.closest('.idpg-social-remove');
		if ( ! btn ) {
			return;
		}
		var row = btn.closest('.idpg-social-row');
		if ( row ) {
			row.remove();
		}
	});
})();
