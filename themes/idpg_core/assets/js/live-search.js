/**
 * Live-поиск в шапке — автодополнение с группами подсказок,
 * подсветкой совпадений и популярным при фокусе.
 */
(function(){
  'use strict';

  var forms = document.querySelectorAll('[data-idpg-live-search]');
  if (!forms.length) return;

  var restUrl = (window.idpgData && window.idpgData.restUrl) || (window.location.origin + '/wp-json/idpg/v1');
  var citySlug = (window.idpgData && window.idpgData.citySlug) || '';

  // Строки без gettext в JS — как в остальном файле.
  var L10N = {
    nothing: 'Ничего не найдено',
    allResults: 'Показать все результаты',
    upcoming: 'Ближайшие события',
    topCats: 'Популярные рубрики'
  };

  function debounce(fn, ms){
    var t; return function(){ var args=arguments, ctx=this; clearTimeout(t); t=setTimeout(function(){ fn.apply(ctx, args); }, ms); };
  }

  function escapeHtml(s){
    var d=document.createElement('div'); d.textContent=(s === null || s === undefined) ? '' : String(s); return d.innerHTML;
  }

  // Подсветка совпадений: сначала escape, потом обёртка в <mark>.
  function highlight(text, query){
    var safe = escapeHtml(text);
    var q = (query || '').trim();
    if (!q) return safe;
    var esc = q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    try {
      return safe.replace(new RegExp('(' + esc + ')', 'ig'), '<mark class="idpg-search__mark">$1</mark>');
    } catch (e){
      return safe;
    }
  }

  forms.forEach(function(form){
    var input = form.querySelector('[data-idpg-live-input]');
    var dropdown = form.querySelector('.idpg-search__dropdown');
    if (!input || !dropdown) return;

    var abortCtrl = null;
    var selectedIndex = -1;
    var popularCache = null;
    var popularLoading = false;

    function hideDropdown(){
      dropdown.hidden = true;
      dropdown.innerHTML = '';
      selectedIndex = -1;
    }

    // Один элемент подсказки: событие (картинка+мета) или площадка/рубрика (буква).
    function renderItem(item, query, isEvent){
      var thumb;
      if (isEvent && item.thumb){
        thumb = '<img class="idpg-search__thumb" src="'+escapeHtml(item.thumb)+'" alt="" loading="lazy">';
      } else {
        var letter = escapeHtml((item.title || '?').trim().charAt(0).toUpperCase() || '?');
        thumb = '<span class="idpg-search__thumb idpg-search__thumb--placeholder" aria-hidden="true">'+letter+'</span>';
      }
      var meta = '';
      if (isEvent && (item.date || item.venue)){
        meta = '<span class="idpg-search__meta">'+escapeHtml([item.date, item.venue].filter(Boolean).join(' • '))+'</span>';
      }
      return '<li role="option"><a class="idpg-search__item" href="'+escapeHtml(item.url || '#')+'">'
        + thumb
        + '<span class="idpg-search__info"><span class="idpg-search__title">'+highlight(item.title, query)+'</span>'
        + meta
        + '</span></a></li>';
    }

    // Группы подсказок с заголовками.
    function renderGroups(groups, query, showAll){
      var html = '';
      var total = 0;
      groups.forEach(function(g){
        if (!g || !g.items || !g.items.length) return;
        var isEvent = (g.type === 'events');
        total += g.items.length;
        html += '<div class="idpg-search__group">';
        if (g.title){
          html += '<div class="idpg-search__group-title">'+escapeHtml(g.title)+'</div>';
        }
        html += '<ul class="idpg-search__list" role="listbox">';
        g.items.forEach(function(item){
          html += renderItem(item, query, isEvent);
        });
        html += '</ul></div>';
      });
      if (!html){
        dropdown.innerHTML = '<div class="idpg-search__empty">'+escapeHtml(L10N.nothing)+'</div>'
          + '<a class="idpg-search__all" href="'+form.action+'?s='+encodeURIComponent(query)+'">'+escapeHtml(L10N.allResults)+' →</a>';
      } else {
        if (showAll){
          html += '<a class="idpg-search__all" href="'+form.action+'?s='+encodeURIComponent(query)+'">'
            + escapeHtml(L10N.allResults)+' ('+total+') →</a>';
        }
        dropdown.innerHTML = html;
      }
      dropdown.hidden = false;
      selectedIndex = -1;
    }

    // Старый плоский формат (обратная совместимость): только события.
    function renderFlat(resultsArr, query){
      renderGroups([{ type: 'events', title: '', items: resultsArr }], query, true);
    }

    function showPopular(){
      if (popularCache){
        renderGroups(popularCache, '', false);
        return;
      }
      if (popularLoading) return;
      popularLoading = true;
      var url = restUrl + '/search/popular';
      if (citySlug) url += '?city=' + encodeURIComponent(citySlug);
      fetch(url, { credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(data){
          popularLoading = false;
          var groups = [];
          if (data && data.events && data.events.length){
            groups.push({ type: 'events', title: L10N.upcoming, items: data.events });
          }
          if (data && data.cats && data.cats.length){
            groups.push({ type: 'cats', title: L10N.topCats, items: data.cats });
          }
          popularCache = groups;
          // Показываем только если поле всё ещё пустое/короткое.
          if (input.value.trim().length < 2) renderGroups(groups, '', false);
        })
        .catch(function(){ popularLoading = false; });
    }

    var doSearch = debounce(function(){
      var q = input.value.trim();
      // Короткий запрос — показываем популярное вместо пустоты.
      if (q.length < 2){ showPopular(); return; }

      if (abortCtrl) abortCtrl.abort();
      abortCtrl = new AbortController();

      var url = restUrl + '/search/live?q=' + encodeURIComponent(q);
      if (citySlug) url += '&city=' + encodeURIComponent(citySlug);

      fetch(url, {signal: abortCtrl.signal, credentials: 'same-origin'})
        .then(function(r){ return r.json(); })
        .then(function(data){
          if (data && data.groups){
            renderGroups(data.groups, q, true);
          } else {
            renderFlat((data && data.results) || [], q);
          }
        })
        .catch(function(e){ if(e.name!=='AbortError') hideDropdown(); });
    }, 300);

    input.addEventListener('input', doSearch);
    input.addEventListener('focus', function(){
      if (input.value.trim().length >= 2) doSearch();
      else showPopular();
    });

    // Keyboard navigation (по всем элементам групп по порядку).
    input.addEventListener('keydown', function(e){
      if (dropdown.hidden) return;
      var items = dropdown.querySelectorAll('.idpg-search__item');
      if (!items.length) return;

      if (e.key === 'ArrowDown'){
        e.preventDefault();
        selectedIndex = Math.min(selectedIndex+1, items.length-1);
        updateActive(items);
      } else if (e.key === 'ArrowUp'){
        e.preventDefault();
        selectedIndex = Math.max(selectedIndex-1, 0);
        updateActive(items);
      } else if (e.key === 'Enter'){
        if (selectedIndex>=0 && items[selectedIndex]){
          e.preventDefault();
          window.location.href = items[selectedIndex].href;
        }
      } else if (e.key === 'Escape'){
        hideDropdown();
      }
    });

    function updateActive(items){
      items.forEach(function(el,i){ el.classList.toggle('is-active', i===selectedIndex); });
      if (selectedIndex>=0 && items[selectedIndex]) items[selectedIndex].scrollIntoView({block:'nearest'});
    }

    // Hide on click outside
    document.addEventListener('click', function(e){
      if (!form.contains(e.target)) hideDropdown();
    });

    // Hide on form submit if empty
    form.addEventListener('submit', function(e){
      if (!input.value.trim()){ e.preventDefault(); }
    });
  });

  // Подсветка запроса в карточках на странице поиска (только текстовые узлы).
  (function highlightSearchPage(){
    if (!document.body.classList.contains('search')) return;
    var q = '';
    try {
      q = (new URLSearchParams(window.location.search).get('s') || '').trim();
    } catch (e){ return; }
    if (q.length < 2) return;
    var esc = q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    var re;
    try { re = new RegExp('(' + esc + ')', 'ig'); } catch (e){ return; }
    document.querySelectorAll('.idpg-event-card__title').forEach(function(el){
      walk(el);
    });
    function walk(node){
      Array.prototype.slice.call(node.childNodes).forEach(function(child){
        if (child.nodeType === 3){
          var frag = highlightTextNode(child);
          if (frag) node.replaceChild(frag, child);
        } else if (child.nodeType === 1 && child.tagName !== 'MARK'){
          walk(child);
        }
      });
    }
    function highlightTextNode(textNode){
      var text = textNode.nodeValue;
      re.lastIndex = 0;
      if (!re.test(text)) return null;
      re.lastIndex = 0;
      var frag = document.createDocumentFragment();
      var last = 0, m;
      while ((m = re.exec(text))){
        frag.appendChild(document.createTextNode(text.slice(last, m.index)));
        var mark = document.createElement('mark');
        mark.className = 'idpg-search__mark';
        mark.textContent = m[0];
        frag.appendChild(mark);
        last = m.index + m[0].length;
        if (!m[0].length) re.lastIndex++;
      }
      frag.appendChild(document.createTextNode(text.slice(last)));
      return frag;
    }
  })();
})();
