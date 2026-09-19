/**
 * IDPG Ad Widget — выбор/удаление баннера (медиабиблиотека).
 *
 * Вешается на документ, чтобы работать и для виджетов,
 * добавленных в блочном редакторе виджетов.
 *
 * @package idpg_core
 */
(function ($) {
	'use strict';

	var frame;

	$(document).on('click', '.idpg-ad-widget__select', function (e) {
		e.preventDefault();

		var $wrap = $(this).closest('.idpg-ad-widget__media');
		if (!$wrap.length) {
			return;
		}

		if (frame) {
			frame.remove();
			frame = null;
		}

		frame = wp.media({
			multiple: false,
			button: { text: idpgAdWidgetL10n.select || 'Выбрать' }
		});

		frame.on('select', function () {
			var att = frame.state().get('selection').first();
			var id = att ? att.id : '';

			$wrap.find('.idpg-ad-widget__image-id').val(id);
			$wrap.find('.idpg-ad-widget__image-wrap').empty();

			if (id) {
				var attachment = wp.media.attachment(id);
				attachment.fetch().done(function () {
					// Для предпросмотра берём средний размер, если он есть,
					// и ограничиваем ширину — иначе оригинал растягивает форму.
					var url = attachment.get('url');
					var sizes = attachment.get('sizes');
					if (sizes && sizes.medium && sizes.medium.url) {
						url = sizes.medium.url;
					}
					if (url) {
						$wrap.find('.idpg-ad-widget__image-wrap').html(
							'<img src="' + url + '" alt="" style="max-width:300px;height:auto;">'
						);
					}
				});
			}
		});

		frame.open();
	});

	$(document).on('click', '.idpg-ad-widget__remove', function (e) {
		e.preventDefault();

		var $wrap = $(this).closest('.idpg-ad-widget__media');
		if (!$wrap.length) {
			return;
		}

		$wrap.find('.idpg-ad-widget__image-wrap').empty();
		$wrap.find('.idpg-ad-widget__image-id').val('');
	});
})(jQuery);
