import u from"./apexcharts.esm-CfSzfZrp.js";function W(){function x(){const e=location.search.match(/tab=([a-z]+)/);return(e?e[1]:"overview").toLowerCase()}const A={overview:"Overview",orders:"Orders",products:"Products",customers:"Customers",catalog:"WhatsApp Catalog",analytics:"Analytics"},g={overview:['Bloomly <span class="italic woo-accent">wholesale</span>',"Performance overview of your active WooCommerce automations and store activity."],orders:["Orders","View and manage every order coming in from WooCommerce."],products:["Products","Browse the catalog synced from your WooCommerce store."],customers:["Customers","Customers synced from your WooCommerce store."],catalog:['WhatsApp <span class="italic woo-accent">catalog</span>',"Sync product cards into WhatsApp and share them in a single tap."],analytics:['Sales <span class="italic woo-accent">analytics</span>',"Forecast, year-over-year trends and per-product performance."]};function l(e){document.querySelectorAll("[data-pane]").forEach(o=>o.classList.toggle("hidden",o.dataset.pane!==e)),document.querySelectorAll("[data-tab]").forEach(o=>o.classList.toggle("active",o.dataset.tab===e)),document.getElementById("bc-tab").textContent=A[e]||"Overview";const[t,a]=g[e]||g.overview;document.getElementById("page-title").innerHTML=t,document.getElementById("page-desc").textContent=a,e==="analytics"&&setTimeout(P,50),e==="overview"&&setTimeout(R,50)}const k=[{name:"Nike",color:"#3FBE6E"},{name:"Adidas",color:"#E87A5D"},{name:"Puma",color:"#7B57C7"},{name:"New Balance",color:"#F2B5A0"},{name:"Converse",color:"#1F4540"},{name:"Reebok",color:"#92A29E"},{name:"Sketchers",color:"#3D7CD3"}],S=[{name:"Cloud Shift Lightweight Runner Pro Edition",price:8225,rating:5,color:"#D8F571",accent:"#1F4540"},{name:"Wave Strike Dynamic Boost Sneaker",price:9970,rating:4.7,color:"#F4B7DC",accent:"#7B57C7"},{name:"Titan Edge High Impact Stability Trainers",price:5410,rating:3.5,color:"#1F4540",accent:"#FBFAF6"},{name:"Velocity Boost Xtreme High Shock Absorbers",price:9140,rating:4.9,color:"#F2F2F2",accent:"#E87A5D"}],$=[{name:"Air Stride Pro 23",price:7890,rating:4.6,color:"#FCBFAB",accent:"#A1431F"},{name:"Glide Tempo Marathon",price:9450,rating:4.8,color:"#3FBE6E",accent:"#FBFAF6"},{name:"Stratos Cushion Walk",price:6320,rating:4.4,color:"#5BA0F2",accent:"#FBFAF6"},{name:"Sprint Aero Hybrid",price:8800,rating:4.9,color:"#1F4540",accent:"#D8F571"}],C=[{name:"Black Sports Jersey",units:78247},{name:"Stainless Steel Tumbler",units:21008},{name:"50-Piece Drawing Set",units:49083},{name:"11-Piece Crochet Hook Set",units:24781},{name:"Pink Travel Backpack",units:22446}],E=[{id:"#WC-3417",customer:"Anya Mehra",email:"anya@bloomly.in",total:"₹ 4,820",pay:"paid",ful:"fulfilled",date:"Today 14:42"},{id:"#WC-3416",customer:"Rahul Iyer",email:"rahul@example.com",total:"₹ 12,140",pay:"paid",ful:"unfulfilled",date:"Today 13:08"},{id:"#WC-3415",customer:"Guest",email:"—",total:"₹ 1,950",pay:"pending",ful:"unfulfilled",date:"Today 11:24"},{id:"#WC-3414",customer:"Priya Reddy",email:"priya@bloomly.in",total:"₹ 8,820",pay:"paid",ful:"fulfilled",date:"Yesterday"},{id:"#WC-3413",customer:"Kyung J.",email:"kyung@example.com",total:"₹ 3,210",pay:"refunded",ful:"fulfilled",date:"2 days ago"},{id:"#WC-3412",customer:"Vetrick R.",email:"vetrick@bloomly.in",total:"₹ 6,440",pay:"paid",ful:"partial",date:"2 days ago"}],T=[{name:"Anya Mehra",email:"anya@bloomly.in",phone:"+91 98765 43210",orders:14,spent:"₹ 1.8L",since:"Jan 2024"},{name:"Rahul Iyer",email:"rahul@example.com",phone:"+91 90220 18821",orders:9,spent:"₹ 92,400",since:"Mar 2024"},{name:"Priya Reddy",email:"priya@bloomly.in",phone:"+91 99888 11221",orders:21,spent:"₹ 2.4L",since:"Nov 2023"},{name:"Kyung Jin",email:"kyung@example.com",phone:"+82 10 1234 5678",orders:3,spent:"₹ 18,200",since:"Aug 2025"},{name:"Vetrick R.",email:"vetrick@bloomly.in",phone:"+91 98098 11345",orders:6,spent:"₹ 48,600",since:"Apr 2024"}],F=[{name:"Nike Air Max 270",rid:"SKU-AM270",price:"₹ 11,640",avail:"in stock",sync:"synced"},{name:"Nike Dunk Low",rid:"SKU-DLL01",price:"₹ 9,140",avail:"in stock",sync:"synced"},{name:"Nike Air Force 1",rid:"SKU-AF1-W",price:"₹ 8,050",avail:"low",sync:"syncing"},{name:"Adidas Ultraboost 22",rid:"SKU-UB22",price:"₹ 14,200",avail:"in stock",sync:"synced"},{name:"Puma RS-X Bold",rid:"SKU-RSX01",price:"₹ 7,300",avail:"out",sync:"failed"}],D=[{sku:"BLCK-SPRT-JRSY",name:"Black Sports Jersey",model:"AI model",d1:303,d7:1854,p7:3369,w:"+81.7%",d30:9736,p30:14246,m:"+46.3%",d90:21813,p90:26209,badge:"A"},{sku:"STLS-STLL-TBLR",name:"Stainless Steel Tumbler",model:"AI model",d1:42,d7:295,p7:652.6,w:"+121.2%",d30:2043,p30:3188,m:"+56.1%",d90:7543,p90:7490,badge:"B"},{sku:"50PC-DRWN-SETT",name:"50-Piece Drawing Set",model:"AI model · with adjustments",d1:113,d7:843,p7:1539,w:"+82.6%",d30:11173,p30:7098,m:"−36.5%",d90:18818,p90:13939,badge:"A"},{sku:"11PC-CRCH-HSET",name:"11-Piece Crochet Hook Set",model:"Moving average",d1:157,d7:880,p7:1047,w:"+19.0%",d30:4982,p30:3969,m:"−20.3%",d90:11001,p90:8459,badge:"B"},{sku:"GREY-CHNL-MATS",name:"Grey Chenille Bath Mat Set",model:"Moving average",d1:92,d7:741,p7:784.1,w:"+5.8%",d30:4553,p30:3738,m:"−17.9%",d90:8241,p90:7087,badge:"B"},{sku:"PINK-TRVL-BKPK",name:"Pink Travel Backpack",model:"AI model",d1:89,d7:661,p7:829.2,w:"+25.4%",d30:4125,p30:3187,m:"−22.7%",d90:7382,p90:7084,badge:"B"},{sku:"BLCK-YOGA-MATT",name:"Black yoga mat padded 1/2-inch with…",model:"AI model",d1:40,d7:270,p7:772.3,w:"+186.0%",d30:1387,p30:3434,m:"+147.6%",d90:3339,p90:7934,badge:"B"}],L=[{sku:"BLCK-SPRT-JRSY",name:"Black Sports Jersey",sales:3488512,perDay:9558,contrib:"+14.2%",vy:"−18.7%",units:78247,perDayU:214.4,avg:44.58,badge:"A"},{sku:"STLS-STLL-TBLR",name:"Stainless Steel Tumbler",sales:1561294,perDay:4278,contrib:"+6.3%",vy:"−45.3%",units:21008,perDayU:57.6,avg:74.32,badge:"B"},{sku:"50PC-DRWN-SETT",name:"50-Piece Drawing Set",sales:2261434,perDay:6196,contrib:"+9.2%",vy:"+137.3%",units:49083,perDayU:134.5,avg:46.07,badge:"A"},{sku:"11PC-CRCH-HSET",name:"11-Piece Crochet Hook Set",sales:1538218,perDay:4214,contrib:"+6.2%",vy:"+161.3%",units:24781,perDayU:67.9,avg:62.07,badge:"B"},{sku:"GREY-CHNL-MATS",name:"Grey Chenille Bath Mat Set",sales:1416454,perDay:3881,contrib:"+5.8%",vy:"+12.3%",units:24929,perDayU:68.3,avg:56.82,badge:"B"},{sku:"PINK-TRVL-BKPK",name:"Pink Travel Backpack",sales:1223456,perDay:3352,contrib:"+5.0%",vy:"+169.7%",units:22446,perDayU:61.5,avg:54.51,badge:"B"},{sku:"BLCK-YOGA-MATT",name:"Black yoga mat padded 1/2-inch with nylon strap.",sales:1232974,perDay:3378,contrib:"+5.0%",vy:"−34.2%",units:21605,perDayU:59.2,avg:57.07,badge:"B"},{sku:"BLCK-WOMN-SORT",name:"Black Women's Running Shorts with Adjustable Elastic Band",sales:971808,perDay:2662,contrib:"+3.9%",vy:"+70.0%",units:19507,perDayU:53.4,avg:49.82,badge:"C"},{sku:"FRVS-PWDR-MIXX",name:"Fruit, Veggies and Spices Powder Mix",sales:905229,perDay:2480,contrib:"+3.7%",vy:"+59.7%",units:13294,perDayU:36.4,avg:68.09,badge:"C"}];function M(e){return"₹ "+e.toLocaleString("en-IN")}function f(e){return e.toLocaleString("en-US")}function i(e){return`<span class="font-mono ${e.startsWith("−")||e.startsWith("-")?"delta-down":"delta-up"}">${e}</span>`}function c(e,t){return`<svg viewBox="0 0 200 110" class="w-full h-full">
          <ellipse cx="100" cy="92" rx="78" ry="5" fill="rgba(0,0,0,0.06)"/>
          <path d="M30 78 Q40 58 70 53 L130 48 Q160 48 175 73 L175 88 Q175 92 170 92 L40 92 Q30 92 30 86 Z" fill="${e}"/>
          <path d="M70 53 Q90 28 130 33 Q150 36 145 53" fill="${t}" opacity="0.75"/>
          <circle cx="100" cy="55" r="11" fill="${t}"/>
          <path d="M88 60 Q100 52 112 60" fill="none" stroke="${t}" stroke-width="2"/>
        </svg>`}document.getElementById("brand-chips").innerHTML=k.map(e=>`
        <a href="#" class="bg-paper-0 border border-paper-200 rounded-2xl p-3 hover:border-wa-deep hover:shadow-card transition flex flex-col items-center gap-2">
          <span class="text-[12px] font-semibold text-ink-900">${e.name}</span>
          <span class="w-full h-12 prod-img rounded-lg grid place-items-center">
            ${c(e.color,"#FBFAF6")}
          </span>
        </a>
      `).join("");function h(e){return Math.round(e.rating),`<div class="bg-paper-0 border border-paper-200 rounded-2xl p-3 shadow-card hover:border-wa-deep hover:shadow-soft transition">
          <div class="prod-img rounded-xl h-32 grid place-items-center">${c(e.color,e.accent)}</div>
          <div class="text-[12.5px] font-medium leading-snug mt-3 line-clamp-2 min-h-[36px]">${e.name}</div>
          <div class="mt-3 flex items-center justify-between">
            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full bg-accent-amber/20 text-[10px] font-mono text-ink-800">
              <svg viewBox="0 0 16 16" class="w-2.5 h-2.5" fill="#E5A04E"><path d="M8 1l2.2 4.4 4.8.7-3.5 3.4.8 4.8L8 12l-4.3 2.3.8-4.8L1 6.1l4.8-.7z"/></svg>
              ${e.rating.toFixed(1)}
            </span>
            <span class="font-semibold text-[13px]">${M(e.price)}</span>
            <button class="px-2 py-1 rounded-full border border-paper-200 hover:bg-paper-50 text-[10.5px] font-semibold inline-flex items-center gap-1">
              <svg viewBox="0 0 16 16" class="w-2.5 h-2.5" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 4h2l1.5 8h7l1-5H6"/></svg>
              Add
            </button>
          </div>
        </div>`}document.getElementById("new-arrivals").innerHTML=S.map(h).join(""),document.getElementById("popular-products").innerHTML=$.map(h).join(""),document.getElementById("top-products").innerHTML=C.map((e,t)=>`
        <li class="flex items-center gap-3">
          <span class="w-7 h-7 rounded-md grid place-items-center font-mono text-[11px] font-semibold bg-paper-50 border border-paper-200 text-ink-700">${t+1}</span>
          <div class="flex-1 min-w-0">
            <div class="text-[12.5px] font-medium truncate">${e.name}</div>
            <div class="font-mono text-[10px] text-ink-500">${f(e.units)} units</div>
          </div>
          <div class="w-20 h-1.5 rounded-full bg-paper-100 overflow-hidden">
            <div class="h-full bg-wa-deep" style="width:${Math.min(100,e.units/800)}%"></div>
          </div>
        </li>`).join(""),document.getElementById("automations-rows").innerHTML=[["Abandoned checkout","green","3,214","486","128","26.3%","₹ 6.2L"],["Order confirmation","green","512","512","512","100%","₹ 24.6L"],["Shipping update","green","418","402","388","96.5%","—"],["Review request","green","320","180","64","35.5%","—"],["Win-back · 30 days","amber","120","32","7","21.8%","₹ 38,200"],["Welcome new buyer","green","42","42","12","28.5%","₹ 1.6L"]].map(e=>`<tr class="hover:bg-paper-50/60">
        <td class="px-4 py-2.5 font-medium">${e[0]}</td>
        <td class="px-2 py-2.5"><span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10.5px] font-mono ${e[1]==="green"?"bg-wa-mint text-wa-deep":"bg-accent-amber/20 text-[#7B5A14]"}"><span class="w-1.5 h-1.5 rounded-full ${e[1]==="green"?"bg-wa-green":"bg-accent-amber"}"></span>${e[1]==="green"?"Active":"Draft"}</span></td>
        <td class="px-2 py-2.5 font-mono">${e[2]}</td>
        <td class="px-2 py-2.5 font-mono">${e[3]}</td>
        <td class="px-2 py-2.5 font-mono">${e[4]}</td>
        <td class="px-2 py-2.5 font-mono">${e[5]}</td>
        <td class="px-2 py-2.5 font-mono">${e[6]}</td>
        <td class="px-4 py-2.5 text-right text-ink-500"><svg viewBox="0 0 16 16" class="w-4 h-4 inline" fill="currentColor"><circle cx="3" cy="8" r="1.4"/><circle cx="8" cy="8" r="1.4"/><circle cx="13" cy="8" r="1.4"/></svg></td>
      </tr>`).join("");const v={paid:["bg-wa-mint","text-wa-deep","bg-wa-green"],pending:["bg-accent-amber/20","text-[#7B5A14]","bg-accent-amber"],refunded:["bg-[#E0EBF7]","text-[#13478A]","bg-[#3D7CD3]"]};document.getElementById("orders-rows").innerHTML=E.map(e=>{const t=v[e.pay]||v.pending;return`<tr class="hover:bg-paper-50/60">
          <td class="px-4 py-2.5 font-mono text-wa-deep font-semibold">${e.id}</td>
          <td class="px-2 py-2.5"><div class="font-medium">${e.customer}</div><div class="text-[10.5px] text-ink-500">${e.email}</div></td>
          <td class="px-2 py-2.5 font-semibold">${e.total}</td>
          <td class="px-2 py-2.5"><span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10.5px] font-mono ${t[0]} ${t[1]}"><span class="w-1.5 h-1.5 rounded-full ${t[2]}"></span>${e.pay}</span></td>
          <td class="px-2 py-2.5"><span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10.5px] font-mono ${e.ful==="fulfilled"?"bg-wa-mint text-wa-deep":e.ful==="partial"?"bg-accent-amber/20 text-[#7B5A14]":"bg-paper-50 text-ink-700"}">${e.ful}</span></td>
          <td class="px-2 py-2.5 font-mono text-[11px] text-ink-700">${e.date}</td>
        </tr>`}).join(""),document.getElementById("customers-rows").innerHTML=T.map(e=>`
        <tr class="hover:bg-paper-50/60">
          <td class="px-4 py-2.5 font-medium">${e.name}</td>
          <td class="px-2 py-2.5 text-ink-700">${e.email}</td>
          <td class="px-2 py-2.5 font-mono text-[11px] text-ink-700">${e.phone}</td>
          <td class="px-2 py-2.5 font-mono">${e.orders}</td>
          <td class="px-2 py-2.5 font-semibold">${e.spent}</td>
          <td class="px-2 py-2.5 font-mono text-[11px] text-ink-500">${e.since}</td>
        </tr>`).join("");const b={"in stock":["bg-wa-mint","text-wa-deep"],low:["bg-accent-amber/20","text-[#7B5A14]"],out:["bg-accent-coral/15","text-accent-coral"]},w={synced:["bg-wa-mint","text-wa-deep"],syncing:["bg-[#E0EBF7]","text-[#13478A]"],failed:["bg-accent-coral/15","text-accent-coral"]};document.getElementById("catalog-rows").innerHTML=F.map(e=>`
        <tr class="hover:bg-paper-50/60">
          <td class="px-4 py-2.5"><input type="checkbox"></td>
          <td class="px-2 py-2.5"><div class="w-9 h-9 rounded-lg prod-img grid place-items-center">${c("#3FBE6E","#FBFAF6")}</div></td>
          <td class="px-2 py-2.5 font-medium">${e.name}</td>
          <td class="px-2 py-2.5"><code class="font-mono text-[10.5px] bg-paper-50 px-1.5 py-0.5 rounded">${e.rid}</code></td>
          <td class="px-2 py-2.5 font-semibold">${e.price}</td>
          <td class="px-2 py-2.5"><span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10.5px] font-mono ${b[e.avail][0]} ${b[e.avail][1]}">${e.avail}</span></td>
          <td class="px-2 py-2.5"><span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10.5px] font-mono ${w[e.sync][0]} ${w[e.sync][1]}">${e.sync}</span></td>
        </tr>`).join("");function B(e){return`<span class="w-5 h-5 rounded-full grid place-items-center text-[9.5px] font-mono font-bold text-paper-0" style="background:${{A:"#3FBE6E",B:"#5BA0F2",C:"#E5A04E"}[e]||"#92A29E"}">${e}</span>`}function s(e){return typeof e=="number"?f(e):e}document.getElementById("forecast-rows").innerHTML=D.map(e=>`
        <tr class="hover:bg-paper-50/60">
          <td class="px-3 py-2.5">
            <div class="flex items-center gap-2">${B(e.badge)}
              <div class="min-w-0">
                <div class="font-mono text-[11px] text-ink-900">${e.sku}</div>
                <div class="text-[11px] text-ink-500 truncate max-w-[220px]">${e.name}</div>
              </div>
            </div>
          </td>
          <td class="px-3 py-2.5 text-ink-700">Amazon US</td>
          <td class="px-3 py-2.5 text-ink-700">${e.model}</td>
          <td class="text-right px-2 py-2.5 font-mono">${s(e.d1)}</td>
          <td class="text-right px-2 py-2.5 font-mono"><div>${s(e.d7)}</div><div class="text-[10px] text-ink-500">${(e.d7/7).toFixed(1)}/day</div></td>
          <td class="text-right px-2 py-2.5 font-mono"><div>${s(e.p7)}</div><div class="text-[10px] text-ink-500">${(e.p7/7).toFixed(1)}/day</div></td>
          <td class="text-right px-2 py-2.5">${i(e.w)}</td>
          <td class="text-right px-2 py-2.5 font-mono"><div>${s(e.d30)}</div><div class="text-[10px] text-ink-500">${(e.d30/30).toFixed(1)}/day</div></td>
          <td class="text-right px-2 py-2.5 font-mono"><div>${s(e.p30)}</div><div class="text-[10px] text-ink-500">${(e.p30/30).toFixed(1)}/day</div></td>
          <td class="text-right px-2 py-2.5">${i(e.m)}</td>
          <td class="text-right px-2 py-2.5 font-mono"><div>${s(e.d90)}</div><div class="text-[10px] text-ink-500">${(e.d90/90).toFixed(1)}/day</div></td>
          <td class="text-right px-2 py-2.5 font-mono"><div>${s(e.p90)}</div><div class="text-[10px] text-ink-500">${(e.p90/90).toFixed(1)}/day</div></td>
        </tr>`).join(""),document.getElementById("analytics-rows").innerHTML=L.map(e=>`
        <tr class="hover:bg-paper-50/60">
          <td class="px-3 py-2.5">
            <div class="flex items-center gap-2">${B(e.badge)}
              <div class="min-w-0">
                <div class="font-mono text-[11px] text-ink-900">${e.sku}</div>
                <div class="text-[11px] text-ink-500 truncate max-w-[260px]">${e.name}</div>
              </div>
            </div>
          </td>
          <td class="px-3 py-2.5 text-ink-700">Amazon US</td>
          <td class="text-right px-3 py-2.5 font-mono">${s(e.sales)}</td>
          <td class="text-right px-3 py-2.5 font-mono">${s(e.perDay)}</td>
          <td class="text-right px-3 py-2.5">${i(e.contrib)}</td>
          <td class="text-right px-3 py-2.5">${i(e.vy)}</td>
          <td class="text-right px-3 py-2.5 font-mono">${s(e.units)}</td>
          <td class="text-right px-3 py-2.5 font-mono">${e.perDayU}</td>
          <td class="text-right px-3 py-2.5 font-mono">${e.avg}</td>
        </tr>`).join("");let p,m,y;function R(){if(p||!document.getElementById("revenue-chart"))return;const e=[];for(let a=0;a<30;a++)e.push(5e4+Math.round(Math.sin(a/3)*8e3)+Math.round(Math.random()*15e3));const t=Array.from({length:30},(a,o)=>{const n=new Date;return n.setDate(n.getDate()-(29-o)),n.toLocaleDateString("en-US",{month:"short",day:"numeric"})});p=new u(document.getElementById("revenue-chart"),{chart:{type:"area",height:260,toolbar:{show:!1},fontFamily:"Plus Jakarta Sans"},series:[{name:"Revenue",data:e}],xaxis:{categories:t,labels:{style:{colors:"#6B807C",fontSize:"10px"}},axisBorder:{show:!1},axisTicks:{show:!1}},yaxis:{labels:{style:{colors:"#6B807C",fontSize:"10px"},formatter:a=>"₹"+(a/1e3).toFixed(0)+"k"}},stroke:{curve:"smooth",width:2.5,colors:["#7F54B3"]},fill:{type:"gradient",gradient:{shadeIntensity:1,opacityFrom:.35,opacityTo:.05,stops:[0,100],colorStops:[{offset:0,color:"#9D7BE0"},{offset:100,color:"#7F54B3"}]}},grid:{borderColor:"#EFEBE0",strokeDashArray:3},tooltip:{y:{formatter:a=>"₹ "+a.toLocaleString("en-IN")}},dataLabels:{enabled:!1}}),p.render()}function P(){if(!m&&document.getElementById("forecast-chart")){const e=[],t=[],a=new Date;a.setMonth(a.getMonth()-24);for(let n=0;n<365*2;n++){const r=new Date(a);r.setDate(a.getDate()+n);let d=1500+Math.round(Math.sin(n/30)*400)+Math.round(Math.random()*600);n%60<3&&(d+=3500),e.push([r.getTime(),d])}const o=e[e.length-1][0];for(let n=1;n<=365;n++){const r=new Date(o);r.setDate(r.getDate()+n);let d=1500+Math.round(Math.sin((730+n)/30)*400)+Math.round(Math.random()*500);n%60<3&&(d+=3e3),t.push([r.getTime(),d])}m=new u(document.getElementById("forecast-chart"),{chart:{type:"line",height:280,toolbar:{show:!1},fontFamily:"Plus Jakarta Sans"},series:[{name:"Adjusted sales",type:"area",data:e},{name:"Adjusted forecast",type:"line",data:t}],xaxis:{type:"datetime",labels:{style:{colors:"#6B807C",fontSize:"10px"}},axisBorder:{show:!1}},yaxis:{labels:{style:{colors:"#6B807C",fontSize:"10px"},formatter:n=>(n/1e3).toFixed(1)+"K"}},stroke:{width:[1.5,2],curve:"smooth",dashArray:[0,5],colors:["#E5A04E","#E5A04E"]},fill:{type:["gradient","solid"],gradient:{opacityFrom:.18,opacityTo:0,stops:[0,100]},colors:["#E5A04E","#E5A04E"]},grid:{borderColor:"#EFEBE0",strokeDashArray:3},legend:{show:!1},dataLabels:{enabled:!1},annotations:{xaxis:[{x:o,borderColor:"#92A29E",strokeDashArray:4,label:{text:"Today",style:{color:"#3A5A55",background:"#FBFAF6",fontSize:"10px"}}}]}}),m.render()}if(!y&&document.getElementById("yoy-chart")){const e=["Dec 2023","Jan 2024","Feb 2024","Mar 2024","Apr 2024","May 2024","Jun 2024","Jul 2024","Aug 2024","Sep 2024","Oct 2024","Nov 2024","Dec 2024"],t=[2.8,2.2,1.9,2,1.9,1.9,2,2,2.1,2,2.5,3.2,1.8],a=[2.6,2.5,1.7,1.7,1.6,1.7,1.7,1.7,1.8,1.9,2.4,2.6,.7];y=new u(document.getElementById("yoy-chart"),{chart:{type:"bar",height:300,toolbar:{show:!1},fontFamily:"Plus Jakarta Sans"},series:[{name:"Same 365 days, 1 year before",data:a},{name:"Last 365 days",data:t}],xaxis:{categories:e,labels:{style:{colors:"#6B807C",fontSize:"10px"}},axisBorder:{show:!1}},yaxis:{labels:{style:{colors:"#6B807C",fontSize:"10px"},formatter:o=>(window.WA_CURRENCY||"$")+o.toFixed(1)+"M"}},plotOptions:{bar:{columnWidth:"58%",borderRadius:3}},colors:["#D6CDB6","#3D7CD3"],grid:{borderColor:"#EFEBE0",strokeDashArray:3},legend:{show:!1},dataLabels:{enabled:!1},tooltip:{y:{formatter:o=>(window.WA_CURRENCY||"$")+o.toFixed(2)+"M"}}}),y.render()}}l(x()),document.querySelectorAll("[data-tab]").forEach(e=>e.addEventListener("click",t=>{t.preventDefault();const a=e.dataset.tab;history.pushState(null,"","?tab="+a),l(a),window.scrollTo({top:0,behavior:"smooth"})})),window.addEventListener("popstate",()=>l(x()))}export{W as default};
