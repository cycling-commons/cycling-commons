// SPDX-License-Identifier: AGPL-3.0-only
/* The planner's own calendar for the start date (route-planner.md section 4.4, owner 2026-10-18: the browser's
   calendar was not in the site's style and cannot be styled). A popover under the date button: the month and its
   arrows, the weekdays from Monday in the page's language, the days before today shut, a dot on the days the weather
   forecast reaches. Keys: arrows move a day or a week, Page Up and Page Down a month, Enter or Space picks, Escape
   closes. The picked day goes into the hidden date field, whose change event the planner already listens to. */

const pad = n => String(n).padStart(2, '0');
const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const iso = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
const atNoon = s => new Date(s + 'T12:00:00');
const addDays = (d, n) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };

/** Wires the calendar to `button` and the hidden `input`. `words`: prev, next, aria, forecast. `forecastDays`: how far
    ahead the forecast reaches. Returns close(). */
export function initDatePick(button, input, words, forecastDays){
  if(!button || !input) return () => {};
  const lang = document.documentElement.lang || 'en';
  const box = document.createElement('div');
  box.className = 'cc-cal';
  box.hidden = true;
  box.setAttribute('role', 'dialog');
  box.setAttribute('aria-label', words.aria || 'Pick the start date');   // setAttribute: no escaping needed
  button.insertAdjacentElement('afterend', box);
  button.setAttribute('aria-haspopup', 'dialog');
  button.setAttribute('aria-expanded', 'false');
  let shown = null;   // the first day of the month on show
  let focus = null;   // the day the keys move

  const today = () => atNoon(iso(new Date()));
  const minDay = () => atNoon(input.min || iso(new Date()));
  const picked = () => (/^\d{4}-\d{2}-\d{2}$/.test(input.value) ? atNoon(input.value) : today());
  const monthName = d => d.toLocaleDateString(lang, { month: 'long', year: 'numeric' });
  // Monday first: 2024-01-01 was a Monday.
  const weekdays = Array.from({ length: 7 }, (_, i) => new Date(2024, 0, 1 + i).toLocaleDateString(lang, { weekday: 'short' }));

  function render(){
    const first = new Date(shown.getFullYear(), shown.getMonth(), 1, 12);
    const lead = (first.getDay() + 6) % 7;   // days of the week before the 1st, Monday first
    const start = addDays(first, -lead);
    const sel = iso(picked()), now = iso(today()), min = iso(minDay()), reach = iso(addDays(today(), forecastDays));
    const prevOff = iso(new Date(first.getFullYear(), first.getMonth(), 0, 12)) < min;
    let cells = '';
    for(let i = 0; i < 42; i++){
      const d = addDays(start, i), k = iso(d), out = d.getMonth() !== first.getMonth(), shut = k < min;
      const cls = ['cc-cal-d', out ? 'out' : '', k === now ? 'today' : '', k === sel ? 'sel' : '', k === iso(focus) ? 'focus' : '', !shut && k <= reach ? 'fc' : ''].filter(Boolean).join(' ');
      const label = d.toLocaleDateString(lang, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) + (!shut && k <= reach ? ' · ' + (words.forecast || 'weather forecast') : '');
      cells += `<button type="button" class="${cls}" data-day="${k}" ${shut ? 'disabled' : ''} tabindex="${k === iso(focus) ? 0 : -1}" aria-label="${esc(label)}"${k === sel ? ' aria-pressed="true"' : ''}>${d.getDate()}</button>`;
    }
    box.innerHTML = `<div class="cc-cal-h"><button type="button" class="cc-cal-nav" data-step="-1" aria-label="${esc(words.prev || 'Previous month')}" ${prevOff ? 'disabled' : ''}>‹</button>`
      + `<span class="cc-cal-m" aria-live="polite">${esc(monthName(first))}</span>`
      + `<button type="button" class="cc-cal-nav" data-step="1" aria-label="${esc(words.next || 'Next month')}">›</button></div>`
      + `<div class="cc-cal-w" aria-hidden="true">${weekdays.map(w => `<span>${esc(w)}</span>`).join('')}</div>`
      + `<div class="cc-cal-g" role="grid">${cells}</div>`
      + `<div class="cc-cal-f" aria-hidden="true"><i></i>${esc(words.forecast || 'weather forecast')}</div>`;
  }
  function focusDay(){ const b = box.querySelector(`[data-day="${iso(focus)}"]`); if(b) b.focus(); }
  function moveTo(d){
    if(iso(d) < iso(minDay())) d = minDay();
    focus = d;
    if(d.getMonth() !== shown.getMonth() || d.getFullYear() !== shown.getFullYear()) shown = new Date(d.getFullYear(), d.getMonth(), 1, 12);
    render(); focusDay();
  }
  function open(){
    focus = picked(); shown = new Date(focus.getFullYear(), focus.getMonth(), 1, 12);
    render(); box.hidden = false; button.setAttribute('aria-expanded', 'true'); focusDay();
    setTimeout(() => document.addEventListener('pointerdown', outside, true), 0);
  }
  function close(back){
    if(box.hidden) return;
    box.hidden = true; button.setAttribute('aria-expanded', 'false');
    document.removeEventListener('pointerdown', outside, true);
    if(back) button.focus();
  }
  function outside(e){ if(!box.contains(e.target) && e.target !== button) close(false); }
  function pick(k){
    if(k < iso(minDay())) return;
    if(input.value !== k){ input.value = k; input.dispatchEvent(new Event('change', { bubbles: true })); }
    close(true);
  }

  button.addEventListener('click', () => (box.hidden ? open() : close(true)));
  box.addEventListener('click', e => {
    const nav = e.target.closest('.cc-cal-nav');
    if(nav && !nav.disabled){ shown = new Date(shown.getFullYear(), shown.getMonth() + Number(nav.dataset.step), 1, 12); focus = shown < minDay() ? minDay() : shown; render(); return; }
    const day = e.target.closest('.cc-cal-d');
    if(day && !day.disabled) pick(day.dataset.day);
  });
  box.addEventListener('keydown', e => {
    const step = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[e.key];
    if(step){ e.preventDefault(); moveTo(addDays(focus, step)); return; }
    if(e.key === 'PageUp' || e.key === 'PageDown'){ e.preventDefault(); const d = new Date(focus); d.setMonth(d.getMonth() + (e.key === 'PageUp' ? -1 : 1)); moveTo(d); return; }
    if(e.key === 'Escape'){ e.preventDefault(); e.stopPropagation(); close(true); return; }
    if((e.key === 'Enter' || e.key === ' ') && e.target.classList.contains('cc-cal-d')){ e.preventDefault(); pick(e.target.dataset.day); }
  });
  return close;
}
