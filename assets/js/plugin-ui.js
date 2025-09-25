(function(){
  function q(sel){ return document.querySelector(sel); }
  function ajax(url){ return fetch(url, {credentials:'same-origin'}).then(function(r){return r.json();}); }
  function copy(text){
    if(navigator.clipboard && navigator.clipboard.writeText){ navigator.clipboard.writeText(text); return; }
    var t=document.createElement('textarea'); t.value=text; document.body.appendChild(t); t.select(); try{ document.execCommand('copy'); }catch(e){} document.body.removeChild(t);
  }

  function poll(){
    ajax(VCBK.ajax+"?action=vcbk_progress&_wpnonce="+VCBK.nonce).then(function(j){
      var p = j.percent||0; var stage=j.stage||''; var bar=q('#vcbk-progress-bar'); var txt=q('#vcbk-progress-stage');
      if(bar){ bar.style.width = p+'%'; }
      if(txt){ txt.textContent = (stage||'') + (stage?' ':'') + '('+p+'%)'; }
    }).catch(function(){});
    var levelSel = q('select[name="level"]');
    var level = levelSel ? levelSel.value : '';
    var url = VCBK.ajax+"?action=vcbk_tail_logs&_wpnonce="+VCBK.nonce + (level?"&level="+encodeURIComponent(level):'');
    ajax(url).then(function(j){
      if(Array.isArray(j.lines)){
        var el = q('#vcbk-log'); if(el){
          var atBottom = el.scrollTop + el.clientHeight >= el.scrollHeight - 5;
          el.textContent = j.lines.join('\n');
          if(atBottom && (!window.vcbkNoScroll)){
            el.scrollTop = el.scrollHeight;
          }
        }
      }
    }).catch(function(){});
  }

  function schedulePreview(){
    var sel = document.querySelector('[name="schedule[interval]"]');
    var time = document.querySelector('[name="schedule[start_time]"]');
    var out = document.getElementById('vcbk-next-run-preview');
    if(!sel || !time || !out) return;
    function compute(){
      var iv = sel.value; var t = (time.value||'01:30').split(':');
      var h=parseInt(t[0]||'1',10), m=parseInt(t[1]||'30',10);
      var now = new Date(); var next = new Date(now.getTime());
      next.setHours(h, m, 0, 0); if(next <= now){ next.setDate(next.getDate()+1); }
      var map = { '2h':2,'4h':4,'8h':8,'12h':12 };
      if(map[iv]){ // show next tick within same day if possible
        while(next <= now){ next = new Date(next.getTime() + map[iv]*3600*1000); }
      }
      out.textContent = next.toLocaleString();
    }
    sel.addEventListener('change', compute);
    time.addEventListener('input', compute);
    compute();
  }

  document.addEventListener('DOMContentLoaded', function(){
    schedulePreview();
    // Auto-start polling if a live log container exists on the page
    var logEl = q('#vcbk-log');
    var toggle = q('#vcbk-toggle-autorefresh');
    if(logEl){ poll(); window.vcbkTimer=setInterval(poll, 2500); if(toggle){ toggle.dataset.running='1'; toggle.textContent='Stop Auto-Refresh'; } }
  });

  document.addEventListener('click', function(e){
    var t = e.target;
    if(t && t.matches('#vcbk-toggle-autorefresh')){
      e.preventDefault();
      if(t.dataset.running==='1'){ window.clearInterval(window.vcbkTimer); t.dataset.running='0'; t.textContent='Start Auto-Refresh'; }
      else { poll(); window.vcbkTimer=setInterval(poll, 2500); t.dataset.running='1'; t.textContent='Stop Auto-Refresh'; }
    }
    if(t && t.matches('#vcbk-toggle-scroll')){
      e.preventDefault();
      window.vcbkNoScroll = !window.vcbkNoScroll;
      t.textContent = window.vcbkNoScroll ? 'Start Auto-Scroll' : 'Stop Auto-Scroll';
    }
    if(t && t.matches('#vcbk-copy-log')){
      e.preventDefault();
      var el = q('#vcbk-log'); if(el){ copy(el.textContent||''); }
    }
    if(t && t.matches('#vcbk-pause,#vcbk-resume,#vcbk-cancel')){
      e.preventDefault();
      var cmd = t.id==='vcbk-pause'?'pause':(t.id==='vcbk-resume'?'resume':'cancel');
      var body = new FormData();
      body.append('action','vcbk_job_control');
      body.append('_wpnonce', VCBK.nonce);
      body.append('cmd', cmd);
      fetch(VCBK.ajax, {method:'POST', credentials:'same-origin', body: body}).then(function(){ poll(); });
    }
  });
})();
