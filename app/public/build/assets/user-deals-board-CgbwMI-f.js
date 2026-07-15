function F(){const m=document.getElementById("dl-board");if(!m)return;const _=m.dataset.stageUrl||"/deals",$=m.dataset.csrf||document.querySelector('meta[name="csrf-token"]')?.content||"",o=(t,e="info")=>{if(window.toast)return window.toast(t,e);e==="error"?console.error(t):console.log(t)};let h=null;m.querySelectorAll(".dl-card").forEach(A);try{const t=new URLSearchParams(window.location.search).get("deal");t&&/^\d+$/.test(t)&&x(t)}catch{}function A(t){let e=!1;t.addEventListener("dragstart",a=>{if(e=!0,h=t,t.classList.add("dragging"),a.dataTransfer){a.dataTransfer.effectAllowed="move";try{a.dataTransfer.setData("text/plain",t.dataset.dealId||"")}catch{}}}),t.addEventListener("dragend",()=>{t.classList.remove("dragging"),h=null,setTimeout(()=>{e=!1},0)}),t.addEventListener("click",()=>{e||x(t.dataset.dealId)})}m.querySelectorAll(".dl-col").forEach(t=>{t.addEventListener("dragover",e=>{e.preventDefault(),e.dataTransfer&&(e.dataTransfer.dropEffect="move"),t.classList.add("dragover")}),t.addEventListener("dragleave",()=>t.classList.remove("dragover")),t.addEventListener("drop",e=>{if(e.preventDefault(),t.classList.remove("dragover"),!h)return;const a=h.closest(".dl-col");if(a===t)return;const s=h.dataset.dealId,d=t.dataset.stageId,c=t.querySelector("[data-drop]"),g=c.querySelector("[data-empty]");g&&g.remove();const b=h;c.appendChild(b),S(),D(s,d).then(w=>{w?.stage?.is_won?o("Deal marked Won","success"):w?.stage?.is_lost?o("Deal marked Lost","info"):o("Deal moved","success")}).catch(w=>{a.querySelector("[data-drop]").appendChild(b),S(),o(w?.message||"Could not move the deal.","error")})})});async function D(t,e){const a=await fetch(`${_}/${t}/stage`,{method:"PATCH",headers:{"Content-Type":"application/json","X-CSRF-TOKEN":$,Accept:"application/json"},body:JSON.stringify({stage_id:Number(e)})}),s=await a.json().catch(()=>({}));if(!a.ok||!s.ok)throw new Error(s.message||`HTTP ${a.status}`);return s}function j(t){if(!t||!t.id)return;const e=m.querySelector(`.dl-card[data-deal-id="${t.id}"]`);if(!e)return;const a=e.querySelector("[data-card-title]");a&&typeof t.title=="string"&&(a.textContent=t.title);const s=e.querySelector(".dl-amount");s&&t.value_display!=null&&(s.textContent=t.value_display);const d=m.querySelector(`.dl-col[data-stage-id="${t.stage_id}"]`),c=e.closest(".dl-col");if(d&&c!==d){const g=d.querySelector("[data-drop]"),b=g.querySelector("[data-empty]");b&&b.remove(),g.appendChild(e),S()}}function S(){m.querySelectorAll(".dl-col").forEach(t=>{const e=t.querySelectorAll(".dl-card").length,a=t.querySelector("[data-count]");a&&(a.textContent=`${e}`)})}const k=document.getElementById("dl-new-modal"),E=document.getElementById("dl-new-form"),O=()=>k?.classList.add("open"),T=()=>k?.classList.remove("open");document.querySelectorAll("[data-deal-new]").forEach(t=>t.addEventListener("click",O)),document.querySelectorAll("[data-deal-cancel]").forEach(t=>t.addEventListener("click",T)),k?.addEventListener("click",t=>{t.target===k&&T()}),E?.addEventListener("submit",async t=>{t.preventDefault();const e=E.querySelector('button[type="submit"]');e&&(e.disabled=!0,e.textContent="…");const a=new FormData(E),s=Object.fromEntries(a.entries());try{const d=await fetch(_,{method:"POST",headers:{"Content-Type":"application/json","X-CSRF-TOKEN":$,Accept:"application/json"},body:JSON.stringify(s)}),c=await d.json().catch(()=>({}));if(!d.ok||!c.ok)throw new Error(c.message||`HTTP ${d.status}`);o("Deal created","success"),window.location.reload()}catch(d){o(d?.message||"Could not create the deal.","error"),e&&(e.disabled=!1,e.textContent="Create deal")}});const v=document.getElementById("dl-settings-modal"),C=document.getElementById("dl-settings-form");document.querySelectorAll("[data-deal-settings]").forEach(t=>t.addEventListener("click",()=>v?.classList.add("open"))),document.querySelectorAll("[data-settings-cancel]").forEach(t=>t.addEventListener("click",()=>v?.classList.remove("open"))),v?.addEventListener("click",t=>{t.target===v&&v.classList.remove("open")}),C?.addEventListener("submit",async t=>{t.preventDefault();const e=new FormData(C),a={auto_from_orders:e.get("auto_from_orders")?1:0,min_value:e.get("min_value")||null};try{const s=await fetch(v.dataset.url,{method:"POST",headers:{"Content-Type":"application/json","X-CSRF-TOKEN":$,Accept:"application/json"},body:JSON.stringify(a)}),d=await s.json().catch(()=>({}));if(!s.ok||!d.ok)throw new Error(d.message||`HTTP ${s.status}`);o("Settings saved","success"),v.classList.remove("open")}catch(s){o(s?.message||"Could not save settings.","error")}});const f=document.getElementById("dl-panel"),i=f?.dataset.base||"/deals",M=f?.dataset.chat||"/chat",l=f?.querySelector("[data-panel-body]");let r=null,y=!1;const n=t=>String(t??"").replace(/[&<>"']/g,e=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"})[e]),P=(t,e)=>{let a;return(...s)=>{clearTimeout(a),a=setTimeout(()=>t(...s),e)}};function L(){f?.classList.remove("open"),r=null,y&&(y=!1,window.location.reload())}f?.addEventListener("click",t=>{t.target===f&&L()});async function u(t,e={}){const a=await fetch(t,{headers:{"Content-Type":"application/json","X-CSRF-TOKEN":$,Accept:"application/json"},...e}),s=await a.json().catch(()=>({}));if(!a.ok||s.ok===!1)throw new Error(s.message||`HTTP ${a.status}`);return s}async function x(t){if(f){r=t,f.classList.add("open"),l.innerHTML='<div class="text-center text-ink-400 py-20 text-sm">Loading…</div>';try{B(await u(`${i}/${t}`))}catch(e){l.innerHTML=`<div class="text-center text-ink-500 py-20 text-sm">${n(e.message||"Failed to load")}</div>`}}}function q(t){const e=t.type==="stage_change"?t.label||"Stage changed":t.body||"",a=t.type==="task"?`<button type="button" data-task-done="${t.id}" class="${t.done?"text-wa-deep":"text-ink-400"} hover:underline">${t.done?"Done":"Mark done"}</button>`:"";return`<div class="dl-act">
            <div class="text-[12px] text-ink-900">${n(e)}</div>
            <div class="flex items-center gap-2 text-[11px] text-ink-400 mt-0.5">
                <span>${n(t.user_name)}</span><span>·</span><span>${n(t.created_at)}</span>
                ${t.due_at?`<span>· due ${n(t.due_at)}</span>`:""}${a}
            </div></div>`}function B(t){const e=t.deal,a=t.stages||[],s=t.members||[],d=t.activities||[],c=e.status==="won"?"dl-badge-won":e.status==="lost"?"dl-badge-lost":"dl-badge-open",g=a.map(p=>`<option value="${p.id}" ${p.id===e.stage_id?"selected":""}>${n(p.name)}</option>`).join(""),b='<option value="">Unassigned</option>'+s.map(p=>`<option value="${p.id}" ${p.id===e.owner_user_id?"selected":""}>${n(p.name)}</option>`).join(""),w=p=>(String(p||"?").match(/[a-z]+/gi)||["?"]).slice(0,2).map(H=>H[0]).join("").toUpperCase(),I=e.contact?`
            <div class="rounded-xl border border-paper-200 p-3">
                <div class="flex items-center gap-3">
                    <span class="w-9 h-9 rounded-full bg-wa-deep text-paper-0 grid place-items-center text-[12px] font-semibold shrink-0">${n(w(e.contact.name))}</span>
                    <div class="min-w-0 flex-1">
                        <div class="text-[13px] font-semibold text-ink-900 truncate">${n(e.contact.name)}</div>
                        <div class="text-[12px] text-ink-500">${n(e.contact.phone)}</div>
                    </div>
                </div>
                ${e.contact.wa_phone?`
                <div class="flex gap-2 mt-3">
                    <a href="https://wa.me/${n(e.contact.wa_phone)}" target="_blank" rel="noopener" class="dl-btn dl-btn-primary text-center flex-1 inline-flex items-center justify-center gap-1.5">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.76.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.9-4.45 9.9-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2Zm5.8 14.06c-.24.68-1.42 1.32-1.95 1.36-.5.04-.5.42-3.16-.66-2.66-1.08-4.32-3.82-4.45-4-.13-.18-1.06-1.41-1.06-2.69 0-1.28.67-1.91.91-2.17.24-.26.52-.32.7-.32l.5.01c.16.01.38-.06.59.45.24.58.81 2 .88 2.14.07.14.12.31.02.49-.09.18-.14.29-.27.45-.14.16-.29.36-.41.48-.14.14-.28.29-.12.57.16.28.71 1.17 1.53 1.9 1.05.94 1.94 1.23 2.22 1.37.28.14.44.12.6-.07.18-.21.69-.81.87-1.09.18-.28.36-.23.6-.14.24.09 1.55.73 1.81.87.27.14.44.21.51.32.07.12.07.66-.17 1.34Z"/></svg>
                        Message
                    </a>
                    <a href="${e.conversation_id?`/team-inbox?c=${n(e.conversation_id)}`:`${M}?to=${n(e.contact.wa_phone)}`}" class="dl-btn dl-btn-ghost text-center">${e.conversation_id?"Open in inbox":"Open chat"}</a>
                </div>`:""}
            </div>`:`
            <div class="rounded-xl border border-dashed border-paper-200 p-3 text-[12px] text-ink-500">
                No contact linked yet — search to connect this deal to a person.
                <input type="text" data-contact-search placeholder="Search contacts…" class="dl-field mt-2">
                <div data-contact-results class="mt-1"></div>
            </div>`,N=d.length?d.map(q).join(""):'<div class="text-[12px] text-ink-400 py-3">No activity yet — add a note, log a call, or set a task above.</div>';l.innerHTML=`
            <div class="dl-phead">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex-1 min-w-0">
                        <div class="dl-eyebrow" style="margin-bottom:3px">Deal · via ${n(e.source)}</div>
                        <input type="text" data-edit="title" value="${n(e.title)}" class="dl-title-input" aria-label="Deal name">
                    </div>
                    <div class="flex items-center gap-1 shrink-0">
                        <button type="button" data-delete class="dl-iconbtn dl-iconbtn-danger" aria-label="Delete deal" title="Delete deal">
                            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14M10 11v5M14 11v5"/></svg>
                        </button>
                        <button type="button" data-close class="dl-iconbtn" aria-label="Close">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                        </button>
                    </div>
                </div>
                <div class="flex items-center gap-2.5 mt-2.5">
                    <span class="dl-amount-lg">${n(e.value_display)}</span>
                    <span class="dl-badge ${c}">${n(e.status.toUpperCase())}</span>
                    ${e.stage_name?`<span class="text-[11px] text-ink-500">${n(e.stage_name)}</span>`:""}
                </div>
            </div>

            <div class="dl-pbody">
                <section class="mb-5">
                    <div class="dl-eyebrow">Customer</div>
                    ${I}
                </section>

                <section class="mb-5">
                    <div class="dl-eyebrow">Deal details</div>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="text-[11px] font-semibold text-ink-500">Value
                            <input type="number" min="0" step="0.01" data-edit="value" value="${e.value}" class="dl-field mt-1">
                        </label>
                        <label class="text-[11px] font-semibold text-ink-500">Stage
                            <select data-edit="stage_id" class="dl-field mt-1">${g}</select>
                        </label>
                        <label class="text-[11px] font-semibold text-ink-500">Owner
                            <select data-edit="owner_user_id" class="dl-field mt-1">${b}</select>
                        </label>
                        <label class="text-[11px] font-semibold text-ink-500">Expected close
                            <input type="date" data-edit="expected_close_date" value="${n(e.expected_close_date||"")}" class="dl-field mt-1">
                        </label>
                    </div>
                    <div class="flex gap-2 mt-3">
                        <button type="button" data-won class="dl-btn dl-btn-primary flex-1">Mark Won</button>
                        <button type="button" data-lost class="dl-btn dl-btn-ghost flex-1">Mark Lost</button>
                    </div>
                    ${e.lost_reason?`<div class="text-[12px] text-ink-500 mt-2">Lost reason: ${n(e.lost_reason)}</div>`:""}
                </section>

                <section>
                    <div class="dl-eyebrow">Activity</div>
                    <div class="flex items-center gap-2 mb-2">
                        <select data-act-type class="dl-field" style="max-width:130px">
                            <option value="note">Note</option>
                            <option value="task">Task</option>
                            <option value="call">Call log</option>
                        </select>
                        <input type="date" data-act-due class="dl-field" style="max-width:150px;display:none">
                    </div>
                    <textarea data-act-body rows="2" placeholder="Add a note, log a call, or create a task…" class="dl-field"></textarea>
                    <button type="button" data-act-add class="dl-btn dl-btn-primary mt-2">Add to timeline</button>
                    <div data-timeline class="mt-4">${N}</div>
                </section>
            </div>`}l?.addEventListener("change",async t=>{const e=t.target;if(e.matches("[data-act-type]")){const a=l.querySelector("[data-act-due]");a&&(a.style.display=e.value==="task"?"":"none");return}if(e.matches("[data-edit]")){const a={};a[e.dataset.edit]=e.value;try{const s=await u(`${i}/${r}`,{method:"PATCH",body:JSON.stringify(a)});y=!0,s&&s.deal&&j(s.deal),o("Saved","success"),(e.dataset.edit==="stage_id"||e.dataset.edit==="value")&&x(r)}catch(s){o(s.message,"error")}}}),l?.addEventListener("input",P(async t=>{if(!t.target.matches("[data-contact-search]"))return;const e=l.querySelector("[data-contact-results]");if(e)try{const a=await u(`${i}/contacts/search?q=${encodeURIComponent(t.target.value.trim())}`);e.innerHTML=a.data.map(s=>`<button type="button" data-link-contact="${s.id}" class="block w-full text-left text-[12px] py-1 hover:text-wa-deep">${n(s.name)} · ${n(s.phone)}</button>`).join("")}catch{}},250)),l?.addEventListener("click",async t=>{const e=t.target.closest("[data-close],[data-won],[data-lost],[data-act-add],[data-task-done],[data-delete],[data-link-contact]");if(e){if(e.hasAttribute("data-close"))return L();if(e.hasAttribute("data-won")){try{await u(`${i}/${r}/won`,{method:"POST",body:"{}"}),y=!0,o("Marked Won","success"),x(r)}catch(a){o(a.message,"error")}return}if(e.hasAttribute("data-lost")){try{await u(`${i}/${r}/lost`,{method:"POST",body:"{}"}),y=!0,o("Marked Lost","info"),x(r)}catch(a){o(a.message,"error")}return}if(e.hasAttribute("data-act-add")){const a=l.querySelector("[data-act-type]").value,s=l.querySelector("[data-act-body]").value.trim(),d=l.querySelector("[data-act-due]").value;if(!s)return;try{const c=await u(`${i}/${r}/activity`,{method:"POST",body:JSON.stringify({type:a,body:s,due_at:d||null})});l.querySelector("[data-timeline]").insertAdjacentHTML("afterbegin",q(c.activity)),l.querySelector("[data-act-body]").value="",o("Added","success")}catch(c){o(c.message,"error")}return}if(e.hasAttribute("data-task-done")){try{const a=await u(`${i}/${r}/task/${e.dataset.taskDone}/done`,{method:"POST",body:"{}"});e.textContent=a.done?"Done":"Mark done",e.classList.toggle("text-wa-deep",a.done),e.classList.toggle("text-ink-400",!a.done)}catch(a){o(a.message,"error")}return}if(e.hasAttribute("data-link-contact")){try{await u(`${i}/${r}`,{method:"PATCH",body:JSON.stringify({contact_id:e.dataset.linkContact})}),y=!0,x(r)}catch(a){o(a.message,"error")}return}if(e.hasAttribute("data-delete")){if(!confirm("Delete this deal? This cannot be undone."))return;try{await u(`${i}/${r}`,{method:"DELETE"}),o("Deal deleted","success"),y=!0,L()}catch(a){o(a.message,"error")}}}})}export{F as default};
