const __vite__mapDeps=(i,m=__vite__mapDeps,d=(m.f||(m.f=["./Builder-gW1CNVfZ.js","./react-vendor-FDlpAmHA.js","./compatibility-CF7CyX-7.js","./common-DxLs3hWb.js","./builderConfig-CP-397iu.js","./ComponentSelector-DeAZf0mY.js","./loadCSV-DexEeo3a.js","./PriceBreakdown-zUIDasTG.js","./buildHistory-CJewa1k_.js","./state-vendor-DUd5oXVA.js","./AdminBuilder-CjzAckTd.js","./Summary-DTVbwopn.js","./performance-C6Scpg_y.js","./BottleneckCalculator-BTkAced4.js","./ComponentComparison-CsJlMCkY.js","./FpsCalculator-XyD1-RdU.js","./BuildHistory-CNxysQ9E.js","./AIGenerator-DSb6x6eW.js","./useLivePrices-G8JQxP2A.js","./GameSystemGenerator-CcePhFTC.js"])))=>i.map(i=>d[i]);
import{r as J,a as K,g as Q,b as x,u as V,L as S,c as X,O as Z,R as q,d as ee}from"./react-vendor-FDlpAmHA.js";import{c as te}from"./state-vendor-DUd5oXVA.js";(function(){const a=document.createElement("link").relList;if(a&&a.supports&&a.supports("modulepreload"))return;for(const r of document.querySelectorAll('link[rel="modulepreload"]'))o(r);new MutationObserver(r=>{for(const s of r)if(s.type==="childList")for(const i of s.addedNodes)i.tagName==="LINK"&&i.rel==="modulepreload"&&o(i)}).observe(document,{childList:!0,subtree:!0});function n(r){const s={};return r.integrity&&(s.integrity=r.integrity),r.referrerPolicy&&(s.referrerPolicy=r.referrerPolicy),r.crossOrigin==="use-credentials"?s.credentials="include":r.crossOrigin==="anonymous"?s.credentials="omit":s.credentials="same-origin",s}function o(r){if(r.ep)return;r.ep=!0;const s=n(r);fetch(r.href,s)}})();var I={exports:{}},R={};/**
 * @license React
 * react-jsx-runtime.production.min.js
 *
 * Copyright (c) Facebook, Inc. and its affiliates.
 *
 * This source code is licensed under the MIT license found in the
 * LICENSE file in the root directory of this source tree.
 */var O;function re(){if(O)return R;O=1;var t=J(),a=Symbol.for("react.element"),n=Symbol.for("react.fragment"),o=Object.prototype.hasOwnProperty,r=t.__SECRET_INTERNALS_DO_NOT_USE_OR_YOU_WILL_BE_FIRED.ReactCurrentOwner,s={key:!0,ref:!0,__self:!0,__source:!0};function i(c,d,h){var m,f={},g=null,y=null;h!==void 0&&(g=""+h),d.key!==void 0&&(g=""+d.key),d.ref!==void 0&&(y=d.ref);for(m in d)o.call(d,m)&&!s.hasOwnProperty(m)&&(f[m]=d[m]);if(c&&c.defaultProps)for(m in d=c.defaultProps,d)f[m]===void 0&&(f[m]=d[m]);return{$$typeof:a,type:c,key:g,ref:y,props:f,_owner:r.current}}return R.Fragment=n,R.jsx=i,R.jsxs=i,R}var B;function se(){return B||(B=1,I.exports=re()),I.exports}var e=se(),E={},z;function ne(){if(z)return E;z=1;var t=K();return E.createRoot=t.createRoot,E.hydrateRoot=t.hydrateRoot,E}var oe=ne();const ae=Q(oe),ie="modulepreload",le=function(t,a){return new URL(t,a).href},F={},_=function(a,n,o){let r=Promise.resolve();if(n&&n.length>0){let i=function(m){return Promise.all(m.map(f=>Promise.resolve(f).then(g=>({status:"fulfilled",value:g}),g=>({status:"rejected",reason:g}))))};const c=document.getElementsByTagName("link"),d=document.querySelector("meta[property=csp-nonce]"),h=(d==null?void 0:d.nonce)||(d==null?void 0:d.getAttribute("nonce"));r=i(n.map(m=>{if(m=le(m,o),m in F)return;F[m]=!0;const f=m.endsWith(".css"),g=f?'[rel="stylesheet"]':"";if(!!o)for(let l=c.length-1;l>=0;l--){const p=c[l];if(p.href===m&&(!f||p.rel==="stylesheet"))return}else if(document.querySelector(`link[href="${m}"]${g}`))return;const v=document.createElement("link");if(v.rel=f?"stylesheet":ie,f||(v.as="script"),v.crossOrigin="",v.href=m,h&&v.setAttribute("nonce",h),document.head.appendChild(v),f)return new Promise((l,p)=>{v.addEventListener("load",l),v.addEventListener("error",()=>p(new Error(`Unable to preload CSS for ${m}`)))})}))}function s(i){const c=new Event("vite:preloadError",{cancelable:!0});if(c.payload=i,window.dispatchEvent(c),!c.defaultPrevented)throw i}return r.then(i=>{for(const c of i||[])c.status==="rejected"&&s(c.reason);return a().catch(s)})},$="pctg_users";function D(){try{return JSON.parse(localStorage.getItem($))||{}}catch{return{}}}function ce(t){localStorage.setItem($,JSON.stringify(t))}async function L(t){const n=new TextEncoder().encode(t),o=await crypto.subtle.digest("SHA-256",n);return Array.from(new Uint8Array(o)).map(r=>r.toString(16).padStart(2,"0")).join("")}function de({mode:t="login",onClose:a,onSwitch:n,onSuccess:o}){const[r,s]=x.useState(""),[i,c]=x.useState(""),[d,h]=x.useState(""),[m,f]=x.useState(""),[g,y]=x.useState(""),v=async l=>{if(l.preventDefault(),y(""),t==="login"){const b=D()[r];if(!b){y("No account found with this email.");return}const u=await L(i);if(b.password!==u){y("Incorrect password.");return}o&&o({email:r,name:b.name})}else{if(i!==d){y("Passwords do not match.");return}if(i.length<6){y("Password must be at least 6 characters.");return}const p=D();if(p[r]){y("An account with this email already exists.");return}const b=await L(i);p[r]={name:m,password:b,createdAt:new Date().toISOString()},ce(p),o&&o({email:r,name:m})}};return e.jsx("div",{className:"modal-overlay",onClick:a,children:e.jsxs("div",{className:"modal-box",onClick:l=>l.stopPropagation(),children:[e.jsx("h2",{children:t==="login"?"Login":"Register"}),e.jsxs("form",{onSubmit:v,children:[t==="signup"&&e.jsxs("div",{className:"field",children:[e.jsx("label",{children:"Name"}),e.jsx("input",{type:"text",value:m,onChange:l=>f(l.target.value),placeholder:"Your name",required:!0})]}),e.jsxs("div",{className:"field",children:[e.jsx("label",{children:"E-mail Address"}),e.jsx("input",{type:"email",value:r,onChange:l=>s(l.target.value),placeholder:"you@example.com",required:!0})]}),e.jsxs("div",{className:"field",children:[e.jsx("label",{children:"Password"}),e.jsx("input",{type:"password",value:i,onChange:l=>c(l.target.value),placeholder:"Your password",required:!0})]}),t==="signup"&&e.jsxs("div",{className:"field",children:[e.jsx("label",{children:"Confirm Password"}),e.jsx("input",{type:"password",value:d,onChange:l=>h(l.target.value),placeholder:"Confirm password",required:!0})]}),g&&e.jsx("div",{style:{padding:"8px 12px",background:"rgba(239,68,68,0.1)",borderRadius:"6px",border:"1px solid rgba(239,68,68,0.2)",color:"#ef4444",fontSize:"12px",marginBottom:"10px"},children:g}),t==="signup"&&e.jsxs("div",{className:"checkbox-field",style:{display:"flex",alignItems:"center",gap:"8px",marginBottom:"14px"},children:[e.jsx("input",{type:"checkbox",id:"terms",required:!0,style:{width:"auto"}}),e.jsx("label",{htmlFor:"terms",style:{margin:0,fontSize:"12px",color:"#888"},children:"I agree to the Terms & Conditions"})]}),e.jsx("div",{className:"actions",children:e.jsx("button",{type:"submit",children:t==="login"?"Login":"Register"})})]}),e.jsx("div",{className:"or-divider",style:{textAlign:"center",color:"#555",fontSize:"12px",margin:"14px 0"},children:"or"}),e.jsx("div",{className:"social-login",style:{display:"flex",gap:"8px"},children:e.jsx("button",{type:"button",className:"secondary",style:{flex:1,fontSize:"12px"},onClick:()=>{o&&o({email:"guest@example.com",name:"Guest User"})},children:"Continue as Guest"})}),e.jsx("div",{className:"switch-action",style:{textAlign:"center",marginTop:"14px",fontSize:"13px",color:"#888"},children:t==="login"?e.jsxs(e.Fragment,{children:["Are you a new User? ",e.jsx("a",{style:{color:"#00eaff",cursor:"pointer"},onClick:()=>n("signup"),children:"Register"})," yourself."]}):e.jsxs(e.Fragment,{children:["Already a user? ",e.jsx("a",{style:{color:"#00eaff",cursor:"pointer"},onClick:()=>n("login"),children:"Login here"})]})})]})})}const pe={};function ue(t,a){let n;try{n=t()}catch{return}return{getItem:r=>{var s;const i=d=>d===null?null:JSON.parse(d,void 0),c=(s=n.getItem(r))!=null?s:null;return c instanceof Promise?c.then(i):i(c)},setItem:(r,s)=>n.setItem(r,JSON.stringify(s,void 0)),removeItem:r=>n.removeItem(r)}}const P=t=>a=>{try{const n=t(a);return n instanceof Promise?n:{then(o){return P(o)(n)},catch(o){return this}}}catch(n){return{then(o){return this},catch(o){return P(o)(n)}}}},me=(t,a)=>(n,o,r)=>{let s={getStorage:()=>localStorage,serialize:JSON.stringify,deserialize:JSON.parse,partialize:p=>p,version:0,merge:(p,b)=>({...b,...p}),...a},i=!1;const c=new Set,d=new Set;let h;try{h=s.getStorage()}catch{}if(!h)return t((...p)=>{console.warn(`[zustand persist middleware] Unable to update item '${s.name}', the given storage is currently unavailable.`),n(...p)},o,r);const m=P(s.serialize),f=()=>{const p=s.partialize({...o()});let b;const u=m({state:p,version:s.version}).then(k=>h.setItem(s.name,k)).catch(k=>{b=k});if(b)throw b;return u},g=r.setState;r.setState=(p,b)=>{g(p,b),f()};const y=t((...p)=>{n(...p),f()},o,r);let v;const l=()=>{var p;if(!h)return;i=!1,c.forEach(u=>u(o()));const b=((p=s.onRehydrateStorage)==null?void 0:p.call(s,o()))||void 0;return P(h.getItem.bind(h))(s.name).then(u=>{if(u)return s.deserialize(u)}).then(u=>{if(u)if(typeof u.version=="number"&&u.version!==s.version){if(s.migrate)return s.migrate(u.state,u.version);console.error("State loaded from storage couldn't be migrated since no migrate function was provided")}else return u.state}).then(u=>{var k;return v=s.merge(u,(k=o())!=null?k:y),n(v,!0),f()}).then(()=>{b==null||b(v,void 0),i=!0,d.forEach(u=>u(v))}).catch(u=>{b==null||b(void 0,u)})};return r.persist={setOptions:p=>{s={...s,...p},p.getStorage&&(h=p.getStorage())},clearStorage:()=>{h==null||h.removeItem(s.name)},getOptions:()=>s,rehydrate:()=>l(),hasHydrated:()=>i,onHydrate:p=>(c.add(p),()=>{c.delete(p)}),onFinishHydration:p=>(d.add(p),()=>{d.delete(p)})},l(),v||y},he=(t,a)=>(n,o,r)=>{let s={storage:ue(()=>localStorage),partialize:l=>l,version:0,merge:(l,p)=>({...p,...l}),...a},i=!1;const c=new Set,d=new Set;let h=s.storage;if(!h)return t((...l)=>{console.warn(`[zustand persist middleware] Unable to update item '${s.name}', the given storage is currently unavailable.`),n(...l)},o,r);const m=()=>{const l=s.partialize({...o()});return h.setItem(s.name,{state:l,version:s.version})},f=r.setState;r.setState=(l,p)=>{f(l,p),m()};const g=t((...l)=>{n(...l),m()},o,r);r.getInitialState=()=>g;let y;const v=()=>{var l,p;if(!h)return;i=!1,c.forEach(u=>{var k;return u((k=o())!=null?k:g)});const b=((p=s.onRehydrateStorage)==null?void 0:p.call(s,(l=o())!=null?l:g))||void 0;return P(h.getItem.bind(h))(s.name).then(u=>{if(u)if(typeof u.version=="number"&&u.version!==s.version){if(s.migrate)return[!0,s.migrate(u.state,u.version)];console.error("State loaded from storage couldn't be migrated since no migrate function was provided")}else return[!1,u.state];return[!1,void 0]}).then(u=>{var k;const[j,N]=u;if(y=s.merge(N,(k=o())!=null?k:g),n(y,!0),j)return m()}).then(()=>{b==null||b(y,void 0),y=o(),i=!0,d.forEach(u=>u(y))}).catch(u=>{b==null||b(void 0,u)})};return r.persist={setOptions:l=>{s={...s,...l},l.storage&&(h=l.storage)},clearStorage:()=>{h==null||h.removeItem(s.name)},getOptions:()=>s,rehydrate:()=>v(),hasHydrated:()=>i,onHydrate:l=>(c.add(l),()=>{c.delete(l)}),onFinishHydration:l=>(d.add(l),()=>{d.delete(l)})},s.skipHydration||v(),y||g},xe=(t,a)=>"getStorage"in a||"serialize"in a||"deserialize"in a?((pe?"production":void 0)!=="production"&&console.warn("[DEPRECATED] `getStorage`, `serialize` and `deserialize` options are deprecated. Use `storage` option instead."),me(t,a)):he(t,a),ge=xe,U=["case","case-fan","cooler","cpu","motherboard","ram","ssd","psu","os"],M={name:"Microsoft Windows 11 Pro Retail - Download 64-bit",price:"189.99",mode:"64",max_memory:"2048"},fe=[{id:"build_service",name:"PCTG Build, Premium Cable Manage, Premium Test & Optimize",price:150},{id:"warranty",name:"2 Year Warranty & Free Tech Support / Remote Assistance",price:0},{id:"delivery",name:"Delivery By RM Special Delivery Insured",price:50},{id:"os",name:"Windows 11 Pro Retail",price:35}],G=fe.reduce((t,a)=>(t[a.id]={name:a.name,price:a.price},t),{}),ve={warranty:{name:"2 Year Warranty & Free Tech Support / Remote Assistance",price:0},os:{name:"Windows 11 Pro Retail",price:35},system_design:{name:"System Design Charge",price:35}};function T(t,a=1){return!t||typeof t!="object"?t:{...t,qty:t.qty||a}}function ye(t){return t?Array.isArray(t)?t.reduce((a,n)=>a+(parseFloat(n==null?void 0:n.price)||0)*(n.qty||1),0):(parseFloat(t==null?void 0:t.price)||0)*(t.qty||1):0}function be(t){const a=[];for(const[n,o]of Object.entries(t))Array.isArray(o)?o.forEach(r=>a.push({cat:n,item:r})):o&&a.push({cat:n,item:o});return a}const Y=te(ge((t,a)=>({selections:{os:T(M)},adminMode:!1,buildType:"full",onBuildComplete:null,enabledAddons:{},mandatory:G,setAdminMode:n=>t({adminMode:n}),setBuildType:n=>{t({buildType:n,mandatory:n==="parts"?ve:G})},toggleAddon:n=>{t(o=>{const r={...o.enabledAddons};return r[n]?delete r[n]:r[n]=!0,{enabledAddons:r}})},setComponent:(n,o)=>{t(r=>{const s=T(o),i={...r.selections,[n]:s};return((()=>{const h=i.cpu;return!(h&&h.graphics&&String(h.graphics).toLowerCase()!=="none")})()?[...U,"gpu"]:U).every(h=>i[h])&&r.onBuildComplete&&setTimeout(()=>r.onBuildComplete(),100),{selections:i}})},toggleMultiComponent:(n,o)=>{t(r=>{const s=r.selections[n],i=T(o);let c;if(Array.isArray(s)){const h=s.findIndex(m=>m.name===o.name);h>=0?(c=s.filter((m,f)=>f!==h),c.length===0&&(c=void 0)):c=[...s,i]}else(s==null?void 0:s.name)===o.name?c=void 0:c=[i];const d={...r.selections};return c===void 0?delete d[n]:d[n]=c,{selections:d}})},isMultiSelected:(n,o)=>{const r=a().selections[n];return Array.isArray(r)?r.some(s=>s.name===o):(r==null?void 0:r.name)===o},setItemQty:(n,o,r)=>{t(s=>{const i=s.selections[n],c={...s.selections};return Array.isArray(i)?c[n]=i.map(d=>d.name===o?{...d,qty:Math.max(1,r)}:d):(i==null?void 0:i.name)===o&&(c[n]={...i,qty:Math.max(1,r)}),{selections:c}})},clearComponent:n=>{t(o=>{const r={...o.selections};return delete r[n],{selections:r}})},removeMultiItem:(n,o)=>{t(r=>{const s=r.selections[n],i={...r.selections};if(Array.isArray(s)){const c=s.filter(d=>d.name!==o);c.length===0?delete i[n]:i[n]=c}else delete i[n];return{selections:i}})},resetBuild:()=>t({selections:{os:T(M)},enabledAddons:{}}),getComponentsTotal:()=>{const n=a().selections,o=a().buildType;return Object.entries(n).reduce((r,[s,i])=>s==="os"&&o==="full"?r:r+ye(i),0)},getMandatoryTotal:()=>{const n=a().mandatory;return Object.values(n).reduce((o,r)=>{const s=parseFloat(r==null?void 0:r.price);return o+(isNaN(s)?0:s)},0)},getBundledPrice:()=>{const n=a().getComponentsTotal(),o=a().getMandatoryTotal(),s=(n+o)*1.03;return Math.ceil(s)},getPriceBreakdown:()=>{const n=a().selections,o=a().mandatory,r=a().buildType,s=[];for(const{cat:f,item:g}of be(n)){if(f==="os"&&r==="full")continue;const y=g.qty||1,v=parseFloat(g==null?void 0:g.price)||0;s.push({label:f,name:(g==null?void 0:g.name)||"—",price:v,qty:y,lineTotal:v*y})}for(const f of Object.values(o))s.push({label:"service",name:f.name,price:parseFloat(f.price)||0,qty:1,lineTotal:parseFloat(f.price)||0});const i=a().getComponentsTotal(),c=a().getMandatoryTotal(),d=i+c,h=d*.03,m=Math.ceil(d*1.03);return{items:s,componentsTotal:i,mandatoryTotal:c,subtotal:d,surcharge:h,total:m}},setOnBuildComplete:n=>t({onBuildComplete:n})}),{name:"pc-builder-storage",partialize:t=>({selections:t.selections,buildType:t.buildType,adminMode:t.adminMode,enabledAddons:t.enabledAddons})})),je="./";function C(t){if(t.startsWith("http")||t.startsWith("data:"))return t;const a=t.startsWith("/")?t.slice(1):t;return`${je}${a}`}function we(t,a){x.useEffect(()=>{function n(o){t.current&&!t.current.contains(o.target)&&a()}return document.addEventListener("mousedown",n),()=>document.removeEventListener("mousedown",n)},[t,a])}function Se(){const t=V(),[a,n]=x.useState(!1),[o,r]=x.useState(!1),[s,i]=x.useState(!1),[c,d]=x.useState("login"),[h,m]=x.useState(null),[f,g]=x.useState(!1),[y,v]=x.useState(""),l=Y(w=>w.adminMode),p=Y(w=>w.setAdminMode),b=x.useRef(null);we(b,()=>r(!1)),x.useEffect(()=>{const w=sessionStorage.getItem("pctg_user");if(w)try{m(JSON.parse(w))}catch{}},[]);const u=w=>t.pathname===w?"active":"",k=()=>{sessionStorage.removeItem("pctg_user"),m(null)},j=w=>{sessionStorage.setItem("pctg_user",JSON.stringify(w)),m(w),i(!1)},N=(w,A)=>{w==="Pctg"&&A==="22.techguy.23"?(p(!0),g(!1),v("")):v("Invalid username or password")};return e.jsxs("nav",{className:"navbar",children:[e.jsx(S,{to:"/",className:"navbar-logo",children:e.jsx("img",{src:C("/pctg-logo.png"),alt:"PcTechGuyOnline",style:{height:"40px",width:"auto"}})}),e.jsxs("ul",{className:`navbar-links${a?" mobile-open":""}`,children:[e.jsx("li",{children:e.jsx(S,{to:"/",className:u("/"),children:"Home"})}),e.jsx("li",{children:e.jsx(S,{to:"/builder",className:u("/builder"),children:"Builder"})}),e.jsx("li",{children:e.jsxs("div",{className:"dropdown-wrapper",ref:b,children:[e.jsx("button",{className:"nav-dropdown-btn",onClick:()=>r(!o),children:"Tools ▾"}),o&&e.jsxs("div",{className:"dropdown-menu",onClick:()=>r(!1),children:[e.jsx(S,{to:"/ai-generator",children:"AI Build Generator"}),e.jsx(S,{to:"/game-system-generator",children:"Game System Generator"}),e.jsx(S,{to:"/fps-calculator",children:"FPS Calculator"}),e.jsx(S,{to:"/bottleneck-calculator",children:"Bottleneck Calculator"}),e.jsx(S,{to:"/compare",children:"Component Comparison"}),e.jsx(S,{to:"/builds",children:"Build History"})]})]})}),e.jsx("li",{children:e.jsx(S,{to:"/summary",className:u("/summary"),children:"Summary"})}),e.jsx("li",{children:e.jsx(S,{to:"/admin",className:`admin-link ${u("/admin")}`,children:"⚙ Admin"})})]}),e.jsxs("div",{className:"navbar-right",children:[h?e.jsxs("div",{className:"user-info",style:{display:"flex",alignItems:"center",gap:"10px"},children:[e.jsx("span",{style:{fontSize:"12px",color:"#00eaff"},children:h.email}),e.jsx("button",{className:"auth-btn login",onClick:k,style:{padding:"6px 12px",fontSize:"12px"},children:"Logout"})]}):e.jsxs(e.Fragment,{children:[e.jsx("button",{className:"auth-btn login",onClick:()=>{d("login"),i(!0)},children:"Login"}),e.jsx("button",{className:"auth-btn signup",onClick:()=>{d("signup"),i(!0)},children:"Signup"})]}),e.jsx("button",{onClick:()=>l?p(!1):g(!0),style:{background:"none",border:`1px solid ${l?"#00eaff":"rgba(255,255,255,0.15)"}`,color:l?"#00eaff":"#666",borderRadius:"6px",padding:"6px 10px",cursor:"pointer",fontFamily:"inherit",fontSize:"13px",boxShadow:l?"0 0 8px rgba(0,234,255,0.3)":"none"},title:l?"Lock admin mode":"Unlock admin mode",children:l?"🔓 Admin":"🔒"}),e.jsx("button",{className:"mobile-toggle",onClick:()=>n(!a),children:e.jsx("svg",{viewBox:"0 0 24 24",fill:"none",stroke:"#aaa",strokeWidth:"2",strokeLinecap:"round",children:e.jsx("path",{d:"M3 12h18M3 6h18M3 18h18"})})})]}),f&&e.jsx("div",{className:"modal-overlay",onClick:()=>{g(!1),v("")},children:e.jsxs("div",{className:"modal-box",style:{maxWidth:"360px"},onClick:w=>w.stopPropagation(),children:[e.jsx("h2",{style:{marginTop:0},children:"Admin Access"}),e.jsx("p",{style:{fontSize:"13px",color:"#aaa",marginBottom:"12px"},children:"Enter admin credentials to unlock prices and URLs."}),e.jsxs("form",{onSubmit:w=>{w.preventDefault();const A=new FormData(w.target);N(A.get("user"),A.get("pw"))},children:[e.jsx("input",{name:"user",type:"text",placeholder:"Username",style:{width:"100%",padding:"10px 12px",borderRadius:"6px",border:"1px solid rgba(255,255,255,0.15)",background:"#0d0d18",color:"#e6e6e6",fontFamily:"inherit",fontSize:"14px",boxSizing:"border-box",marginBottom:"8px"},autoFocus:!0}),e.jsx("input",{name:"pw",type:"password",placeholder:"Password",style:{width:"100%",padding:"10px 12px",borderRadius:"6px",border:"1px solid rgba(255,255,255,0.15)",background:"#0d0d18",color:"#e6e6e6",fontFamily:"inherit",fontSize:"14px",boxSizing:"border-box",marginBottom:"8px"},autoFocus:!0}),y&&e.jsx("div",{style:{color:"#ef4444",fontSize:"12px",marginBottom:"8px"},children:y}),e.jsxs("div",{className:"actions",children:[e.jsx("button",{type:"submit",className:"button",style:{padding:"8px 20px",fontSize:"13px"},children:"Unlock"}),e.jsx("button",{type:"button",className:"button secondary",onClick:()=>{g(!1),v("")},children:"Cancel"})]})]})]})}),s&&e.jsx(de,{mode:c,onClose:()=>i(!1),onSwitch:d,onSuccess:j})]})}const Ne="2.6.77",ke="2026-08-08T20:53:45.795Z",H={version:Ne,buildDate:ke};function Ce(){const a=new Date(H.buildDate).toLocaleDateString("en-GB",{day:"numeric",month:"short",year:"numeric"});return e.jsxs("footer",{className:"site-footer",children:[e.jsxs("div",{className:"footer-version",children:["v",H.version," • Updated ",a]}),e.jsxs("div",{className:"footer-inner",children:[e.jsxs("div",{className:"footer-brand",children:[e.jsx(S,{to:"/",className:"footer-logo",children:e.jsx("img",{src:C("/pctg-logo.png"),alt:"PcTechGuyOnline",style:{height:"32px",width:"auto",display:"block"}})}),e.jsx("p",{children:"Browse, Discover, Customize, Build Your PC — All in one place! PC Builder is your go-to platform for building your PC from scratch."}),e.jsxs("p",{children:["Reach us at: ",e.jsx("a",{href:"mailto:info@pctechguyonline.com",style:{color:"#00eaff"},children:"info@pctechguyonline.com"})]}),e.jsx("p",{className:"disclaimer",children:"Product price & specifications may change. Always verify on the retailer's website before purchasing. Compatibility checks are highly accurate but should be verified before ordering."})]}),e.jsxs("div",{className:"footer-col",children:[e.jsx("h4",{children:"Useful Links"}),e.jsx(S,{to:"/builder",children:"PC Builder"}),e.jsx(S,{to:"/admin",children:"Admin Panel"}),e.jsx("a",{href:"https://www.pctechguyonline.com",target:"_blank",rel:"noopener noreferrer",children:"PcTechGuyOnline.com"})]}),e.jsxs("div",{className:"footer-col",children:[e.jsx("h4",{children:"Support"}),e.jsx("a",{href:"mailto:info@pctechguyonline.com",children:"Email Us"}),e.jsx("a",{href:"https://wa.me/447933101083",target:"_blank",rel:"noopener noreferrer",children:"WhatsApp"}),e.jsx(S,{to:"/",children:"FAQ"})]}),e.jsxs("div",{className:"footer-col",children:[e.jsx("h4",{children:"Connect"}),e.jsx("a",{href:"https://www.facebook.com/pctechguyonline",target:"_blank",rel:"noopener noreferrer",children:"Facebook"}),e.jsx("a",{href:"https://www.instagram.com/pctechguyonline",target:"_blank",rel:"noopener noreferrer",children:"Instagram"}),e.jsx("a",{href:"https://x.com/pctechguyonline",target:"_blank",rel:"noopener noreferrer",children:"X"}),e.jsx("a",{href:"https://www.tiktok.com/@pctechguyonline",target:"_blank",rel:"noopener noreferrer",children:"TikTok"}),e.jsx("a",{href:"https://www.youtube.com/channel/UCKZVSHOWfJAJKdDr73pmNnA",target:"_blank",rel:"noopener noreferrer",children:"YouTube"})]})]}),e.jsxs("div",{className:"footer-bottom",children:[e.jsxs("span",{children:["Copyright © ",new Date().getFullYear()," PcTechGuyOnline | All Rights Reserved."]}),e.jsxs("div",{children:[e.jsx("a",{href:"https://www.pctechguyonline.com/privacy-policy",target:"_blank",rel:"noopener noreferrer",children:"Privacy Policy"}),e.jsx("span",{style:{margin:"0 8px",color:"#555"},children:"|"}),e.jsx("a",{href:"https://www.pctechguyonline.com/terms",target:"_blank",rel:"noopener noreferrer",children:"Terms of Service"})]})]})]})}const _e=[{name:"Facebook",url:"https://www.facebook.com/pctechguyonline",icon:"https://cdn.simpleicons.org/facebook/1877F2",color:"#1877F2"},{name:"Instagram",url:"https://www.instagram.com/pctechguyonline",icon:"https://cdn.simpleicons.org/instagram/E4405F",color:"#E4405F"},{name:"X",url:"https://x.com/pctechguyonline",icon:"https://cdn.simpleicons.org/x/000000",color:"#000000"},{name:"TikTok",url:"https://www.tiktok.com/@pctechguyonline",icon:"https://cdn.simpleicons.org/tiktok/000000",color:"#000000"},{name:"YouTube",url:"https://www.youtube.com/channel/UCKZVSHOWfJAJKdDr73pmNnA",icon:"https://cdn.simpleicons.org/youtube/FF0000",color:"#FF0000"},{name:"Discord",url:"https://discord.gg/w2Y7VBMAg",icon:"https://cdn.simpleicons.org/discord/5865F2",color:"#5865F2"},{name:"Xbox",url:"https://www.xbox.com/en-GB/play/user/DGINGERBREDMAN",icon:"https://cdn.simpleicons.org/xbox/107C10",color:"#107C10"},{name:"Steam",url:"https://steamcommunity.com/profiles/76561197979525265",icon:"https://cdn.simpleicons.org/steam/1B2838",color:"#1B2838"},{name:"Epic Games",url:"https://store.epicgames.com/u/594528bea06d4a60b077ebbb45fe2579",icon:"https://cdn.simpleicons.org/epicgames/2F2D2E",color:"#2F2D2E"}];function Ae(){return e.jsxs("div",{className:"home",children:[e.jsxs("div",{className:"home-hero",children:[e.jsx("img",{src:C("/pctg-main.png"),alt:"PcTechGuyOnline",className:"home-avatar",onError:t=>{t.target.style.display="none"}}),e.jsx("h1",{children:"PCTechGuyOnline.com"}),e.jsx("p",{className:"tagline",children:"Get Your Gamers Edge © with a custom PC built for you"}),e.jsx("img",{src:C("/pctg-banner.png"),alt:"Gaming PCs",className:"home-banner",onError:t=>{t.target.style.display="none"}}),e.jsxs("div",{style:{display:"flex",gap:"12px",justifyContent:"center",flexWrap:"wrap"},children:[e.jsx(S,{to:"/ai-generator",className:"cta-btn",style:{fontSize:"0.9rem",padding:"14px 28px"},children:"AI Build Generator"}),e.jsx(S,{to:"/builder",className:"cta-btn",style:{fontSize:"0.9rem",padding:"14px 28px",background:"linear-gradient(135deg, #ff005e, #00eaff)"},children:"Manual Builder"})]})]}),e.jsxs("div",{className:"home-stats",children:[e.jsxs("div",{className:"home-stat",children:[e.jsx("div",{className:"stat-number",children:"500+"}),e.jsx("div",{className:"stat-label",children:"PCs Built"})]}),e.jsxs("div",{className:"home-stat",children:[e.jsx("div",{className:"stat-number",children:"4.9★"}),e.jsx("div",{className:"stat-label",children:"Customer Rating"})]}),e.jsxs("div",{className:"home-stat",children:[e.jsx("div",{className:"stat-number",children:"UK"}),e.jsx("div",{className:"stat-label",children:"Based & Shipped"})]})]}),e.jsxs("div",{className:"home-guide",children:[e.jsx("h2",{children:"How to Build Your PC"}),e.jsxs("div",{className:"step",children:[e.jsx("div",{className:"step-num",children:"1"}),e.jsxs("div",{className:"step-content",children:[e.jsx("h3",{children:"Choose Your Components"}),e.jsx("p",{children:'Click on "+ Choose" next to each component to browse compatible parts. Our compatibility filter ensures everything works together.'})]})]}),e.jsxs("div",{className:"step",children:[e.jsx("div",{className:"step-num",children:"2"}),e.jsxs("div",{className:"step-content",children:[e.jsx("h3",{children:"Check Compatibility"}),e.jsx("p",{children:"The builder automatically checks that your selected CPU, motherboard, RAM, GPU, and other components are compatible."})]})]}),e.jsxs("div",{className:"step",children:[e.jsx("div",{className:"step-num",children:"3"}),e.jsxs("div",{className:"step-content",children:[e.jsx("h3",{children:"Review & Order"}),e.jsx("p",{children:"Review your complete build with a bundled price including professional build service, testing, and Royal Mail delivery."})]})]})]}),e.jsxs("div",{className:"home-features",children:[e.jsxs(S,{to:"/ai-generator",className:"home-feature",style:{textDecoration:"none",display:"block",padding:"20px",background:"rgba(255,255,255,0.02)",borderRadius:"10px",border:"1px solid rgba(0,234,255,0.08)",textAlign:"center",transition:"all 0.3s"},children:[e.jsx("div",{style:{fontSize:"32px",marginBottom:"8px"},children:"🤖"}),e.jsx("h3",{style:{color:"#00eaff",fontSize:"14px",margin:"0 0 6px",textTransform:"uppercase"},children:"AI Build Generator"}),e.jsx("p",{style:{color:"#888",fontSize:"12px",margin:0,lineHeight:"1.5"},children:"Enter your budget and use case — AI picks the perfect parts"})]}),e.jsxs(S,{to:"/fps-calculator",className:"home-feature",style:{textDecoration:"none",display:"block",padding:"20px",background:"rgba(255,255,255,0.02)",borderRadius:"10px",border:"1px solid rgba(0,234,255,0.08)",textAlign:"center",transition:"all 0.3s"},children:[e.jsx("div",{style:{fontSize:"32px",marginBottom:"8px"},children:"🎮"}),e.jsx("h3",{style:{color:"#00eaff",fontSize:"14px",margin:"0 0 6px",textTransform:"uppercase"},children:"FPS Calculator"}),e.jsx("p",{style:{color:"#888",fontSize:"12px",margin:0,lineHeight:"1.5"},children:"Estimate FPS for 20+ games at any resolution"})]}),e.jsxs(S,{to:"/bottleneck-calculator",className:"home-feature",style:{textDecoration:"none",display:"block",padding:"20px",background:"rgba(255,255,255,0.02)",borderRadius:"10px",border:"1px solid rgba(0,234,255,0.08)",textAlign:"center",transition:"all 0.3s"},children:[e.jsx("div",{style:{fontSize:"32px",marginBottom:"8px"},children:"🔍"}),e.jsx("h3",{style:{color:"#00eaff",fontSize:"14px",margin:"0 0 6px",textTransform:"uppercase"},children:"Bottleneck Calculator"}),e.jsx("p",{style:{color:"#888",fontSize:"12px",margin:0,lineHeight:"1.5"},children:"Check CPU/GPU bottleneck percentages"})]}),e.jsxs(S,{to:"/compare",className:"home-feature",style:{textDecoration:"none",display:"block",padding:"20px",background:"rgba(255,255,255,0.02)",borderRadius:"10px",border:"1px solid rgba(0,234,255,0.08)",textAlign:"center",transition:"all 0.3s"},children:[e.jsx("div",{style:{fontSize:"32px",marginBottom:"8px"},children:"⚖️"}),e.jsx("h3",{style:{color:"#00eaff",fontSize:"14px",margin:"0 0 6px",textTransform:"uppercase"},children:"Component Comparison"}),e.jsx("p",{style:{color:"#888",fontSize:"12px",margin:0,lineHeight:"1.5"},children:"Compare parts side by side to find the best"})]}),e.jsxs(S,{to:"/builds",className:"home-feature",style:{textDecoration:"none",display:"block",padding:"20px",background:"rgba(255,255,255,0.02)",borderRadius:"10px",border:"1px solid rgba(0,234,255,0.08)",textAlign:"center",transition:"all 0.3s"},children:[e.jsx("div",{style:{fontSize:"32px",marginBottom:"8px"},children:"📦"}),e.jsx("h3",{style:{color:"#00eaff",fontSize:"14px",margin:"0 0 6px",textTransform:"uppercase"},children:"Build History"}),e.jsx("p",{style:{color:"#888",fontSize:"12px",margin:0,lineHeight:"1.5"},children:"Save, load, and export your builds"})]}),e.jsxs("div",{className:"home-feature",children:[e.jsx("div",{className:"feature-icon",children:"🔗"}),e.jsx("h3",{children:"Shareable Link"}),e.jsx("p",{children:"Save your build and share it with friends using a unique link."})]}),e.jsxs("div",{className:"home-feature",children:[e.jsx("div",{className:"feature-icon",children:"✅"}),e.jsx("h3",{children:"Compatibility Filter"}),e.jsx("p",{children:"Only compatible parts are shown, ensuring your build works perfectly."})]}),e.jsxs("div",{className:"home-feature",children:[e.jsx("div",{className:"feature-icon",children:"🔌"}),e.jsx("h3",{children:"Bundled Pricing"}),e.jsx("p",{children:"See the complete price including build, testing, and delivery — no hidden fees."})]}),e.jsxs("div",{className:"home-feature",children:[e.jsx("div",{className:"feature-icon",children:"🤖"}),e.jsx("h3",{children:"AI Assistant"}),e.jsx("p",{children:"Get real-time advice from PCTG, your AI product advisor."})]})]}),e.jsxs("div",{className:"home-social",children:[e.jsx("h3",{children:"Connect With Us"}),e.jsx("div",{className:"social-links",children:_e.map(t=>e.jsx("a",{href:t.url,target:"_blank",rel:"noopener noreferrer",className:"social-link",title:t.name,children:e.jsx("img",{src:t.icon,alt:t.name})},t.name))})]})]})}const Re=x.lazy(()=>_(()=>import("./Builder-gW1CNVfZ.js"),__vite__mapDeps([0,1,2,3,4,5,6,7,8,9]),import.meta.url)),Pe=x.lazy(()=>_(()=>import("./AdminBuilder-CjzAckTd.js"),__vite__mapDeps([10,1,2,3,4,5,6,9]),import.meta.url)),Ee=x.lazy(()=>_(()=>import("./Summary-DTVbwopn.js").then(t=>t.S),__vite__mapDeps([11,2,3,12,1,4,7]),import.meta.url)),Te=x.lazy(()=>_(()=>import("./BottleneckCalculator-BTkAced4.js"),__vite__mapDeps([13,1,6,3,9]),import.meta.url)),Ie=x.lazy(()=>_(()=>import("./ComponentComparison-CsJlMCkY.js"),__vite__mapDeps([14,1,6,3,9]),import.meta.url)),Oe=x.lazy(()=>_(()=>import("./FpsCalculator-XyD1-RdU.js"),__vite__mapDeps([15,1,6,12,3,2,9]),import.meta.url)),Be=x.lazy(()=>_(()=>import("./BuildHistory-CNxysQ9E.js"),__vite__mapDeps([16,1,8,4,9]),import.meta.url)),ze=x.lazy(()=>_(()=>import("./AIGenerator-DSb6x6eW.js"),__vite__mapDeps([17,1,18,6,3,2,4,5,9]),import.meta.url)),Fe=x.lazy(()=>_(()=>import("./GameSystemGenerator-CcePhFTC.js"),__vite__mapDeps([19,1,18,6,3,2,4,9]),import.meta.url));function De(){return e.jsx("div",{style:{textAlign:"center",padding:"40px",color:"#666"},children:"Loading..."})}function Le(){return e.jsxs(e.Fragment,{children:[e.jsx(Se,{}),e.jsx("main",{className:"container",children:e.jsx(x.Suspense,{fallback:e.jsx(De,{}),children:e.jsx(Z,{})})}),e.jsx(Ce,{})]})}const Ue=X([{path:"/",element:e.jsx(Le,{}),children:[{index:!0,element:e.jsx(Ae,{})},{path:"builder",element:e.jsx(Re,{})},{path:"summary",element:e.jsx(Ee,{})},{path:"admin",element:e.jsx(Pe,{})},{path:"bottleneck-calculator",element:e.jsx(Te,{})},{path:"compare",element:e.jsx(Ie,{})},{path:"fps-calculator",element:e.jsx(Oe,{})},{path:"builds",element:e.jsx(Be,{})},{path:"ai-generator",element:e.jsx(ze,{})},{path:"game-system-generator",element:e.jsx(Fe,{})}]}]);class Me extends q.Component{constructor(a){super(a),this.state={hasError:!1,error:null}}static getDerivedStateFromError(a){return{hasError:!0,error:a}}componentDidCatch(a,n){console.error("ErrorBoundary caught:",a,n)}render(){var a;return this.state.hasError?e.jsxs("div",{style:{padding:"20px",textAlign:"center",color:"#ff6b6b",background:"#1a1a2e",borderRadius:"8px",margin:"20px"},children:[e.jsx("h2",{children:"Something went wrong"}),e.jsx("p",{children:((a=this.state.error)==null?void 0:a.message)||"Unknown error"}),e.jsx("button",{onClick:()=>window.location.reload(),style:{padding:"10px 20px",background:"#00B67A",border:"none",borderRadius:"4px",color:"white",cursor:"pointer"},children:"Reload Page"})]}):this.props.children}}const Ge=[{id:1,title:"Get YOUR Gamers Edge ©",subtitle:"In 3 Steps!!!"},{id:2,title:"1. Select Parts & Budget",desc:"Choose your components or let the builder auto-optimize."},{id:3,title:"2. Get Price & Performance Summary",desc:"Instant FPS estimates + your visual PC build preview."},{id:4,title:"3. Select Purchase & Wait for Delivery",desc:"Your custom gaming rig delivered to your door."}];function Ye({price:t="£1,249",performance:a="1440p Ultra • 165 FPS",onFinish:n}){const[o,r]=x.useState("logo"),[s,i]=x.useState(!1),[c,d]=x.useState(!1),[h,m]=x.useState(Array(6).fill(!1)),[f,g]=x.useState(!1),[y,v]=x.useState(!1),[l,p]=x.useState(!1),[b,u]=x.useState(0),k=x.useRef([]);return x.useEffect(()=>{const j=(w,A)=>setTimeout(A,w),N=[];return N.push(j(500,()=>u(1))),N.push(j(5500,()=>{u(2),r("parts"),N.push(j(1e3,()=>{m([!0,!0,!0,!0,!0,!0])}))})),N.push(j(10500,()=>{u(3),r("caseEmpty"),g(!0)})),N.push(j(15500,()=>{u(4),r("caseFinal"),v(!0)})),N.push(j(20500,()=>{u(0),r("metrics"),p(!0)})),N.push(j(25500,()=>{i(!0),N.push(j(1e3,()=>{d(!0),n==null||n()}))})),()=>N.forEach(clearTimeout)},[n]),e.jsxs("div",{id:"pcv-intro",className:s?"pcv-finished":"",style:{display:c?"none":void 0},children:[e.jsx("div",{className:"pcv-overlay"}),e.jsxs("div",{className:"pcv-inner",children:[e.jsx("div",{id:"pcv-popups",children:Ge.map(j=>e.jsxs("div",{className:`pcv-popup ${b===j.id?"active":""}`,children:[e.jsx("h1",{children:j.title}),j.subtitle&&e.jsx("h2",{children:j.subtitle}),j.desc&&e.jsx("p",{children:j.desc})]},j.id))}),e.jsx("div",{className:`pcv-stage pcv-stage-logo ${o==="logo"?"active":""}`,children:e.jsx("img",{src:C("/intro/pctg.png"),alt:"PCTG Logo",className:"pcv-logo"})}),e.jsxs("div",{className:`pcv-stage pcv-stage-parts ${o==="parts"?"active":""}`,children:[[{img:"cpu.png",label:"CPU"},{img:"gpu.png",label:"GPU"},{img:"ram.png",label:"RAM"},{img:"ssd.png",label:"SSD"},{img:"cooler.png",label:"Cooler"},{img:"psu.png",label:"PSU"}].map((j,N)=>e.jsxs("div",{ref:w=>k.current[N]=w,className:`pcv-part ${h[N]?"selected":""}`,style:{transitionDelay:`${150*N}ms`,opacity:1,transform:"translateY(0)"},children:[e.jsx("img",{src:C(`/intro/${j.img}`),alt:j.label}),e.jsx("span",{children:j.label})]},j.label)),e.jsx("img",{src:C("/intro/preview.png"),alt:"Build Preview",style:{width:"100%",maxWidth:"500px",borderRadius:"12px",marginTop:"20px"}})]}),e.jsx("div",{className:`pcv-stage pcv-stage-case-empty ${o==="caseEmpty"?"active":""}`,children:e.jsxs("div",{className:`pcv-case ${f?"assembled":""}`,children:[e.jsx("img",{src:C("/intro/empty.png"),alt:"Empty Case"}),e.jsx("div",{className:"pcv-case-glow"})]})}),e.jsx("div",{className:`pcv-stage pcv-stage-case-final ${o==="caseFinal"?"active":""}`,children:e.jsxs("div",{className:`pcv-case pcv-case-final ${y?"reveal":""}`,children:[e.jsx("img",{src:C("/intro/final.png"),alt:"Completed Build"}),e.jsx("div",{className:"pcv-case-glow"})]})}),e.jsx("div",{className:`pcv-stage pcv-stage-final-images ${o==="metrics"?"active":""}`,children:e.jsxs("div",{className:"pcv-final-images-container",children:[e.jsx("div",{className:`pcv-final-title ${l?"show":""}`,children:e.jsx("img",{src:C("/intro/title.png"),alt:"Title"})}),e.jsx("div",{className:`pcv-final-gaming-wrap ${l?"show":""}`,children:e.jsx("img",{src:C("/intro/gaming-setup.jpg"),alt:"Gaming Setup"})})]})})]}),e.jsx("style",{children:`
        #pcv-intro {
          position: fixed;
          inset: 0;
          width: 100%;
          height: 100vh;
          background: #05060a;
          color: #fff;
          overflow: hidden;
          font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
          z-index: 9999;
        }

        .pcv-inner {
          position: relative;
          width: 100%;
          max-width: 1400px;
          height: 100vh;
          margin: 0 auto;
          padding: 24px 20px;
          background: radial-gradient(circle at top, #141b2b 0%, #05060a 60%);
          background-image: url(${C("/intro/background.jpg")});
          background-size: cover;
          background-position: center;
          background-blend-mode: overlay;
        }

        .pcv-overlay {
          position: absolute;
          inset: 0;
          background: #05060a;
          opacity: 0;
          pointer-events: none;
          transition: opacity 0.8s ease-in-out;
        }

        #pcv-intro.pcv-finished .pcv-overlay {
          opacity: 1;
        }

        .pcv-stage {
          position: absolute;
          inset: 0;
          display: flex;
          align-items: center;
          justify-content: center;
          opacity: 0;
          transform: scale(0.95);
          transition: opacity 0.6s ease, transform 0.6s ease;
          pointer-events: none;
        }

        .pcv-stage.active {
          opacity: 1;
          transform: scale(1);
        }

        .pcv-stage-logo .pcv-logo {
          width: 260px;
          filter: drop-shadow(0 0 25px rgba(255, 0, 80, 0.8));
          transform: scale(0.7);
          animation: pcv-logo-pop 1.4s ease-out forwards;
        }

        @keyframes pcv-logo-pop {
          0% { opacity: 0; transform: scale(0.4); }
          40% { opacity: 1; transform: scale(1.1); }
          100% { opacity: 1; transform: scale(1); }
        }

        .pcv-stage-parts {
          flex-direction: row;
          flex-wrap: wrap;
          gap: 24px;
          padding: 0 40px;
        }

        .pcv-part {
          width: 150px;
          height: 180px;
          background: rgba(10, 14, 24, 0.9);
          border-radius: 16px;
          padding: 10px;
          border: 1px solid rgba(0, 255, 170, 0.15);
          box-shadow: 0 0 18px rgba(0, 0, 0, 0.6);
          display: flex;
          flex-direction: column;
          align-items: center;
          transform: translateY(40px);
          opacity: 0;
          transition: opacity 0.5s ease, transform 0.5s ease, border-color 0.4s ease, box-shadow 0.4s ease;
        }

        .pcv-part img {
          width: 100%;
          height: 120px;
          border-radius: 12px;
          object-fit: contain;
          background: rgba(0, 0, 0, 0.3);
        }

        .pcv-part span {
          margin-top: 8px;
          font-size: 0.8rem;
          letter-spacing: 0.06em;
          text-transform: uppercase;
          color: #a9b4d9;
        }

        .pcv-part.selected {
          border-color: rgba(0, 255, 170, 0.9);
          box-shadow: 0 0 25px rgba(0, 255, 170, 0.4);
          transform: translateY(0) scale(1.05);
        }

        .pcv-stage-case-empty .pcv-case,
        .pcv-stage-case-final .pcv-case {
          position: relative;
          width: min(1080px, 85vw);
          border-radius: 24px;
          overflow: hidden;
          box-shadow: 0 0 40px rgba(0, 0, 0, 0.8);
          transform: translateY(40px);
          opacity: 0;
        }

        .pcv-case img {
          width: 100%;
          display: block;
        }

        .pcv-case-glow {
          position: absolute;
          inset: 0;
          background: radial-gradient(circle at center, rgba(0, 255, 170, 0.35), transparent 60%);
          mix-blend-mode: screen;
          opacity: 0;
        }

        .pcv-case.assembled {
          animation: pcv-case-rise 0.9s ease-out forwards;
        }

        .pcv-case.assembled .pcv-case-glow {
          animation: pcv-case-glow 1.4s ease-out forwards;
        }

        .pcv-case-final.reveal {
          animation: pcv-case-final-rise 0.9s ease-out forwards;
        }

        .pcv-case-final.reveal .pcv-case-glow {
          animation: pcv-case-glow 1.4s ease-out forwards;
        }

        @keyframes pcv-case-rise {
          0% { opacity: 0; transform: translateY(60px) scale(0.9); }
          100% { opacity: 1; transform: translateY(0) scale(1); }
        }

        @keyframes pcv-case-final-rise {
          0% { opacity: 0; transform: translateY(40px) scale(0.95); }
          100% { opacity: 1; transform: translateY(0) scale(1); }
        }

        @keyframes pcv-case-glow {
          0% { opacity: 0; }
          40% { opacity: 1; }
          100% { opacity: 0.6; }
        }

        .pcv-stage-final-images {
          flex-direction: column;
        }

        .pcv-final-images-container {
          width: min(1080px, 85vw);
          max-height: 78vh;
          display: flex;
          flex-direction: column;
          align-items: center;
          gap: 12px;
          opacity: 0;
          transform: translateY(60px);
          transition: opacity 0.7s ease, transform 0.7s ease;
        }

        .pcv-stage-final-images.active .pcv-final-images-container {
          opacity: 1;
          transform: translateY(0);
        }

        .pcv-final-title {
          flex-shrink: 0;
          max-width: 100%;
          opacity: 0;
          transform: translateY(-20px);
          transition: opacity 0.6s ease 0.2s, transform 0.6s ease 0.2s;
        }

        .pcv-final-title.show {
          opacity: 1;
          transform: translateY(0);
        }

        .pcv-final-title img {
          max-width: 100%;
          max-height: 20vh;
          display: block;
          object-fit: contain;
          border-radius: 12px;
          box-shadow: 0 0 30px rgba(0, 234, 255, 0.25);
        }

        .pcv-final-gaming-wrap {
          flex: 1;
          min-height: 0;
          max-width: 100%;
          opacity: 0;
          transform: translateY(30px);
          transition: opacity 0.7s ease 0.4s, transform 0.7s ease 0.4s;
        }

        .pcv-final-gaming-wrap.show {
          opacity: 1;
          transform: translateY(0);
        }

        .pcv-final-gaming-wrap img {
          width: 100%;
          max-height: 55vh;
          display: block;
          object-fit: contain;
          border-radius: 20px;
          box-shadow: 0 0 50px rgba(0, 234, 255, 0.3);
        }

        #pcv-popups {
          position: absolute;
          top: 0;
          left: 0;
          right: 0;
          z-index: 10;
          display: flex;
          justify-content: center;
          pointer-events: none;
        }

        .pcv-popup {
          position: absolute;
          top: 40px;
          background: linear-gradient(135deg, rgba(0, 234, 255, 0.12), rgba(255, 0, 94, 0.08));
          backdrop-filter: blur(14px);
          border: 1px solid rgba(0, 234, 255, 0.2);
          border-radius: 16px;
          padding: 18px 32px;
          max-width: 520px;
          width: 90%;
          text-align: center;
          opacity: 0;
          transform: translateY(-30px) scale(0.92);
          transition: opacity 0.5s ease, transform 0.5s ease;
          pointer-events: none;
        }

        .pcv-popup.active {
          opacity: 1;
          transform: translateY(0) scale(1);
        }

        .pcv-popup h1 {
          font-size: 1.1rem;
          font-weight: 700;
          color: #fff;
          margin: 0 0 2px;
          letter-spacing: 0.3px;
        }

        .pcv-popup h2 {
          font-size: 0.9rem;
          font-weight: 600;
          color: #00eaff;
          margin: 0;
          letter-spacing: 0.5px;
        }

        .pcv-popup p {
          font-size: 0.8rem;
          color: #a9b4d9;
          margin: 4px 0 0;
        }

        @media (max-width: 600px) {
          .pcv-part {
            width: 100px;
            height: 140px;
            padding: 6px;
          }
          .pcv-part img {
            height: 90px;
          }
          .pcv-stage-parts {
            gap: 12px;
            padding: 0 16px;
          }
          .pcv-stage-parts {
            padding: 0 16px;
          }
          .pcv-stage-case-empty .pcv-case,
          .pcv-stage-case-final .pcv-case {
            width: min(780px, 92vw);
          }
        }
      `})]})}function He(){const[t,a]=x.useState(!0);return e.jsxs(e.Fragment,{children:[t&&e.jsx(Ye,{onFinish:()=>a(!1)}),e.jsx(Me,{children:e.jsx(ee,{router:Ue})})]})}const qe="V_dUNK84z3XIlPFvm";if(typeof window<"u"){const t=document.createElement("script");t.src="https://cdn.jsdelivr.net/npm/@emailjs/browser@4/dist/email.min.js",t.onload=()=>{var a;(a=window.emailjs)==null||a.init(qe)},document.head.appendChild(t)}ae.createRoot(document.getElementById("root")).render(e.jsx(q.StrictMode,{children:e.jsx(He,{})}));export{_,C as a,e as j,Y as u};
