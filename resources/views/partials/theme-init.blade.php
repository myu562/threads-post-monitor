{{-- Runs before the stylesheet so the first paint already uses the right theme (no flash).
     The rest of the toggle logic lives in public/js/theme.js. --}}
<script>(function(){var t=null;try{t=localStorage.getItem('theme')}catch(e){}if(t!=='light'&&t!=='dark'){t=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'}var d=document.documentElement;d.setAttribute('data-theme',t);d.style.colorScheme=t})();</script>
