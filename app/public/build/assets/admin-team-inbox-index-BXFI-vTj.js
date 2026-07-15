function L(){const u=()=>document.querySelector("meta[name=csrf-token]")?.content||"",c=(t,e={})=>fetch(t,{credentials:"same-origin",headers:{Accept:"application/json","X-Requested-With":"XMLHttpRequest","X-CSRF-TOKEN":u(),...e.body?{"Content-Type":"application/json"}:{},...e.headers||{}},...e,body:e.body&&typeof e.body!="string"?JSON.stringify(e.body):e.body}).then(async a=>{const i=await a.json().catch(()=>({}));if(!a.ok){const o=i?.message||`HTTP ${a.status}`;throw new Error(o)}return i}),n=(t,e=document)=>e.querySelector(t),m=(t,e=document)=>Array.from(e.querySelectorAll(t)),d=(t,e="info")=>{const a=n("#toast");a&&(a.textContent=t,a.className=`toast toast-${e}`,a.style.display="block",clearTimeout(a._t),a._t=setTimeout(()=>a.style.display="none",3500))},s={permissions:{},workspaces:[],tab:"sla_breach",workspaceFilter:"",items:[],active:null,targetWorkspaceId:null};async function f(){try{const t=await c("/admin/team-inbox/api/bootstrap");s.permissions=t.permissions||{},s.workspaces=t.workspaces||[],b(),k(),w(),await l(),x()}catch(t){d("Bootstrap failed: "+t.message,"error")}}function b(){const t=s.workspaces;n('[data-stat="workspaces"]').textContent=t.length,n('[data-stat="open_total"]').textContent=t.reduce((e,a)=>e+(a.open_count||0),0),n('[data-stat="breach_total"]').textContent=t.reduce((e,a)=>e+(a.breach_count||0),0)}function k(){const t=n("#adm-workspace-list");t&&(t.innerHTML=s.workspaces.slice(0,30).map(e=>`
          <div class="px-5 py-3 border-b border-paper-200 flex items-center gap-3">
            <div class="flex-1 min-w-0">
              <div class="font-semibold text-[12.5px] truncate">${r(e.name)}</div>
              <div class="text-[10.5px] text-ink-500 font-mono truncate">${r(e.slug||"")} · ${r(e.plan||"starter")}</div>
            </div>
            <div class="text-right text-[11px] text-ink-700">
              <div>${e.open_count||0} open</div>
              ${e.breach_count>0?`<div class="text-accent-coral">${e.breach_count} breach</div>`:""}
            </div>
          </div>
        `).join(""))}function w(){const t=n("#adm-workspace-filter");if(!t)return;const e=['<option value="">All workspaces</option>'].concat(s.workspaces.map(a=>`<option value="${a.id}">${r(a.name)}</option>`));t.innerHTML=e.join("")}async function l(){try{const t=new URLSearchParams({tab:s.tab});s.workspaceFilter&&t.set("workspace_id",s.workspaceFilter);const e=await c(`/admin/team-inbox/api/queue?${t}`);s.items=e.items||[],h()}catch(t){d("Queue: "+t.message,"error")}}function h(){const t=n("#adm-conv-list");if(t){if(s.items.length===0){t.innerHTML='<div class="px-5 py-12 text-center text-[12px] text-ink-500">No conversations match this filter.</div>';return}t.innerHTML=s.items.map(e=>`
          <button data-conv-id="${e.id}" data-workspace-id="${e.workspace_id}" class="adm-row w-full text-left px-5 py-3 border-b border-paper-200 hover:bg-paper-50 flex items-center gap-3">
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2">
                <span class="font-semibold text-[12.5px] truncate">${r(e.title||"Untitled")}</span>
                ${e.sla_breached?'<span class="px-1.5 py-0.5 rounded text-[9.5px] font-mono bg-accent-coral/20 text-accent-coral">SLA</span>':""}
                ${e.is_spam?'<span class="px-1.5 py-0.5 rounded text-[9.5px] font-mono bg-paper-100 text-ink-500">SPAM</span>':""}
              </div>
              <div class="text-[11.5px] text-ink-500 truncate">${r(e.preview||"")}</div>
              <div class="text-[10.5px] font-mono text-ink-500 mt-0.5">${r(e.workspace_name||"")} · ${r(e.assignee_name||"unassigned")} · ${r(e.team_name||"—")}</div>
            </div>
            <div class="text-right text-[10.5px] font-mono text-ink-500 shrink-0">
              ${p(e.last_message_at)}
            </div>
          </button>
        `).join(""),t.querySelectorAll("[data-conv-id]").forEach(e=>{e.addEventListener("click",()=>v(parseInt(e.dataset.convId,10),parseInt(e.dataset.workspaceId,10)))})}}async function v(t,e){s.targetWorkspaceId=e,n("#adm-drawer").classList.remove("hidden");try{const a=await c(`/admin/team-inbox/api/conversations/${t}`);s.active=a.conversation,s.active.id=t,n("#adm-drawer-title").textContent=a.conversation?.title||"Conversation",n("#adm-drawer-workspace").textContent=a.conversation?.workspace?.name||"",y(a.messages||[]),g(a.notes||[],a.platform_notes||[]),$(a.events||[])}catch(a){d("Conversation: "+a.message,"error")}}function y(t){const e=n("#adm-drawer-thread");e&&(e.innerHTML=t.map(a=>{const i=a.direction==="out";return`<div class="${i?"ml-8":"mr-8"}">
              <div class="${i?"bg-wa-bubble":"bg-paper-50"} border border-paper-200 rounded-md px-3 py-1.5 text-[12px] whitespace-pre-wrap break-words">${r(a.body||"")}</div>
              <div class="text-[9.5px] font-mono text-ink-500 mt-0.5 ${i?"text-right":""}">${p(a.created_at)} · ${r(a.direction)}</div>
            </div>`}).join("")||'<div class="text-[11.5px] text-ink-500">No messages yet.</div>')}function g(t,e){const a=n("#adm-drawer-notes");a&&(a.innerHTML=t.map(o=>`
              <div class="text-[11.5px] bg-accent-amber/10 border border-accent-amber/30 rounded-md px-2.5 py-1.5">
                <div class="font-mono text-[9.5px] text-ink-700 mb-0.5">${r(o.author_id||"")} · ${p(o.created_at)}</div>
                <div>${r(o.body)}</div>
              </div>`).join("")||'<div class="text-[11.5px] text-ink-500">No internal notes.</div>');const i=n("#adm-drawer-platform-notes");i&&(i.innerHTML=e.map(o=>`
              <div class="text-[11.5px] border-l-2 border-wa-deep pl-2.5 py-1">
                <div class="font-mono text-[9.5px] text-ink-700 mb-0.5">${r(o.admin_name||"")} · ${r(o.severity||"info")} · ${p(o.created_at)}</div>
                <div>${r(o.body)}</div>
              </div>`).join("")||'<div class="text-[11.5px] text-ink-500">No platform notes yet.</div>')}function $(t){const e=n("#adm-drawer-events");e&&(e.innerHTML=t.map(a=>`
          <div class="flex items-center gap-2">
            <span class="font-mono text-[9.5px] uppercase tracking-wider text-ink-500 w-32 shrink-0">${r(a.type)}</span>
            <span class="flex-1">${p(a.created_at)}</span>
          </div>
        `).join("")||'<div class="text-ink-500">No events.</div>')}async function x(){try{const t=await c("/admin/team-inbox/api/audit?layer=platform"),e=n("#adm-audit-list");if(!e)return;e.innerHTML=(t||[]).slice(0,50).map(a=>`
              <div class="px-5 py-2 border-b border-paper-200 text-[11.5px] flex items-center gap-3">
                <span class="font-mono text-[10px] uppercase tracking-wider text-ink-500 w-40 shrink-0">${r(a.action)}</span>
                <span class="text-ink-700 flex-1 truncate">workspace_id ${a.workspace_id??"—"}</span>
                <span class="font-mono text-[10px] text-ink-500 shrink-0">${p(a.created_at)}</span>
              </div>`).join("")||'<div class="px-5 py-4 text-[11.5px] text-ink-500">No audit events yet.</div>'}catch{}}m("#adm-tabs [data-adm-tab]").forEach(t=>{t.addEventListener("click",()=>{m("#adm-tabs .ti-tab").forEach(e=>e.classList.remove("active")),t.classList.add("active"),s.tab=t.dataset.admTab,l()})}),n("#adm-workspace-filter")?.addEventListener("change",t=>{s.workspaceFilter=t.target.value,l()}),m("[data-close-drawer]").forEach(t=>t.addEventListener("click",()=>{n("#adm-drawer")?.classList.add("hidden")})),n("#adm-spam-btn")?.addEventListener("click",async()=>{if(s.active&&confirm("Mark this conversation as spam?"))try{await c(`/admin/team-inbox/api/conversations/${s.active.id}/spam`,{method:"POST"}),d("Flagged as spam.","success"),n("#adm-drawer")?.classList.add("hidden"),l()}catch(t){d("Failed: "+t.message,"error")}}),n("#adm-platform-note-save")?.addEventListener("click",async()=>{const t=n("#adm-platform-note").value.trim(),e=n("#adm-platform-note-severity").value;if(!(!t||!s.active))try{await c(`/admin/team-inbox/api/conversations/${s.active.id}/note`,{method:"POST",body:{body:t,severity:e}}),n("#adm-platform-note").value="",d("Platform note added.","success"),v(s.active.id,s.targetWorkspaceId),x()}catch(a){d("Failed: "+a.message,"error")}}),n("#adm-impersonate-btn")?.addEventListener("click",()=>{if(!s.targetWorkspaceId)return d("No target workspace.","error");n("#adm-impersonate-modal")?.classList.remove("hidden"),n("#adm-impersonate-reason").value="",n("#adm-impersonate-reason").focus()}),n("#adm-impersonate-cancel")?.addEventListener("click",()=>{n("#adm-impersonate-modal")?.classList.add("hidden")}),n("#adm-impersonate-confirm")?.addEventListener("click",()=>{const t=n("#adm-impersonate-reason").value.trim();if(t.length<8)return d("Reason must be at least 8 characters.","error");const e=document.createElement("form");e.method="POST",e.action=`/admin/impersonate/${s.targetWorkspaceId}`,e.style.display="none";const a=document.createElement("input");a.name="_token",a.value=u();const i=document.createElement("input");i.name="reason",i.value=t,e.appendChild(a),e.appendChild(i),document.body.appendChild(e),e.submit()});function r(t){return String(t??"").replace(/[&<>"']/g,e=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"})[e])}function p(t){if(!t)return"";const e=new Date(t),a=new Date;return e.toDateString()===a.toDateString()?e.toTimeString().slice(0,5):e.toLocaleDateString(void 0,{month:"short",day:"numeric"})}f()}export{L as default};
