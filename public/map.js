// Store locator: OpenStreetMap + Leaflet. Branches come from Admin > Settings. No API key needed.
(function(){
 const esc=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const km=(a,b,c,d)=>{const r=Math.PI/180,x=Math.sin((c-a)*r/2)**2+Math.cos(a*r)*Math.cos(c*r)*Math.sin((d-b)*r/2)**2;return 12742*Math.asin(Math.sqrt(x))};
 const fmt=k=>k<1?Math.round(k*1000)+' m':k.toFixed(1)+' km';
 const dirUrl=b=>'https://www.google.com/maps/dir/?api=1&destination='+encodeURIComponent(b.name.replace(/\s*\(.*\)/,'')+', '+b.address);
 window.initMap=function(branches){
  const box=document.getElementById('mapw'),el=document.getElementById('map');
  if(!box||!el)return;
  if(!branches||!branches.length||!window.L){box.hidden=true;return}
  box.hidden=false;
  let map,markers=[],me=null,ring=null,watch=null,mine=null;
  const list=document.getElementById('brl'),msg=document.getElementById('locmsg'),btn=document.getElementById('loc');
  function draw(){
   list.innerHTML=branches.map((b,i)=>{
    const d=mine?` <small class="dist">${fmt(km(mine[0],mine[1],b.lat,b.lng))} away</small>`:'';
    return `<div class="br" data-i="${i}"><div><b>${esc(b.name)}</b>${d}<br><span>${esc(b.address)}</span></div><div class="ba"><button type="button" class="lk" data-show="${i}">Show on map</button><a href="${dirUrl(b)}" target="_blank" rel="noopener">Directions</a></div></div>`}).join('');
  }
  function build(){
   if(map)return;
   map=L.map(el,{scrollWheelZoom:false}).setView([branches[0].lat,branches[0].lng],14);
   L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'}).addTo(map);
   markers=branches.map(b=>L.marker([b.lat,b.lng]).addTo(map).bindPopup(`<b>${esc(b.name)}</b><br>${esc(b.address)}<br><a href="${dirUrl(b)}" target="_blank" rel="noopener">Get directions</a>`));
   if(branches.length>1)map.fitBounds(L.latLngBounds(branches.map(b=>[b.lat,b.lng])),{padding:[40,40],maxZoom:15});
   setTimeout(()=>map.invalidateSize(),200);
  }
  draw();
  if('IntersectionObserver'in window){new IntersectionObserver((en,ob)=>{if(en[0].isIntersecting){build();ob.disconnect()}},{rootMargin:'300px'}).observe(el)}else build();
  list.addEventListener('click',ev=>{const t=ev.target.closest('[data-show]');if(!t)return;build();const i=+t.dataset.show;map.setView([branches[i].lat,branches[i].lng],16);markers[i].openPopup();el.scrollIntoView({behavior:'smooth',block:'center'})});
  function onPos(p){
   const la=p.coords.latitude,ln=p.coords.longitude;mine=[la,ln];build();
   if(!me){me=L.circleMarker([la,ln],{radius:8,color:'#fff',weight:3,fillColor:'#1a73e8',fillOpacity:1}).addTo(map).bindPopup('You are here');ring=L.circle([la,ln],{radius:p.coords.accuracy,color:'#1a73e8',weight:1,fillOpacity:.1}).addTo(map);
    map.fitBounds(L.latLngBounds([[la,ln],...branches.map(b=>[b.lat,b.lng])]),{padding:[40,40],maxZoom:15})}
   else{me.setLatLng([la,ln]);ring.setLatLng([la,ln]).setRadius(p.coords.accuracy)}
   let n=0,nd=1e9;branches.forEach((b,i)=>{const d=km(la,ln,b.lat,b.lng);if(d<nd){nd=d;n=i}});
   msg.textContent=`Nearest: ${branches[n].name}, ${fmt(nd)} away.`;draw();
  }
  btn.addEventListener('click',()=>{
   if(watch!==null){navigator.geolocation.clearWatch(watch);watch=null;if(me){me.remove();ring.remove();me=ring=null}mine=null;msg.textContent='';btn.textContent='Show my location';draw();return}
   if(!navigator.geolocation){msg.textContent='Your browser cannot share its location. Use the Directions links instead.';return}
   msg.textContent='Finding you… allow location access when your browser asks.';
   watch=navigator.geolocation.watchPosition(onPos,er=>{navigator.geolocation.clearWatch(watch);watch=null;msg.textContent=er.code===1?'Location is blocked. Allow it in your browser’s site settings, or use Directions.':'We could not find your location. Use Directions instead.'},{enableHighAccuracy:true,maximumAge:5000,timeout:20000});
   btn.textContent='Stop sharing location';
  });
 };
})();
