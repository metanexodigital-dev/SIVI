/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: public/assets/mobile-scanner.js
 * Propósito: Gestiona la captura móvil de códigos, fotografías, lectura asistida y envío de resultados al computador.
 * Los bloques críticos se comentan para facilitar soporte y mantenimiento.
 */
(function(){
    'use strict';
    var root=document.querySelector('[data-mobile-scanner]');
    if(!root||root.dataset.available!=='1')return;

    var liveEnabled=root.dataset.liveEnabled==='1';
    var imageEnabled=root.dataset.imageEnabled==='1';
    var manualEnabled=root.dataset.manualEnabled==='1';
    var video=root.querySelector('[data-scanner-video]');
    var placeholder=root.querySelector('[data-scanner-placeholder]');
    var startBtn=root.querySelector('[data-scanner-start]');
    var photoBtn=root.querySelector('[data-scanner-photo]');
    var galleryBtn=root.querySelector('[data-scanner-gallery]');
    var photoInput=root.querySelector('[data-scanner-photo-input]');
    var galleryInput=root.querySelector('[data-scanner-gallery-input]');
    var torchBtn=root.querySelector('[data-scanner-torch]');
    var stopBtn=root.querySelector('[data-scanner-stop]');
    var status=root.querySelector('[data-scanner-status]');
    var valueInput=root.querySelector('[data-scanner-value]');
    var formatLabel=root.querySelector('[data-scanner-format]');
    var clearBtn=root.querySelector('[data-scanner-clear]');
    var sendBtn=root.querySelector('[data-scanner-send]');
    var retryBtn=root.querySelector('[data-scanner-retry]');
    var sendStatus=root.querySelector('[data-scanner-send-status]');
    var countdown=root.querySelector('[data-scanner-countdown]');
    var connectionDot=root.querySelector('[data-scanner-connection-dot]');
    var connectionLabel=root.querySelector('[data-scanner-connection-label]');
    var guidedToggle=root.querySelector('[data-scanner-guided]');
    var guidedProgress=root.querySelector('[data-scanner-guided-progress]');
    var guidedStep=root.querySelector('[data-scanner-guided-step]');
    var guidedLabel=root.querySelector('[data-scanner-guided-label]');
    var candidatesCard=root.querySelector('[data-scanner-candidates-card]');
    var candidatesBox=root.querySelector('[data-scanner-candidates]');
    var browserHelp=root.querySelector('[data-scanner-browser-help]');
    var browserTitle=root.querySelector('[data-scanner-browser-title]');
    var browserMessage=root.querySelector('[data-scanner-browser-message]');
    var browserInstructions=root.querySelector('[data-scanner-browser-instructions]');
    var copyLinkBtn=root.querySelector('[data-scanner-copy-link]');
    var shareLinkBtn=root.querySelector('[data-scanner-share-link]');

    var token=root.dataset.token||'';
    var submitUrl=root.dataset.submitUrl||'';
    var statusUrl=root.dataset.statusUrl||'';
    var imageUrl=root.dataset.imageUrl||'';
    var stream=null,detector=null,detecting=false,lastDetected='',lastDetectedAt=0,raf=0;
    var torchOn=false,selectedFormat='Lectura manual',localExpiresAt=0,statusTimer=0;
    var pendingPayload=null,pendingSequence=0,pendingRequestId='',lastAckSequence=0;
    var guidedMode=false,guidedStage=1;
    var ua=navigator.userAgent||'';
    var isIOS=/iPad|iPhone|iPod/.test(ua)||(navigator.platform==='MacIntel'&&navigator.maxTouchPoints>1);
    var isAndroid=/Android/i.test(ua);
    var isWhatsApp=/WhatsApp/i.test(ua);
    var isInstagram=/Instagram/i.test(ua);
    var isFacebook=/FBAN|FBAV/i.test(ua);
    var isInApp=isWhatsApp||isInstagram||isFacebook||/Line\//i.test(ua);

    function setStatus(message,tone){if(status){status.textContent=message;status.dataset.tone=tone||'';}}
    function setSendStatus(message,tone){if(sendStatus){sendStatus.textContent=message;sendStatus.dataset.tone=tone||'';}}
    function setConnection(message,tone){
        if(connectionLabel)connectionLabel.textContent=message;
        if(connectionDot)connectionDot.dataset.tone=tone||'';
    }
    function selectedTarget(){var checked=root.querySelector('input[name="scan_target"]:checked');return checked?checked.value:'serial_number';}
    function setTarget(target){
        var input=root.querySelector('input[name="scan_target"][value="'+target+'"]');
        if(input){input.checked=true;input.dispatchEvent(new Event('change',{bubbles:true}));}
    }
    function normalizePlate(value){var digits=String(value||'').replace(/\D/g,'');return /^000\d{5}$/.test(digits)?digits.slice(0,3)+'-'+digits.slice(3):String(value||'').trim();}
    function createRequestId(){
        if(window.crypto&&crypto.randomUUID)return crypto.randomUUID().replace(/-/g,'');
        return 'sivi'+Date.now().toString(36)+Math.random().toString(36).slice(2,18);
    }
    function updateGuidedUi(){
        guidedMode=!!(guidedToggle&&guidedToggle.checked);
        if(!guidedProgress)return;
        guidedProgress.hidden=!guidedMode;
        if(!guidedMode)return;
        if(guidedStage===1){guidedStep.textContent='Paso 1 de 2';guidedLabel.textContent='Escanee el serial';setTarget('serial_number');}
        else{guidedStep.textContent='Paso 2 de 2';guidedLabel.textContent='Escanee la Placa RNEC';setTarget('placa_rnec');}
    }
    function hideCandidates(){if(candidatesCard)candidatesCard.hidden=true;if(candidatesBox)candidatesBox.innerHTML='';}
    function renderCandidates(values,format){
        hideCandidates();
        if(!Array.isArray(values)||values.length<2){return false;}
        candidatesCard.hidden=false;
        values.forEach(function(candidate){
            var button=document.createElement('button');button.type='button';button.className='scanner-candidate';button.textContent=candidate;
            button.addEventListener('click',function(){updateValue(candidate,format||'Texto detectado · OCR');hideCandidates();valueInput.scrollIntoView({behavior:'smooth',block:'center'});});
            candidatesBox.appendChild(button);
        });
        setStatus('Se encontraron varios valores. Seleccione el serial o la placa correcta.','warning');
        candidatesCard.scrollIntoView({behavior:'smooth',block:'center'});
        return true;
    }
    function updateValue(raw,format){
        var value=String(raw||'').trim();if(!value)return;
        if(selectedTarget()==='placa_rnec')value=normalizePlate(value);
        valueInput.value=value;selectedFormat=format||'Código detectado';formatLabel.textContent=selectedFormat;
        setStatus('Valor detectado. Revíselo antes de enviarlo al computador.','success');
        if(navigator.vibrate)navigator.vibrate(80);
    }
    function stopCamera(){
        detecting=false;if(raf)cancelAnimationFrame(raf);raf=0;torchOn=false;
        if(stream){stream.getTracks().forEach(function(track){track.stop();});stream=null;}
        if(video){video.srcObject=null;video.hidden=true;}
        if(placeholder)placeholder.hidden=false;
        if(startBtn)startBtn.hidden=false;if(stopBtn)stopBtn.hidden=true;if(torchBtn)torchBtn.hidden=true;
    }
    async function buildDetector(){
        if(!('BarcodeDetector' in window))return null;
        var preferred=['qr_code','code_128','code_39','code_93','codabar','ean_13','ean_8','itf','upc_a','upc_e','data_matrix','pdf417','aztec'];
        try{
            var supported=BarcodeDetector.getSupportedFormats?await BarcodeDetector.getSupportedFormats():preferred;
            var formats=preferred.filter(function(item){return supported.indexOf(item)>=0;});
            return new BarcodeDetector(formats.length?{formats:formats}:undefined);
        }catch(e){return null;}
    }
    async function detectLoop(){
        if(!detecting||!detector||!stream)return;
        try{
            if(video.readyState>=2){
                var codes=await detector.detect(video);
                if(codes&&codes.length){
                    var code=codes[0],raw=String(code.rawValue||'').trim(),now=Date.now();
                    if(raw&&(raw!==lastDetected||now-lastDetectedAt>2500)){
                        lastDetected=raw;lastDetectedAt=now;updateValue(raw,String(code.format||'Código detectado').replace(/_/g,' ').toUpperCase());stopCamera();return;
                    }
                }
            }
        }catch(e){setStatus('No fue posible analizar el video. Use fotografía o galería.','warning');}
        if(detecting)raf=requestAnimationFrame(detectLoop);
    }
    function openImageInput(input,source){
        stopCamera();hideCandidates();
        if(!input){setStatus('La captura de imagen no está disponible. Digite el valor manualmente.','danger');return;}
        input.value='';setStatus(source==='gallery'?'Seleccione una imagen nítida de la etiqueta.':'Enfoque solamente el código o la etiqueta y confirme la fotografía.','active');input.click();
    }
    async function decodeImage(file){
        if(!file)return;
        if(file.size>12*1024*1024){setStatus('La imagen supera 12 MB. Seleccione otra con menor resolución.','danger');return;}
        [photoBtn,galleryBtn,startBtn].forEach(function(button){if(button)button.disabled=true;});
        hideCandidates();setStatus('Analizando código y texto de la etiqueta…','active');
        var data=new FormData();data.append('token',token);data.append('target',selectedTarget());data.append('image',file,file.name||'captura.jpg');
        try{
            var response=await fetch(imageUrl,{method:'POST',body:data,credentials:'omit',headers:{'X-Requested-With':'XMLHttpRequest'}});
            var result=await response.json();if(!response.ok||!result.ok)throw new Error(result.message||'No fue posible leer la imagen.');
            var candidates=Array.isArray(result.candidates)?result.candidates:[];
            if(!renderCandidates(candidates,result.format)){updateValue(result.value,result.format||'Valor detectado en imagen');}
        }catch(e){setStatus(e.message||'No fue posible leer la imagen. Intente de nuevo.','danger');}
        finally{[photoBtn,galleryBtn,startBtn].forEach(function(button){if(button)button.disabled=false;});if(photoInput)photoInput.value='';if(galleryInput)galleryInput.value='';}
    }
    async function startCamera(){
        setSendStatus('','');hideCandidates();
        if(!window.isSecureContext){setStatus('La cámara solo puede usarse mediante HTTPS. Abra el enlace seguro de SIVI.','danger');return;}
        if(!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia){if(imageEnabled){setStatus('Este navegador no permite video en vivo. Use fotografía o galería.','warning');openImageInput(photoInput,'camera');}else{setStatus('Este navegador no permite video en vivo y la captura por imagen está deshabilitada.','danger');}return;}
        detector=await buildDetector();
        if(!detector){if(imageEnabled){setStatus('Este navegador no tiene lector automático en vivo. Se abrirá la cámara para tomar una fotografía.','warning');openImageInput(photoInput,'camera');}else{setStatus('Este navegador no tiene lector automático y la captura por imagen está deshabilitada.','danger');}return;}
        try{
            stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'},width:{ideal:1920},height:{ideal:1080}},audio:false});
            video.srcObject=stream;video.hidden=false;placeholder.hidden=true;startBtn.hidden=true;stopBtn.hidden=false;
            await video.play();detecting=true;setStatus('Cámara activa. Mantenga el código dentro del recuadro.','active');
            var track=stream.getVideoTracks()[0];
            if(track&&track.getCapabilities){var capabilities=track.getCapabilities();if(capabilities&&capabilities.torch){torchBtn.hidden=false;}}
            raf=requestAnimationFrame(detectLoop);
        }catch(e){
            stopCamera();var denied=e&&(['NotAllowedError','PermissionDeniedError'].indexOf(e.name)>=0);
            setStatus(denied?(imageEnabled?'No se autorizó la cámara. Use fotografía, galería o habilite el permiso.':'No se autorizó la cámara. Habilite el permiso del navegador.'):(imageEnabled?'No fue posible iniciar el video. Use fotografía o galería.':'No fue posible iniciar el video.'),'danger');
        }
    }
    async function toggleTorch(){
        if(!stream)return;var track=stream.getVideoTracks()[0];if(!track||!track.applyConstraints)return;
        try{torchOn=!torchOn;await track.applyConstraints({advanced:[{torch:torchOn}]});torchBtn.textContent=torchOn?'Apagar linterna':'Encender linterna';}
        catch(e){torchOn=false;torchBtn.hidden=true;setStatus('La linterna no está disponible en este dispositivo.','warning');}
    }
    function sendPayload(payload){
        sendBtn.disabled=true;sendBtn.textContent='Enviando…';retryBtn.hidden=true;setSendStatus('Enviando al computador…','active');
        var data=new FormData();data.append('token',token);data.append('target',payload.target);data.append('value',payload.value);data.append('format',payload.format);data.append('request_id',payload.requestId);
        fetch(submitUrl,{method:'POST',body:data,credentials:'omit',headers:{'X-Requested-With':'XMLHttpRequest'}})
            .then(function(response){return response.json().then(function(result){return {response:response,result:result};});})
            .then(function(item){
                if(!item.response.ok||!item.result.ok)throw new Error(item.result.message||'No fue posible enviar el valor.');
                pendingSequence=Number(item.result.sequence||0);pendingRequestId=payload.requestId;
                setSendStatus('Enviado. Esperando confirmación del computador…','active');
                retryBtn.hidden=false;retryBtn.textContent='Comprobar recepción';checkSessionStatus();
            })
            .catch(function(error){setSendStatus(error.message||'No fue posible enviar el valor.','danger');retryBtn.hidden=false;retryBtn.textContent='Reintentar envío';})
            .finally(function(){sendBtn.disabled=false;sendBtn.textContent='Enviar al computador';});
    }
    function sendValue(){
        var value=valueInput.value.trim();if(!value){setSendStatus('Lea o escriba un valor antes de enviarlo.','danger');valueInput.focus();return;}
        var target=selectedTarget();if(target==='placa_rnec'){value=normalizePlate(value);valueInput.value=value;}
        pendingPayload={target:target,value:value,format:selectedFormat,requestId:createRequestId()};pendingSequence=0;sendPayload(pendingPayload);
    }
    function acknowledgeOnPhone(){
        if(!pendingPayload||pendingSequence<1)return;
        var target=pendingPayload.target,label=target==='placa_rnec'?'Placa RNEC':'Serial';
        setSendStatus('✓ '+label+' recibido y confirmado por el computador.','success');
        if(navigator.vibrate)navigator.vibrate([80,50,80]);
        valueInput.value='';formatLabel.textContent='Lectura manual';selectedFormat='Lectura manual';retryBtn.hidden=true;
        pendingPayload=null;pendingSequence=0;pendingRequestId='';
        if(guidedMode&&target==='serial_number'){
            guidedStage=2;updateGuidedUi();setStatus('Serial confirmado. Ahora escanee la Placa RNEC.','success');window.scrollTo({top:root.querySelector('.scanner-targets').offsetTop-20,behavior:'smooth'});
        }else if(guidedMode&&target==='placa_rnec'){
            guidedMode=false;guidedStage=1;guidedToggle.checked=false;updateGuidedUi();setStatus('Captura guiada completada: serial y placa recibidos.','success');
        }
    }
    function expireSession(){
        stopCamera();setConnection('Sesión vencida','danger');setStatus('La conexión venció. Regrese al computador para renovarla.','danger');
        [sendBtn,startBtn,photoBtn,galleryBtn].forEach(function(button){if(button)button.disabled=true;});
    }
    function checkSessionStatus(){
        if(!statusUrl||!token)return;
        var url=statusUrl+(statusUrl.indexOf('?')>=0?'&':'?')+new URLSearchParams({token:token}).toString();
        fetch(url,{credentials:'omit',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest'}})
            .then(function(response){return response.json().then(function(data){return {response:response,data:data};});})
            .then(function(item){
                if(!item.response.ok||!item.data.ok)throw new Error(item.data.message||'Conexión no disponible.');
                if(item.data.status!=='active'){expireSession();return;}
                setConnection('Celular conectado al computador','success');
                localExpiresAt=Date.now()+Number(item.data.expires_in||0)*1000;
                var ack=Number(item.data.ack_sequence||0);lastAckSequence=Math.max(lastAckSequence,ack);
                if(pendingSequence>0&&ack>=pendingSequence)acknowledgeOnPhone();
            })
            .catch(function(){setConnection('Reconectando con el computador…','warning');});
    }
    function updateCountdown(){
        if(!countdown)return;var remaining=Math.max(0,Math.floor((localExpiresAt-Date.now())/1000));
        var minutes=Math.floor(remaining/60),seconds=remaining%60;countdown.textContent=String(minutes).padStart(2,'0')+':'+String(seconds).padStart(2,'0');
        if(localExpiresAt>0&&remaining<=0)expireSession();
    }
    function configureBrowserHelp(){
        if(!browserHelp)return;
        if(!(isInApp||isIOS||!('BarcodeDetector' in window)))return;
        browserHelp.hidden=false;
        if(isWhatsApp){browserTitle.textContent='Enlace abierto dentro de WhatsApp';browserMessage.textContent='WhatsApp puede limitar la cámara en vivo. Use fotografía o abra el enlace directamente en Safari o Chrome.';}
        else if(isInstagram||isFacebook){browserTitle.textContent='Navegador interno detectado';browserMessage.textContent='Este navegador puede limitar la cámara. Abra el enlace en Safari o Chrome para mayor compatibilidad.';}
        else if(isIOS){browserTitle.textContent='Compatibilidad con iPhone';browserMessage.textContent='Puede usar fotografía o galería. Para cámara en vivo, abra el enlace directamente en Safari.';}
        if(browserInstructions){browserInstructions.textContent=isIOS?'En iPhone: toque el menú del navegador y seleccione “Abrir en Safari”, o copie el enlace.':(isAndroid?'En Android: use el menú y seleccione “Abrir en Chrome”.':'Copie el enlace y ábralo en el navegador principal.');}
    }
    function copyCurrentLink(){
        var url=window.location.href;var done=function(){copyLinkBtn.textContent='Enlace copiado';setTimeout(function(){copyLinkBtn.textContent='Copiar enlace';},1800);};
        if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(url).then(done).catch(function(){});}
        else{var area=document.createElement('textarea');area.value=url;area.style.position='fixed';area.style.opacity='0';document.body.appendChild(area);area.select();try{document.execCommand('copy');done();}catch(e){}area.remove();}
    }

    if(startBtn){startBtn.hidden=!liveEnabled;startBtn.disabled=!liveEnabled;}
    if(photoBtn){photoBtn.hidden=!imageEnabled;photoBtn.disabled=!imageEnabled;}
    if(galleryBtn){galleryBtn.hidden=!imageEnabled;galleryBtn.disabled=!imageEnabled;}
    if(valueInput){valueInput.readOnly=!manualEnabled;}
    if(!liveEnabled&&!imageEnabled&&manualEnabled){setStatus('Escriba el serial o la Placa RNEC y envíelo al computador.','');}
    else if(!liveEnabled&&imageEnabled){setStatus('Use una fotografía o seleccione una imagen de la galería.','');}
    else if(liveEnabled&&!imageEnabled){setStatus('Use la cámara en vivo para leer el código.','');}

    startBtn&&startBtn.addEventListener('click',startCamera);
    photoBtn&&photoBtn.addEventListener('click',function(){openImageInput(photoInput,'camera');});
    galleryBtn&&galleryBtn.addEventListener('click',function(){openImageInput(galleryInput,'gallery');});
    photoInput&&photoInput.addEventListener('change',function(){decodeImage(photoInput.files&&photoInput.files[0]);});
    galleryInput&&galleryInput.addEventListener('change',function(){decodeImage(galleryInput.files&&galleryInput.files[0]);});
    torchBtn&&torchBtn.addEventListener('click',toggleTorch);
    stopBtn&&stopBtn.addEventListener('click',function(){stopCamera();setStatus('Cámara detenida.','');});
    clearBtn.addEventListener('click',function(){valueInput.value='';selectedFormat='Lectura manual';formatLabel.textContent=selectedFormat;setSendStatus('','');hideCandidates();if(!valueInput.readOnly)valueInput.focus();});
    sendBtn.addEventListener('click',sendValue);
    retryBtn.addEventListener('click',function(){if(pendingSequence>0){setSendStatus('Comprobando recepción…','active');checkSessionStatus();}else if(pendingPayload){sendPayload(pendingPayload);}});
    guidedToggle.addEventListener('change',function(){guidedStage=1;updateGuidedUi();setSendStatus('','');});
    root.querySelectorAll('input[name="scan_target"]').forEach(function(input){input.addEventListener('change',function(){if(valueInput.value&&selectedTarget()==='placa_rnec')valueInput.value=normalizePlate(valueInput.value);});});
    copyLinkBtn&&copyLinkBtn.addEventListener('click',copyCurrentLink);
    if(shareLinkBtn&&navigator.share){shareLinkBtn.hidden=false;shareLinkBtn.addEventListener('click',function(){navigator.share({title:'Lector móvil SIVI',text:'Abra esta conexión temporal para capturar serial y Placa RNEC.',url:window.location.href}).catch(function(){});});}
    window.addEventListener('pagehide',stopCamera);document.addEventListener('visibilitychange',function(){if(document.hidden&&stream)stopCamera();});
    window.addEventListener('online',function(){setConnection('Reconectando con el computador…','warning');checkSessionStatus();});
    window.addEventListener('offline',function(){setConnection('Sin conexión a Internet','danger');});

    configureBrowserHelp();updateGuidedUi();checkSessionStatus();statusTimer=setInterval(checkSessionStatus,2200);setInterval(updateCountdown,1000);
})();
