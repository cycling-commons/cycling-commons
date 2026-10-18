// SPDX-License-Identifier: AGPL-3.0-only
/* The map key docked into the ride's details (route-planner.md section 3, owner 2026-10-17: "I want to be able to
   drag the legend into the right ride info drawer; it then snaps to it"). The key's grip drags it; let go over the
   ride's side and it snaps in at the side's foot, let go anywhere else and it goes back to its corner of the map.
   The grip is also a button: Enter docks and undocks it. The choice is a convenience kept in this browser; while the
   side is not shown the key waits in its corner and docks again when the side comes back. */

const KEY = 'cc-legend-docked';

function wanted(){ try { return localStorage.getItem(KEY) === '1'; } catch(e){ return false; } }
function remember(on){ try { localStorage.setItem(KEY, on ? '1' : '0'); } catch(e){ /* private mode: this visit only */ } }

/** Wires the key's grip. `side` is the ride's side, `slot` where the key docks in it, `words` the grip's two labels.
    Returns sync(sideShown), which the planner calls whenever the side shows or hides. */
export function initLegendDock(side, slot, words){
  const legend = document.querySelector('.legend');
  if(!legend || !side || !slot) return () => {};
  const home = document.createComment(' legend ');
  legend.parentNode.insertBefore(home, legend);
  const grip = document.createElement('button');
  grip.type = 'button';
  grip.className = 'lg-grip';
  grip.innerHTML = '<svg viewBox="0 0 22 8" width="22" height="8" aria-hidden="true"><g fill="currentColor"><circle cx="3" cy="2" r="1.3"/><circle cx="8" cy="2" r="1.3"/><circle cx="13" cy="2" r="1.3"/><circle cx="18" cy="2" r="1.3"/><circle cx="3" cy="6" r="1.3"/><circle cx="8" cy="6" r="1.3"/><circle cx="13" cy="6" r="1.3"/><circle cx="18" cy="6" r="1.3"/></g></svg>';
  legend.insertBefore(grip, legend.firstChild);
  let shown = false;

  const docked = () => legend.parentNode === slot;
  const label = () => { const t = docked() ? words.undock : words.dock; grip.title = t; grip.setAttribute('aria-label', t); };
  function dock(on){
    if(on && !docked()){ slot.appendChild(legend); legend.classList.add('docked'); }
    else if(!on && docked()){ home.parentNode.insertBefore(legend, home.nextSibling); legend.classList.remove('docked'); }
    label();
  }
  function sync(sideShown){
    shown = !!sideShown;
    grip.hidden = !shown;
    dock(shown && wanted());
  }

  grip.addEventListener('click', e => {
    if(grip.dataset.dragged === '1'){ grip.dataset.dragged = ''; return; }   // the end of a drag is not a click
    e.preventDefault();
    const on = !docked(); remember(on); dock(on); grip.focus();
  });

  // The drag: the key follows the pointer, fixed to the page; the side lights up while the pointer is over it.
  let start = null, dragging = false, box = null;
  const over = e => { const r = side.getBoundingClientRect(); return e.clientX >= r.left && e.clientX <= r.right && e.clientY >= r.top && e.clientY <= r.bottom; };
  grip.addEventListener('pointerdown', e => {
    if(e.button !== 0 || !shown) return;
    start = { x: e.clientX, y: e.clientY }; dragging = false;
    grip.setPointerCapture(e.pointerId);
  });
  grip.addEventListener('pointermove', e => {
    if(!start) return;
    if(!dragging){
      if(Math.hypot(e.clientX - start.x, e.clientY - start.y) < 5) return;
      dragging = true; box = legend.getBoundingClientRect();
      legend.classList.add('lg-dragging');
      Object.assign(legend.style, { position: 'fixed', left: box.left + 'px', top: box.top + 'px', width: box.width + 'px', right: 'auto', bottom: 'auto' });
    }
    legend.style.transform = `translate(${e.clientX - start.x}px, ${e.clientY - start.y}px)`;
    side.classList.toggle('cc-drop', over(e));
  });
  const end = e => {
    if(!start) return;
    const was = dragging; start = null; dragging = false;
    if(!was) return;
    grip.dataset.dragged = '1';
    legend.classList.remove('lg-dragging');
    ['position', 'left', 'top', 'width', 'right', 'bottom', 'transform'].forEach(k => { legend.style[k] = ''; });
    const into = e.type === 'pointerup' && over(e);
    side.classList.remove('cc-drop');
    remember(into); dock(into);
    setTimeout(() => { grip.dataset.dragged = ''; }, 0);
  };
  grip.addEventListener('pointerup', end);
  grip.addEventListener('pointercancel', end);

  label();
  return sync;
}
