/**
 * Кабинет организатора — переключение разделов без перезагрузки страницы.
 *
 * Разделы уже отрендерены сервером: активный виден, остальные скрыты
 * атрибутом hidden. Скрипт только переключает видимость и обновляет адрес
 * через history.pushState. Без JavaScript работает прямой заход по адресу
 * раздела — сервер отдаёт нужный раздел активным.
 */
(function(){
  'use strict';

  var root = document.querySelector('.idpg-org-page');
  if (!root) return;

  var links = root.querySelectorAll('[data-idpg-org-section]');
  var panels = root.querySelectorAll('[data-idpg-org-panel]');
  if (!links.length || !panels.length) return;

  function panelFor(section){
    return root.querySelector('[data-idpg-org-panel="' + section + '"]');
  }

  function linkFor(section){
    return root.querySelector('[data-idpg-org-section="' + section + '"]');
  }

  /**
   * Раздел по текущему адресу. База /organizer/ отдаёт раздел по умолчанию —
   * первый в разметке.
   */
  function sectionFromUrl(){
    var match = window.location.pathname.match(/\/organizer\/([a-z0-9_-]+)\/?$/i);
    if (match && panelFor(match[1])) return match[1];

    return panels.length ? panels[0].getAttribute('data-idpg-org-panel') : '';
  }

  /**
   * Показать раздел. push = true — обновить адрес (клик по меню).
   */
  function activate(section, push){
    var panel = panelFor(section);
    if (!panel) return false;

    Array.prototype.forEach.call(panels, function(el){
      el.hidden = (el !== panel);
    });

    Array.prototype.forEach.call(links, function(el){
      if (el.getAttribute('data-idpg-org-section') === section){
        el.classList.add('is-active');
        el.setAttribute('aria-current', 'page');
      } else {
        el.classList.remove('is-active');
        el.removeAttribute('aria-current');
      }
    });

    if (push && window.history && window.history.pushState){
      var link = linkFor(section);
      if (link) window.history.pushState({ idpgOrgSection: section }, '', link.href);
    }

    // Перевод фокуса на заголовок раздела — для клавиатуры и скринридеров.
    var title = panel.querySelector('.idpg-org-section__title');
    if (title){
      title.setAttribute('tabindex', '-1');
      try { title.focus({ preventScroll: true }); } catch (e) { title.focus(); }
    }

    return true;
  }

  Array.prototype.forEach.call(links, function(link){
    link.addEventListener('click', function(e){
      // Клик с модификатором или не левой кнопкой — не перехватываем,
      // пусть браузер откроет ссылку как обычно.
      if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;

      var section = link.getAttribute('data-idpg-org-section');
      if (!section) return;

      e.preventDefault();
      activate(section, true);
    });
  });

  // Кнопки «назад/вперёд» браузера — синхронизируем вид с адресом.
  window.addEventListener('popstate', function(){
    activate(sectionFromUrl(), false);
  });
})();
