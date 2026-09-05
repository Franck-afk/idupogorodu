/**
 * Live-поиск в шапке — автодополнение событий
 */
(function(){
  'use strict';

  var forms = document.querySelectorAll('[data-idpg-live-search]');
  if (!forms.length) return;

  var restUrl = (window.idpgData && window.idpgData.restUrl) || (window.location.origin + '/wp-json/idpg/v1');
  var citySlug = (window.idpgData && window.idpgData.citySlug) || '';

  function debounce(fn, ms){
    var t; return function(){ var args=arguments, ctx=this; clearTimeout(t); t=setTimeout(function(){ fn.apply(ctx, args); }, ms); };
  }

  forms.forEach(function(form){
    var input = form.querySelector('[data-idpg-live-input]');
    var dropdown = form.querySelector('.idpg-search__dropdown');
    if (!input || !dropdown) return;

    var abortCtrl = null;
    var selectedIndex = -1;
    var results = [];

    function hideDropdown(){
      dropdown.hidden = true;
      dropdown.innerHTML = '';
      selectedIndex = -1;
      results = [];
    }

    function render(resultsArr, query){
      if (!resultsArr.length){
        dropdown.innerHTML = '<div class="idpg-search__empty">Ничего не найдено</div><a class="idpg-search__all" href="'+form.action+'?s='+encodeURIComponent(query)+'">Показать все результаты →</a>';
        dropdown.hidden = false;
        return;
      }
      var html = '<ul class="idpg-search__list" role="listbox">';
      resultsArr.forEach(function(item, idx){
        html += '<li role="option" data-index="'+idx+'"><a class="idpg-search__item'+(idx===selectedIndex?' is-active':'')+'" href="'+item.url+'">'
          + (item.thumb ? '<img class="idpg-search__thumb" src="'+item.thumb+'" alt="" loading="lazy">' : '<span class="idpg-search__thumb idpg-search__thumb--placeholder">?</span>')
          + '<span class="idpg-search__info"><span class="idpg-search__title">'+escapeHtml(item.title)+'</span>'
          + (item.date || item.venue ? '<span class="idpg-search__meta">'+escapeHtml([item.date, item.venue].filter(Boolean).join(' • '))+'</span>' : '')
          + '</span></a></li>';
      });
      html += '</ul>';
      html += '<a class="idpg-search__all" href="'+form.action+'?s='+encodeURIComponent(query)+'">Показать все результаты ('+resultsArr.length+') →</a>';
      dropdown.innerHTML = html;
      dropdown.hidden = false;
    }

    function escapeHtml(s){
      var d=document.createElement('div'); d.textContent=s; return d.innerHTML;
    }

    var doSearch = debounce(function(){
      var q = input.value.trim();
      if (q.length < 2){ hideDropdown(); return; }

      if (abortCtrl) abortCtrl.abort();
      abortCtrl = new AbortController();

      var url = restUrl + '/search/live?q=' + encodeURIComponent(q);
      if (citySlug) url += '&city=' + encodeURIComponent(citySlug);

      fetch(url, {signal: abortCtrl.signal, credentials: 'same-origin'})
        .then(function(r){ return r.json(); })
        .then(function(data){
          results = (data && data.results) || [];
          render(results, q);
        })
        .catch(function(e){ if(e.name!=='AbortError') hideDropdown(); });
    }, 300);

    input.addEventListener('input', doSearch);
    input.addEventListener('focus', function(){ if(input.value.trim().length>=2) doSearch(); });

    // Keyboard navigation
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
})();
