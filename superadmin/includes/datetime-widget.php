<!-- DateTime Widget for Navbar -->
<div class="navbar-datetime" id="navDateTimeWidget">
    <span class="datetime-date" id="navWidgetDate"></span>
    <span class="datetime-time" id="navWidgetTime"></span>
</div>
<script>
(function(){
    function _updateNavDT(){
        var d=document.getElementById('navWidgetDate'),t=document.getElementById('navWidgetTime');
        if(!d||!t)return;
        var n=new Date(),days=['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        d.textContent=days[n.getDay()]+', '+n.toLocaleDateString(undefined,{year:'numeric',month:'short',day:'2-digit'});
        t.textContent=n.toLocaleTimeString(undefined,{hour:'2-digit',minute:'2-digit',second:'2-digit'});
    }
    _updateNavDT();setInterval(_updateNavDT,1000);
})();
</script>
