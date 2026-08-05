/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: public/assets/report-print.js
 * Propósito: Controla la impresión de informes y el retorno seguro a la pantalla anterior.
 * Los bloques críticos se comentan para facilitar soporte y mantenimiento.
 */
"use strict";
document.addEventListener("DOMContentLoaded",function(){
  var printButton=document.querySelector("[data-report-print]");
  var closeButton=document.querySelector("[data-report-close]");
  if(printButton){printButton.addEventListener("click",function(){window.print();});}
  if(closeButton){
    closeButton.addEventListener("click",function(event){
      var fallback=closeButton.getAttribute("data-close-url")||closeButton.getAttribute("href")||"index.php?page=informes";
      if(window.opener&&!window.opener.closed){
        event.preventDefault();
        try{window.opener.focus();window.close();}catch(error){}
        window.setTimeout(function(){if(!window.closed){window.location.assign(fallback);}},180);
        return;
      }
      // Cuando el navegador impide window.close(), el enlace funciona como retorno seguro.
      closeButton.setAttribute("href",fallback);
    });
  }
});
