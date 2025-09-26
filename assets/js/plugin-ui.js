(function(){
  var vcbkFailures = 0;
  function q(sel){ return document.querySelector(sel); }
  function qa(sel){ return Array.prototype.slice.call(document.querySelectorAll(sel)); }
  function ajax(url){ return fetch(url, {credentials:'same-origin'}).then(function(r){return r.json();}); }
  function copy(text){
    if(navigator.clipboard && navigator.clipboard.writeText){ navigator.clipboard.writeText(text); return; }
    var t=document.createElement('textarea'); t.value=text; document.body.appendChild(t); t.select(); try{ document.execCommand('copy'); }catch(e){} document.body.removeChild(t);
  }
  function toast(kind, msg){
    var root = q('#vcbk-toast-root'); if(!root){ root=document.createElement('div'); root.id='vcbk-toast-root'; root.className='vcbk-toast-root'; document.body.appendChild(root); }
    var el = document.createElement('div'); el.className='vcbk-toast '+(kind||''); el.innerHTML = '<span>'+msg+'</span>';
    root.appendChild(el); setTimeout(function(){ el.style.opacity='0'; setTimeout(function(){ el.remove(); }, 300); }, 3200);
  }

  function updateStages(stageStr){
    var container = q('#vcbk-stages'); if(!container) return;
    var s = (stageStr||'').toLowerCase();
    var steps = ['archive','upload','complete'];
    var currentIndex = 0;
    if(/upload/.test(s)) currentIndex = 1; else if(/complete|done|finish/.test(s)) currentIndex = 2; else currentIndex = 0;
    qa('#vcbk-stages .vcbk-stage').forEach(function(el, idx){
      el.classList.remove('active','done');
      if(idx < currentIndex){ el.classList.add('done'); }
      else if(idx === currentIndex){ el.classList.add('active'); }
    });
  }

  function renderLogLines(lines){
    var el = q('#vcbk-log'); if(!el) return;
    var atBottom = el.scrollTop + el.clientHeight >= el.scrollHeight - 5;
    var html = '';
    for(var i=0;i<lines.length;i++){
      var line = lines[i]; var level='info'; var msg=line; try{ var j=JSON.parse(line); if(j && j.level){ level=j.level; } msg = typeof j==='object'?JSON.stringify(j):line; }catch(e){}
      html += '<div class="vcbk-log-line level-'+level+'">'+escapeHtml(msg)+'</div>';
    }
    el.innerHTML = html;
    if(atBottom && (!window.vcbkNoScroll)){
      el.scrollTop = el.scrollHeight;
    }
  }

  function escapeHtml(s){ return (''+s).replace(/[&<>]/g,function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]); }); }

  function poll(){
    ajax(VCBK.ajax+"?action=vcbk_progress&_wpnonce="+VCBK.nonce).then(function(j){
      var p = j.percent||0; var stage=j.stage||''; var bar=q('#vcbk-progress-bar'); var txt=q('#vcbk-progress-stage');
      if(bar){
        bar.style.width = p+'%';
        bar.classList.remove('success','error');
        if(p>=100 || /complete/i.test(stage)){ bar.classList.add('success'); }
        if(/fail|error/i.test(stage)){ bar.classList.add('error'); }
      }
      if(txt){ txt.textContent = (stage||'') + (stage?' ':'') + '('+p+'%)'; }
      updateStages(stage);
      vcbkFailures = 0;
    }).catch(function(err){ vcbkFailures++; if(vcbkFailures>2){ try{ console.warn('VCBK: progress poll failed', err); }catch(e){} }});
    var level = (window.vcbkLogLevel||'');
    var url = VCBK.ajax+"?action=vcbk_tail_logs&_wpnonce="+VCBK.nonce + (level?"&level="+encodeURIComponent(level):'') + "&lines=10";
    ajax(url).then(function(j){ if(Array.isArray(j.lines)){ renderLogLines(j.lines); } vcbkFailures = 0; }).catch(function(err){ vcbkFailures++; if(vcbkFailures>2){ try{ console.warn('VCBK: log poll failed', err); }catch(e){} }});
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

  function setupSearchableSelects(){
    qa('.vcbk-select-search input[type="search"]').forEach(function(input){
      var sel = document.querySelector(input.getAttribute('data-target'));
      if(!sel) return;
      function pick(){
        var q = (input.value||'').toLowerCase();
        if(!q){ return; }
        var opts = sel.options; var best = -1; var bestIdx=-1;
        for(var i=0;i<opts.length;i++){
          var txt = (opts[i].text||'').toLowerCase();
          var idx = txt.indexOf(q);
          if(idx!==-1 && (best===-1 || idx<best)){ best=idx; bestIdx=i; }
        }
        if(bestIdx>=0){ sel.selectedIndex = bestIdx; }
      }
      input.addEventListener('input', pick);
      input.addEventListener('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); pick(); } });
    });
  }

  function setupInlineValidation(){
    qa('input[data-validate="url"]').forEach(function(inp){
      function validate(){
        var ok=true; try{ if(inp.value){ new URL(inp.value); } }catch(e){ ok=false; }
        inp.classList.toggle('invalid', !ok);
        var help = inp.nextElementSibling; if(help && help.classList.contains('vcbk-inline-help')){
          help.textContent = ok? '': 'Invalid URL';
        }
      }
      inp.addEventListener('input', validate);
      validate();
    });
  }

  function setupDropzones(){
    qa('.vcbk-dropzone').forEach(function(zone){
      var input = zone.querySelector('input[type=file]');
      var fileName = zone.querySelector('.vcbk-dz-file');
      var progressBar = zone.querySelector('.vcbk-dz-progress .bar');
      var form = zone.closest('form');
      function choose(){ if(input) input.click(); }
      function onFile(file){ if(!file) return; if(fileName) fileName.textContent = file.name+' ('+formatBytes(file.size)+')'; }
      zone.addEventListener('click', function(e){ if(e.target===zone || e.target.classList.contains('vcbk-dz-title')){ e.preventDefault(); choose(); }});
      zone.addEventListener('dragover', function(e){ e.preventDefault(); zone.classList.add('drag'); });
      zone.addEventListener('dragleave', function(){ zone.classList.remove('drag'); });
      zone.addEventListener('drop', function(e){ e.preventDefault(); zone.classList.remove('drag'); var f=e.dataTransfer&&e.dataTransfer.files&&e.dataTransfer.files[0]; if(input && f){ input.files = e.dataTransfer.files; onFile(f); upload(form, progressBar); }});
      if(input){ input.addEventListener('change', function(){ if(input.files && input.files[0]){ onFile(input.files[0]); } }); }
    });
  }

  function upload(form, bar){
    if(!form) return; var xhr = new XMLHttpRequest();
    xhr.open(form.method||'POST', form.action, true);
    xhr.onload = function(){ if(xhr.status>=200 && xhr.status<400){ toast('ok','Upload complete'); location.reload(); } else { toast('err','Upload failed'); } };
    if(xhr.upload && bar){ xhr.upload.onprogress = function(e){ if(e.lengthComputable){ var p = Math.round((e.loaded/e.total)*100); bar.style.width = p+'%'; } }; }
    try{
      var fd = new FormData(form);
      xhr.send(fd);
    }catch(e){ form.submit(); }
  }

  function formatBytes(n){ if(n===0) return '0 B'; var k=1024, dm=1, sizes=['B','KB','MB','GB']; var i=Math.floor(Math.log(n)/Math.log(k)); return parseFloat((n/Math.pow(k,i)).toFixed(dm))+' '+sizes[i]; }

  document.addEventListener('DOMContentLoaded', function(){
    schedulePreview();
    setupDropzones();
    setupSearchableSelects();
    setupInlineValidation();
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
      var el = q('#vcbk-log'); if(el){ copy(el.textContent||el.innerText||''); toast('ok','Logs copied'); }
    }
    if(t && t.matches('#vcbk-pause,#vcbk-resume,#vcbk-cancel')){
      e.preventDefault();
      var cmd = t.id==='vcbk-pause'?'pause':(t.id==='vcbk-resume'?'resume':'cancel');
      var body = new FormData();
      body.append('action','vcbk_job_control');
      body.append('_wpnonce', VCBK.nonce);
      body.append('cmd', cmd);
      fetch(VCBK.ajax, {method:'POST', credentials:'same-origin', body: body}).then(function(){ poll(); toast('ok', 'Command: '+cmd); });
    }
    if(t && t.matches('.vcbk-tab')){
      e.preventDefault(); qa('.vcbk-tab').forEach(function(x){ x.setAttribute('aria-selected','false'); }); t.setAttribute('aria-selected','true');
      var level = t.getAttribute('data-level')||''; window.vcbkLogLevel = level; poll();
    }
    if(t && t.matches('.vcbk-collapsible-header')){
      var box = t.closest('.vcbk-collapsible'); if(box){ box.classList.toggle('vcbk-collapsed'); }
    }
  });
})();
