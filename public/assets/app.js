/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: public/assets/app.js
 * Propósito: Administra la interacción general de la interfaz, navegación, sesiones, formularios y comportamiento dinámico.
 * Los bloques críticos se comentan para facilitar soporte y mantenimiento.
 */
document.documentElement.dataset.siviJsVersion="1.0.0.0";
    document.querySelectorAll("[data-confirm]").forEach(function(el){el.addEventListener("click",function(e){if(!confirm(el.dataset.confirm)){e.preventDefault();}})});
    var sidebarToggle=document.getElementById("sidebarToggle");
    var sidebarBackdrop=document.getElementById("sidebarBackdrop");
    var mobileNavigation=window.matchMedia("(max-width: 820px)");
    function setSidebarState(collapsed,persist){
        document.body.classList.toggle("sidebar-collapsed",collapsed);
        document.body.classList.toggle("sidebar-open",mobileNavigation.matches&&!collapsed);
        if(sidebarToggle){sidebarToggle.setAttribute("aria-expanded",collapsed?"false":"true");}
        if(persist!==false&&!mobileNavigation.matches){try{localStorage.setItem("sivi.sidebar.collapsed",collapsed?"1":"0");}catch(e){}}
    }
    function initialSidebarState(){
        if(mobileNavigation.matches){setSidebarState(true,false);return;}
        try{setSidebarState(localStorage.getItem("sivi.sidebar.collapsed")==="1",false);}catch(e){setSidebarState(false,false);}
    }
    initialSidebarState();
    if(sidebarToggle){sidebarToggle.addEventListener("click",function(){setSidebarState(!document.body.classList.contains("sidebar-collapsed"),true);});}
    if(sidebarBackdrop){sidebarBackdrop.addEventListener("click",function(){setSidebarState(true,false);});}
    document.querySelectorAll("#appSidebar .nav-link").forEach(function(link){link.addEventListener("click",function(){if(mobileNavigation.matches){setSidebarState(true,false);}});});
    if(mobileNavigation.addEventListener){mobileNavigation.addEventListener("change",initialSidebarState);}else if(mobileNavigation.addListener){mobileNavigation.addListener(initialSidebarState);}
    (function(){
        var body=document.body, idleLimit=Number(body.dataset.sessionIdleMs||1800000), warningWindow=Number(body.dataset.sessionWarningMs||120000), lastActivity=Date.now(), warningTimer=null, logoutTimer=null, countdownTimer=null;
        var modalElement=document.getElementById("sessionWarningModal");
        if(!modalElement){return;}
        var modal=bootstrap.Modal.getOrCreateInstance(modalElement,{backdrop:"static",keyboard:false});
        function clearTimers(){[warningTimer,logoutTimer,countdownTimer].forEach(function(t){if(t){clearTimeout(t);clearInterval(t);}});}
        function schedule(){clearTimers();var warnAfter=Math.max(0,idleLimit-warningWindow);warningTimer=setTimeout(showWarning,warnAfter);logoutTimer=setTimeout(redirectExpired,idleLimit);}
        function redirectExpired(){window.location.href=""+window.location.pathname+"?page=logout";}
        function showWarning(){
            var end=Date.now()+warningWindow;modal.show();
            function update(){var remain=Math.max(0,end-Date.now()),sec=Math.ceil(remain/1000),min=Math.floor(sec/60),rest=String(sec%60).padStart(2,"0");var el=document.getElementById("sessionCountdown");if(el){el.textContent=min+":"+rest;}if(remain<=0){redirectExpired();}}
            update();countdownTimer=setInterval(update,1000);
        }
        ["click","keydown","mousemove","scroll","touchstart"].forEach(function(evt){document.addEventListener(evt,function(){lastActivity=Date.now();},{passive:true});});
        var continueButton=document.getElementById("continueSession");
        if(continueButton){continueButton.addEventListener("click",function(){fetch(""+window.location.pathname+"?page=session_keepalive",{credentials:"same-origin",headers:{"X-Requested-With":"XMLHttpRequest"}}).then(function(r){if(!r.ok){throw new Error();}return r.json();}).then(function(){modal.hide();lastActivity=Date.now();schedule();}).catch(redirectExpired);});}
        schedule();
    })();
    document.querySelectorAll("form[data-territorial-filters]").forEach(function(form){
        var department=form.querySelector("[data-territorial-department]");
        var municipality=form.querySelector("[data-territorial-municipality]");
        var type=form.querySelector("[data-territorial-type]");
        var sede=form.querySelector("[data-territorial-sede]");
        if(!department||!municipality||!type||!sede){return;}
        function availableTypes(){var selected=municipality.options[municipality.selectedIndex]||null;if(!selected||!selected.value){return [];}try{return JSON.parse(selected.dataset.types||"[]");}catch(e){return [];}}
        function refreshMunicipalities(reset){var departmentValue=department.value;municipality.disabled=!departmentValue;Array.from(municipality.options).forEach(function(option){if(!option.value){option.hidden=false;option.disabled=false;return;}var visible=!!departmentValue&&option.dataset.department===departmentValue;option.hidden=!visible;option.disabled=!visible;});if(reset||municipality.disabled||municipality.selectedOptions.length&&municipality.selectedOptions[0].disabled){municipality.value="";}}
        function refreshTypes(reset){var municipalityValue=municipality.value,types=availableTypes();type.disabled=!municipalityValue;Array.from(type.options).forEach(function(option){if(!option.value){option.hidden=false;option.disabled=false;return;}var visible=!!municipalityValue&&types.indexOf(option.value)!==-1;option.hidden=!visible;option.disabled=!visible;});if(reset||type.disabled||type.selectedOptions.length&&type.selectedOptions[0].disabled){type.value="";}}
        function refreshSedes(reset){var departmentValue=department.value,municipalityValue=municipality.value,typeValue=type.value;sede.disabled=!typeValue;Array.from(sede.options).forEach(function(option){if(!option.value){option.hidden=false;option.disabled=false;return;}var visible=!!departmentValue&&!!municipalityValue&&!!typeValue&&option.dataset.department===departmentValue&&option.dataset.municipality===municipalityValue&&option.dataset.type===typeValue;option.hidden=!visible;option.disabled=!visible;});if(reset||sede.disabled||sede.selectedOptions.length&&sede.selectedOptions[0].disabled){sede.value="";}}
        department.addEventListener("change",function(){refreshMunicipalities(true);refreshTypes(true);refreshSedes(true);});
        municipality.addEventListener("change",function(){refreshTypes(true);refreshSedes(true);});
        type.addEventListener("change",function(){refreshSedes(true);});
        refreshMunicipalities(false);refreshTypes(false);refreshSedes(false);
    });
    document.querySelectorAll("form[data-user-form]").forEach(function(form){
        var role=form.querySelector("[data-user-role]");
        var panels=Array.from(form.querySelectorAll("[data-role-panel]"));
        function refreshRole(){
            var value=role?role.value:"registrador";
            panels.forEach(function(panel){
                var active=panel.dataset.rolePanel===value;
                panel.hidden=!active;
                panel.querySelectorAll("input,select,textarea").forEach(function(control){
                    if(!control.hasAttribute("data-role-required")){
                        control.setAttribute("data-role-required",control.required?"1":"0");
                    }
                    control.disabled=!active;
                    control.required=active&&control.getAttribute("data-role-required")==="1";
                });
            });
            var sede=form.querySelector("[data-user-sede],[data-sede-final]");
            if(sede){sede.required=value==="registrador";sede.disabled=value!=="registrador";}
        }
        if(role){role.addEventListener("change",refreshRole);refreshRole();}

        var scope=form.querySelector("[data-user-sede-filters]");
        if(!scope){return;}
        var department=scope.querySelector("[data-user-sede-department]");
        var municipality=scope.querySelector("[data-user-sede-municipality]");
        var type=scope.querySelector("[data-user-sede-type]");
        var sede=scope.querySelector("[data-user-sede]");
        function availableTypes(){var selected=municipality.options[municipality.selectedIndex]||null;if(!selected||!selected.value){return [];}try{return JSON.parse(selected.dataset.types||"[]");}catch(e){return [];}}
        function resetDisabled(select){if(select.selectedOptions.length&&select.selectedOptions[0].disabled){select.value="";}}
        function refreshMunicipalities(reset){var departmentValue=department.value;municipality.disabled=!departmentValue;Array.from(municipality.options).forEach(function(option){if(!option.value){option.hidden=false;option.disabled=false;return;}var visible=!!departmentValue&&option.dataset.department===departmentValue;option.hidden=!visible;option.disabled=!visible;});if(reset){municipality.value="";}else{resetDisabled(municipality);}}
        function refreshTypes(reset){var municipalityValue=municipality.value,types=availableTypes();type.disabled=!municipalityValue;Array.from(type.options).forEach(function(option){if(!option.value){option.hidden=false;option.disabled=false;return;}var visible=!!municipalityValue&&types.indexOf(option.value)!==-1;option.hidden=!visible;option.disabled=!visible;});if(reset){type.value="";}else{resetDisabled(type);}}
        function refreshSedes(reset){var departmentValue=department.value,municipalityValue=municipality.value,typeValue=type.value;sede.disabled=!typeValue;Array.from(sede.options).forEach(function(option){if(!option.value){option.hidden=false;option.disabled=false;return;}var visible=!!typeValue&&option.dataset.department===departmentValue&&option.dataset.municipality===municipalityValue&&option.dataset.type===typeValue;option.hidden=!visible;option.disabled=!visible;});if(reset){sede.value="";}else{resetDisabled(sede);}}
        department.addEventListener("change",function(){refreshMunicipalities(true);refreshTypes(true);refreshSedes(true);});
        municipality.addEventListener("change",function(){refreshTypes(true);refreshSedes(true);});
        type.addEventListener("change",function(){refreshSedes(true);});
        refreshMunicipalities(false);refreshTypes(false);refreshSedes(false);
    });
    document.querySelectorAll("[data-additional-images-settings]").forEach(function(section){
        var disabledSwitch=section.querySelector("[data-additional-images-disabled]");
        var options=section.querySelector("[data-additional-images-options]");
        if(!disabledSwitch||!options){return;}
        function refreshImagesSettings(){
            options.hidden=disabledSwitch.checked;
            options.querySelectorAll("select,input").forEach(function(control){
                control.disabled=disabledSwitch.checked;
            });
        }
        disabledSwitch.addEventListener("change",refreshImagesSettings);
        refreshImagesSettings();
    });
    document.querySelectorAll("[data-validation-images-settings]").forEach(function(section){
        var disabledSwitch=section.querySelector("[data-validation-images-disabled]");
        var options=section.querySelector("[data-validation-images-options]");
        if(!disabledSwitch||!options){return;}
        function refreshValidationImagesSettings(){
            options.hidden=disabledSwitch.checked;
            options.querySelectorAll("select,input").forEach(function(control){
                control.disabled=disabledSwitch.checked;
            });
        }
        disabledSwitch.addEventListener("change",refreshValidationImagesSettings);
        refreshValidationImagesSettings();
    });
    document.querySelectorAll("[data-cascade-sede-selector]").forEach(function(scope){
        var department=scope.querySelector("[data-sede-department]");
        var municipality=scope.querySelector("[data-sede-municipality]");
        var type=scope.querySelector("[data-sede-type]");
        var sede=scope.querySelector("[data-sede-final]");
        if(!department||!municipality||!type||!sede){return;}
        function availableTypes(){
            var selected=municipality.options[municipality.selectedIndex]||null;
            if(!selected||!selected.value){return [];}
            try{return JSON.parse(selected.dataset.types||"[]");}catch(error){return [];}
        }
        function resetIfUnavailable(select){if(select.selectedOptions.length&&select.selectedOptions[0].disabled){select.value="";}}
        function refreshMunicipalities(reset){
            var departmentValue=department.value;
            municipality.disabled=!departmentValue;
            Array.from(municipality.options).forEach(function(option){
                if(!option.value){option.hidden=false;option.disabled=false;return;}
                var visible=!!departmentValue&&option.dataset.department===departmentValue;
                option.hidden=!visible;option.disabled=!visible;
            });
            if(reset){municipality.value="";}else{resetIfUnavailable(municipality);}
        }
        function refreshTypes(reset){
            var municipalityValue=municipality.value,types=availableTypes();
            type.disabled=!municipalityValue;
            Array.from(type.options).forEach(function(option){
                if(!option.value){option.hidden=false;option.disabled=false;return;}
                var visible=!!municipalityValue&&types.indexOf(option.value)!==-1;
                option.hidden=!visible;option.disabled=!visible;
            });
            if(reset){type.value="";}else{resetIfUnavailable(type);}
        }
        function refreshSedes(reset){
            var departmentValue=department.value,municipalityValue=municipality.value,typeValue=type.value;
            var excluded=scope.dataset.excludeSedeId||"";
            sede.disabled=!typeValue;
            Array.from(sede.options).forEach(function(option){
                if(!option.value){option.hidden=false;option.disabled=false;return;}
                var visible=!!typeValue&&option.dataset.department===departmentValue&&option.dataset.municipality===municipalityValue&&option.dataset.type===typeValue&&(!excluded||option.value!==excluded);
                option.hidden=!visible;option.disabled=!visible;
            });
            if(reset){sede.value="";}else{resetIfUnavailable(sede);}
        }
        function notify(){sede.dispatchEvent(new CustomEvent("sivi:sede-selected",{bubbles:true,detail:{sedeId:sede.value}}));}
        department.addEventListener("change",function(){refreshMunicipalities(true);refreshTypes(true);refreshSedes(true);notify();});
        municipality.addEventListener("change",function(){refreshTypes(true);refreshSedes(true);notify();});
        type.addEventListener("change",function(){refreshSedes(true);notify();});
        sede.addEventListener("change",notify);
        scope.addEventListener("sivi:refresh-sede-selector",function(){refreshMunicipalities(false);refreshTypes(false);refreshSedes(false);});
        refreshMunicipalities(false);refreshTypes(false);refreshSedes(false);
    });
    document.querySelectorAll("[data-sede-gate]").forEach(function(gate){
        var source=gate.querySelector("[data-gate-sede-select]");
        if(!source){return;}
        var dependents=Array.from(gate.querySelectorAll("[data-sede-dependent]"));
        var hiddenInputs=Array.from(gate.querySelectorAll("[data-sede-hidden]"));
        var equipmentSelects=Array.from(gate.querySelectorAll("[data-equipment-sede-select]"));
        var rows=Array.from(gate.querySelectorAll("[data-sede-row]"));
        var emptyFiltered=Array.from(gate.querySelectorAll("[data-sede-filter-empty]"));
        var destinationScopes=Array.from(gate.querySelectorAll("[data-destination-sede-selector]"));
        function refresh(){
            var selected=source.value||"";
            var option=source.options[source.selectedIndex]||null;
            dependents.forEach(function(panel){panel.hidden=!selected;});
            hiddenInputs.forEach(function(input){var changed=input.value!==selected;input.value=selected;if(changed){input.dispatchEvent(new Event("input",{bubbles:true}));input.dispatchEvent(new Event("change",{bubbles:true}));}});
            gate.querySelectorAll("[data-selected-sede-name]").forEach(function(el){el.textContent=option?(option.dataset.siteLabel||option.textContent):"";});
            gate.querySelectorAll("[data-selected-sede-location]").forEach(function(el){el.textContent=option?(option.dataset.siteLocation||""):"";});
            equipmentSelects.forEach(function(select){
                Array.from(select.options).forEach(function(eqOption){
                    if(!eqOption.value){eqOption.hidden=false;eqOption.disabled=false;return;}
                    var visible=!!selected&&eqOption.dataset.sedeId===selected;
                    eqOption.hidden=!visible;eqOption.disabled=!visible;
                });
                if(select.selectedOptions.length&&select.selectedOptions[0].disabled){select.value="";}
                select.disabled=!selected;
            });
            var visibleRows=0;
            rows.forEach(function(row){
                var visible=!!selected&&row.dataset.sedeRow===selected;
                row.hidden=!visible;
                if(visible){visibleRows++;}
            });
            emptyFiltered.forEach(function(el){el.hidden=!selected||visibleRows>0;});
            destinationScopes.forEach(function(scope){
                scope.dataset.excludeSedeId=selected;
                scope.dispatchEvent(new CustomEvent("sivi:refresh-sede-selector"));
            });
        }
        source.addEventListener("change",refresh);
        source.addEventListener("sivi:sede-selected",refresh);
        refresh();
    });
    document.querySelectorAll("[data-placa-rnec]").forEach(function(input){
        // La placa se comporta como el campo de serial: texto continuo, sin máscara,
        // sin segmentación y sin validación del navegador durante la escritura.
        input.setAttribute("data-sivi-plate-input","1");
        input.setAttribute("inputmode","numeric");
        input.setAttribute("autocomplete","off");
        input.removeAttribute("pattern");
        input.removeAttribute("maxlength");
        input.removeAttribute("minlength");
    });
    document.querySelectorAll("[data-copy-suggested-plate]").forEach(function(button){
        button.addEventListener("click",function(){
            var form=button.closest("form")||document;
            var input=form.querySelector("input[name=placa_reported]");
            var unavailable=form.querySelector("[data-plate-unavailable]");
            var status=form.querySelector("select[name=placa_status]");
            if(unavailable&&unavailable.checked){unavailable.checked=false;unavailable.dispatchEvent(new Event("change",{bubbles:true}));}
            if(input){input.disabled=false;input.value=button.dataset.copySuggestedPlate||"";input.dispatchEvent(new Event("input",{bubbles:true}));input.focus();}
            if(status&&input&&input.value){status.value="corregida";}
        });
    });
    document.querySelectorAll("form").forEach(function(form){
        if(form.dataset.noSubmitLock==="1"){return;}
        form.addEventListener("submit",function(){
            if(!form.checkValidity()){return;}
            form.dataset.submitting="1";
            form.querySelectorAll("button[type=submit],input[type=submit]").forEach(function(button){
                button.disabled=true;
                if(button.tagName==="BUTTON"&&!button.dataset.originalText){button.dataset.originalText=button.textContent;button.textContent="Procesando…";}
            });
        });
    });
    document.querySelectorAll("form").forEach(function(form){
        var method=(form.getAttribute("method")||"get").toLowerCase();
        var editable=Array.from(form.querySelectorAll("input:not([type=hidden]):not([type=submit]):not([type=button]),select,textarea")).filter(function(el){return !el.disabled;});
        var shouldGuard=form.hasAttribute("data-dirty-guard")||(method==="post"&&editable.length>=2);
        if(!shouldGuard||form.dataset.noDirtyGuard==="1"){return;}
        var dirty=false;
        form.addEventListener("input",function(){dirty=true;});
        form.addEventListener("change",function(){dirty=true;});
        form.addEventListener("submit",function(){dirty=false;});
        window.addEventListener("beforeunload",function(event){if(dirty&&form.dataset.submitting!=="1"){event.preventDefault();event.returnValue="";}});
    });
    document.querySelectorAll("[data-filter-toggle]").forEach(function(button){
        var target=document.getElementById(button.dataset.filterToggle||"");if(!target){return;}
        button.addEventListener("click",function(){var hidden=target.hidden;target.hidden=!hidden;button.setAttribute("aria-expanded",hidden?"true":"false");});
    });
    document.querySelectorAll("[data-asset-validation-form]").forEach(function(form){
        var statusInputs=Array.from(form.querySelectorAll("[data-asset-status-selector]"));
        var ownershipInputs=Array.from(form.querySelectorAll("[data-ownership-selector]"));
        var belongsInputs=Array.from(form.querySelectorAll("[data-belongs-selector]"));
        var belongsPanel=form.querySelector("[data-belongs-panel=\"no_pertenece\"]");
        var belongsReason=form.elements.belongs_reason||null;
        var belongsOtherPanel=form.querySelector("[data-belongs-other-panel]");
        var belongsOtherReason=form.elements.belongs_reason_other||null;
        var plate=form.querySelector("[data-conditional-plate]");
        var ownPlateException=form.querySelector("[data-own-plate-exception]");
        var plateUnavailable=form.querySelector("[data-plate-unavailable]");
        var plateUnavailablePanel=form.querySelector("[data-plate-unavailable-panel]");
        var plateUnavailableReason=form.elements.plate_unavailable_reason||null;
        var suggestedPlateActions=Array.from(form.querySelectorAll("[data-suggested-plate-action]"));
        var disposal=form.querySelector("[data-status-panel=\"dado_baja\"]");
        var transfer=form.querySelector("[data-status-panel=\"trasladado\"]");
        var steps=Array.from(form.querySelectorAll("[data-wizard-step]"));
        var progress=Array.from(form.parentElement.querySelectorAll("[data-wizard-go]"));
        var errorBox=form.querySelector("[data-wizard-error]");
        var completion=form.parentElement.querySelector("[data-wizard-completion]");
        var current=0,maxReached=0;
        if(!statusInputs.length||!ownershipInputs.length||!belongsInputs.length)return;
        form.classList.add("wizard-enhanced");
        var draftKey=form.dataset.draftKey||"";
        var draftEndpoint=form.dataset.draftEndpoint||"";
        var draftStatus=form.querySelector("[data-draft-status]");
        var draftTimer=null,serverDraftTimer=null,draftRequest=null;
        function draftFields(){return Array.from(form.elements).filter(function(el){return el.name&&el.name!=="csrf"&&el.type!=="file"&&el.type!=="submit"&&el.type!=="button";});}
        function updateDraftStatus(message,tone){if(!draftStatus)return;var small=draftStatus.querySelector("small");if(small)small.textContent=message;draftStatus.classList.remove("is-saved","is-restored","is-error");if(tone)draftStatus.classList.add("is-"+tone);}
        function serializeDraft(){var data={savedAt:new Date().toISOString(),values:{}};draftFields().forEach(function(el){if(el.type==="radio"){if(el.checked)data.values[el.name]=el.value;}else if(el.type==="checkbox"){data.values[el.name]=el.checked?"1":"0";}else{data.values[el.name]=el.value;}});return data;}
        function applyDraftValues(values){if(!values||typeof values!=="object")return false;var changed=false;Object.keys(values).forEach(function(name){var controls=Array.from(form.querySelectorAll("[name=\""+CSS.escape(name)+"\"]"));controls.forEach(function(el){if(el.type==="radio"){el.checked=el.value===String(values[name]);changed=changed||el.checked;}else if(el.type==="checkbox"){el.checked=String(values[name])==="1";changed=true;}else{el.value=values[name]==null?"":String(values[name]);changed=true;}});});return changed;}
        function saveDraftLocal(){if(!draftKey)return;try{localStorage.setItem(draftKey,JSON.stringify(serializeDraft()));}catch(e){updateDraftStatus("No fue posible guardar el borrador en este navegador.","error");}}
        function saveDraftServer(){
            if(!draftEndpoint||!navigator.onLine)return;
            var csrf=form.elements.csrf?form.elements.csrf.value:"";
            var body=new FormData();body.append("csrf",csrf);body.append("payload",JSON.stringify(serializeDraft().values));
            if(draftRequest&&typeof draftRequest.abort==="function")draftRequest.abort();
            draftRequest=typeof AbortController!=="undefined"?new AbortController():null;
            fetch(draftEndpoint,{method:"POST",body:body,credentials:"same-origin",headers:{"X-Requested-With":"XMLHttpRequest"},signal:draftRequest?draftRequest.signal:undefined})
                .then(function(response){if(!response.ok)throw new Error("draft");return response.json();})
                .then(function(data){if(data&&data.ok)updateDraftStatus("Borrador guardado en SIVI a las "+new Date().toLocaleTimeString([], {hour:"2-digit",minute:"2-digit"})+".","saved");})
                .catch(function(error){if(error&&error.name==="AbortError")return;updateDraftStatus("El borrador quedó guardado en este dispositivo; SIVI lo sincronizará cuando haya conexión.","error");});
        }
        function saveDraft(){saveDraftLocal();saveDraftServer();}
        function scheduleDraft(){if(draftTimer)clearTimeout(draftTimer);if(serverDraftTimer)clearTimeout(serverDraftTimer);draftTimer=setTimeout(saveDraftLocal,450);serverDraftTimer=setTimeout(saveDraftServer,1200);}
        function restoreDraftLocal(){if(!draftKey)return false;try{var raw=localStorage.getItem(draftKey);if(!raw)return false;var draft=JSON.parse(raw);if(!applyDraftValues(draft.values||{}))return false;updateDraftStatus("Se recuperó un borrador guardado en este dispositivo. Revise la información antes de continuar.","restored");return true;}catch(e){return false;}}
        function restoreDraftServer(){if(!draftEndpoint||!navigator.onLine)return Promise.resolve(false);return fetch(draftEndpoint,{credentials:"same-origin",headers:{"X-Requested-With":"XMLHttpRequest"}}).then(function(response){if(!response.ok)throw new Error("draft");return response.json();}).then(function(data){if(data&&data.ok&&data.draft&&applyDraftValues(data.draft)){updateDraftStatus("Se recuperó el último borrador guardado en SIVI. Revise la información antes de continuar.","restored");return true;}return false;}).catch(function(){return false;});}
        function clearDraft(){if(draftKey){try{localStorage.removeItem(draftKey);}catch(e){}}updateDraftStatus("Borrador descartado. Se conservarán únicamente los datos confirmados en SIVI.","");}
        var restoredLocal=false;
        if(form.dataset.clearDraft==="1"){clearDraft();}else{restoredLocal=restoreDraftLocal();if(!restoredLocal){restoreDraftServer().then(function(restored){if(restored){update();}});}}

        function checkedValue(inputs){var found=inputs.find(function(el){return el.checked;});return found?found.value:"";}
        function checkedLabel(inputs){var found=inputs.find(function(el){return el.checked;});if(!found)return "Pendiente";var card=found.closest(".choice-card");var title=card?card.querySelector("strong"):null;return title?title.textContent.trim():found.value;}
        function normalizeText(value){return (value||"").trim().toUpperCase();}
        function normalizePlate(value){return (value||"").replace(/\D/g,"");}
        function plateDigitsExpected(){var total=parseInt(plate.dataset.plateTotalCharacters||"9",10);return Math.max(4,total-1);}
        function plateEntryComplete(value){var raw=(value||"").trim(),digits=normalizePlate(raw);return raw!==""&&/^[0-9\s-]+$/.test(raw)&&digits.indexOf("000")===0&&digits.length===plateDigitsExpected();}
        function setPanel(panel,active){
            if(!panel)return;
            panel.hidden=!active;
            panel.querySelectorAll("input,select,textarea").forEach(function(el){el.disabled=!active;if(active&&el.type!=="file")el.setAttribute("required","required");else if(!active)el.removeAttribute("required");});
        }
        function destinationText(){var el=transfer?transfer.querySelector("[data-sede-final]"):null;return el&&el.value&&el.options[el.selectedIndex]?el.options[el.selectedIndex].text:"No aplica";}
        function setResult(kind,text,tone){var el=form.querySelector("[data-verification-result=\""+kind+"\"]");if(!el)return;el.textContent=text;el.className="verification-result is-"+tone;}
        function firstInvalid(section){return Array.from(section.querySelectorAll("input,select,textarea")).find(function(el){return !el.disabled&&el.validity&&!el.validity.valid;})||null;}
        function showError(message,invalid){if(errorBox){errorBox.textContent=message;errorBox.hidden=false;}if(invalid){invalid.focus({preventScroll:true});invalid.scrollIntoView({behavior:"smooth",block:"center"});invalid.reportValidity();}}
        function clearError(){if(errorBox){errorBox.hidden=true;errorBox.textContent="";}}
        function setStep(index,focus){current=Math.max(0,Math.min(index,steps.length-1));maxReached=Math.max(maxReached,current);steps.forEach(function(step,i){step.hidden=i!==current;step.classList.toggle("is-current",i===current);});progress.forEach(function(button,i){button.classList.toggle("is-active",i===current);button.classList.toggle("is-complete",i<current);button.disabled=i>maxReached;button.setAttribute("aria-current",i===current?"step":"false");});clearError();if(focus){steps[current].scrollIntoView({behavior:"smooth",block:"start"});var heading=steps[current].querySelector(".wizard-step-title strong");if(heading){heading.setAttribute("tabindex","-1");heading.focus({preventScroll:true});}}}
        function validateStep(index){var invalid=firstInvalid(steps[index]);if(invalid){showError("Complete los campos obligatorios de este paso para continuar.",invalid);return false;}clearError();return true;}
        function calculateCompletion(){var relevant=Array.from(form.querySelectorAll("input[required],select[required],textarea[required]")).filter(function(el){return !el.disabled;});var radioNames={};relevant.filter(function(el){return el.type==="radio";}).forEach(function(el){radioNames[el.name]=true;});var nonRadio=relevant.filter(function(el){return el.type!=="radio";});var nonRadioComplete=nonRadio.filter(function(el){if(el.type==="checkbox")return el.checked;if(el.type==="file")return !!el.files.length;return !!String(el.value||"").trim()&&(!el.validity||el.validity.valid);}).length;var radioComplete=Object.keys(radioNames).filter(function(name){return !!form.querySelector("input[name=\""+CSS.escape(name)+"\"]:checked");}).length;var total=nonRadio.length+Object.keys(radioNames).length,done=nonRadioComplete+radioComplete;var pct=total?Math.round(done/total*100):100;if(completion)completion.textContent=pct+"%";var summaryStatus=form.querySelector("[data-summary-status]");if(summaryStatus){summaryStatus.textContent=pct===100?"Lista para guardar":"Información incompleta";summaryStatus.className=pct===100?"is-ready":"";}}
        function update(){
            var status=checkedValue(statusInputs),ownership=checkedValue(ownershipInputs),belongs=checkedValue(belongsInputs),isDisposal=status==="dado_baja",isTransfer=status==="trasladado",isOwn=ownership==="propio",doesNotBelong=belongs==="no_pertenece";
            setPanel(belongsPanel,doesNotBelong);setPanel(disposal,isDisposal);setPanel(transfer,isTransfer);
            var belongsOther=doesNotBelong&&belongsReason&&belongsReason.value==="otro";
            if(belongsOtherPanel){belongsOtherPanel.hidden=!belongsOther;}
            if(belongsOtherReason){belongsOtherReason.disabled=!belongsOther;belongsOtherReason.required=belongsOther;}
            if(ownPlateException)ownPlateException.hidden=!isOwn;
            if(plateUnavailable&&!isOwn){plateUnavailable.checked=false;}
            if(plateUnavailable)plateUnavailable.disabled=!isOwn;
            var plateIsUnavailable=isOwn&&!!(plateUnavailable&&plateUnavailable.checked);
            if(plateUnavailablePanel)plateUnavailablePanel.hidden=!plateIsUnavailable;
            if(plateUnavailableReason){plateUnavailableReason.disabled=!plateIsUnavailable;plateUnavailableReason.required=plateIsUnavailable;}
            if(plate){plate.disabled=plateIsUnavailable;if(isOwn&&!plateIsUnavailable)plate.setAttribute("required","required");else plate.removeAttribute("required");}
            suggestedPlateActions.forEach(function(button){button.hidden=!isOwn||plateIsUnavailable;button.disabled=!isOwn||plateIsUnavailable;});
            var marker=form.querySelector("[data-plate-required-marker]");if(marker){marker.textContent=isOwn&&!plateIsUnavailable?"*":(plateIsUnavailable?"(justificar)":"(opcional)");marker.classList.toggle("text-danger",isOwn&&!plateIsUnavailable);}
            var plateHelp=form.querySelector("[data-plate-help]");if(plateHelp)plateHelp.textContent=plateIsUnavailable?"Puede continuar sin digitar la placa porque registrará el motivo por el cual no fue posible visualizarla.":(isOwn?"Obligatoria para equipos propios de la RNEC, salvo que físicamente no sea posible visualizarla.":"Opcional para comodatos y donados sin legalizar.");
            if(belongsReason&&belongsReason.value==="trasladado"&&!isTransfer){var transferInput=statusInputs.find(function(el){return el.value==="trasladado";});if(transferInput){transferInput.checked=true;status="trasladado";isTransfer=true;setPanel(transfer,true);}}
            var serialInput=form.elements.serial_reported,serialValue=serialInput?serialInput.value.trim():"",originalSerial=form.dataset.originalSerial||"";
            if(!serialValue)setResult("serial","Pendiente de verificar","pending");else if(originalSerial&&normalizeText(serialValue)===normalizeText(originalSerial))setResult("serial","Coincide con el inventario","match");else setResult("serial",originalSerial?"Se actualizará el serial":"Serial nuevo registrado","change");
            var plateValue=plate&&!plate.disabled?plate.value.trim():"",originalPlate=form.dataset.originalPlate||"";
            if(plateIsUnavailable)setResult("plate","Placa no visible · motivo requerido","change");else if(!plateValue)setResult("plate",isOwn?"Placa obligatoria pendiente":"Sin placa / opcional",isOwn?"pending":"neutral");else if(originalPlate&&normalizePlate(plateValue)===normalizePlate(originalPlate))setResult("plate","Coincide con el inventario","match");else setResult("plate",originalPlate?"Se actualizará la placa":"Placa nueva registrada","change");
            var plateSummary=plateIsUnavailable?"No visible · observación registrada":(plateValue||(isOwn?"Obligatoria":"No aplica / opcional"));
            var map={"data-summary-belongs":belongs?checkedLabel(belongsInputs):"Pendiente","data-summary-condition":checkedLabel(statusInputs),"data-summary-ownership":checkedLabel(ownershipInputs),"data-summary-serial":serialValue||"Pendiente","data-summary-plate":plateSummary,"data-summary-destination":isTransfer?destinationText():"No aplica"};
            Object.keys(map).forEach(function(k){var el=form.querySelector("["+k+"]");if(el)el.textContent=map[k];});calculateCompletion();
        }

        form.querySelectorAll("[data-wizard-next]").forEach(function(button){button.addEventListener("click",function(){if(validateStep(current))setStep(current+1,true);});});
        form.querySelectorAll("[data-wizard-back]").forEach(function(button){button.addEventListener("click",function(){setStep(current-1,true);});});
        progress.forEach(function(button,i){button.addEventListener("click",function(){if(i<=maxReached)setStep(i,true);});});
        form.querySelectorAll("[data-fill-target]").forEach(function(button){button.addEventListener("click",function(){var input=form.elements[button.dataset.fillTarget];if(input){input.value=button.dataset.fillValue||"";input.dispatchEvent(new Event("input",{bubbles:true}));input.focus();}});});
        form.querySelectorAll("[data-file-preview]").forEach(function(input){input.addEventListener("change",function(){var label=input.closest(".evidence-upload-card"),name=label?label.querySelector("[data-file-name]"):null;if(name)name.textContent=input.files&&input.files[0]?input.files[0].name:"Ningún archivo seleccionado";if(label)label.classList.toggle("has-file",!!(input.files&&input.files.length));calculateCompletion();});});
        form.addEventListener("input",function(){update();scheduleDraft();});form.addEventListener("change",function(){update();scheduleDraft();});
        form.addEventListener("submit",function(event){for(var i=0;i<steps.length;i++){var invalid=firstInvalid(steps[i]);if(invalid){event.preventDefault();setStep(i,true);showError("Revise el campo señalado antes de guardar la validación.",invalid);return;}}saveDraft();});
        update();setStep(0,false);
    });
    document.querySelectorAll("[data-campaign-wizard]").forEach(function(form){
        var wrapper=form.closest(".campaign-wizard");
        var steps=Array.from(form.querySelectorAll("[data-campaign-step]"));
        var progress=wrapper?Array.from(wrapper.querySelectorAll("[data-campaign-go]")):[];
        var progressText=wrapper?wrapper.querySelector("[data-campaign-wizard-progress]"):null;
        var errorBox=form.querySelector("[data-campaign-error]");
        var scopeInputs=Array.from(form.querySelectorAll("[data-scope-mode]"));
        var current=0,maxReached=0;
        form.classList.add("campaign-wizard-enhanced");
        if(wrapper)wrapper.classList.add("campaign-wizard-ready");
        function selectedScope(){var found=scopeInputs.find(function(el){return el.checked;});return found?found.value:"nacional";}
        function selectedText(selector){return Array.from(form.querySelectorAll(selector)).filter(function(el){return el.checked||el.selected;}).map(function(el){var card=el.closest(".choice-card"),strong=card?card.querySelector("strong"):null;return strong?strong.textContent.trim():(el.textContent||el.value).trim();});}
        function updateScope(){var mode=selectedScope();form.querySelectorAll("[data-scope-panel]").forEach(function(panel){var active=panel.dataset.scopePanel===mode;panel.hidden=!active;panel.querySelectorAll("input,select").forEach(function(el){el.disabled=!active;});});updateSummary();}
        function firstInvalid(step){return Array.from(step.querySelectorAll("input,select,textarea")).find(function(el){return !el.disabled&&el.validity&&!el.validity.valid;})||null;}
        function scopeValid(){var mode=selectedScope();if(mode==="departamental")return form.querySelectorAll('input[name="department_codes[]"]:checked').length>0;if(mode==="municipal")return Array.from(form.querySelectorAll('select[name="municipalities[]"] option:checked')).length>0;if(mode==="sedes")return Array.from(form.querySelectorAll('select[name="sede_ids[]"] option:checked')).length>0;return true;}
        function categoryValid(){return form.querySelectorAll('input[name="asset_categories[]"]:checked').length>0;}
        function showCampaignError(message,invalid){if(errorBox){errorBox.textContent=message;errorBox.hidden=false;}if(invalid){invalid.focus({preventScroll:true});invalid.scrollIntoView({behavior:"smooth",block:"center"});invalid.reportValidity();}}
        function clearCampaignError(){if(errorBox){errorBox.hidden=true;errorBox.textContent="";}}
        function validate(index){clearCampaignError();var step=steps[index];if(!step)return false;var invalid=firstInvalid(step);if(invalid){showCampaignError("Complete el campo obligatorio señalado antes de continuar.",invalid);return false;}if(index===1&&!scopeValid()){showCampaignError("Seleccione al menos un departamento, municipio o sede para el alcance elegido.");return false;}if(index===2&&!categoryValid()){showCampaignError("Seleccione al menos un tipo de equipo para la campaña.");return false;}return true;}
        function setStep(index,focus){if(!steps.length)return;current=Math.max(0,Math.min(index,steps.length-1));maxReached=Math.max(maxReached,current);steps.forEach(function(step,i){step.hidden=i!==current;step.classList.toggle("is-current",i===current);});progress.forEach(function(button,i){button.classList.toggle("is-active",i===current);button.classList.toggle("is-complete",i<current);button.disabled=i>maxReached;button.setAttribute("aria-current",i===current?"step":"false");});if(progressText)progressText.textContent=(current+1)+" de "+steps.length;clearCampaignError();updateSummary();if(focus&&steps[current]){steps[current].scrollIntoView({behavior:"smooth",block:"start"});var heading=steps[current].querySelector(".wizard-step-title strong");if(heading){heading.setAttribute("tabindex","-1");heading.focus({preventScroll:true});}}}
        function put(name,text){var el=form.querySelector('[data-campaign-summary="'+name+'"]');if(el)el.textContent=text||"Pendiente";}
        function updateSummary(){
            var nameField=form.elements.namedItem("name"),startField=form.elements.namedItem("start_date"),endField=form.elements.namedItem("end_date");
            var name=nameField?nameField.value.trim():"";
            var start=startField?startField.value:"",end=endField?endField.value:"";
            var mode=selectedScope(),scopeLabels={nacional:"Nacional",departamental:"Departamentos seleccionados",municipal:"Municipios seleccionados",sedes:"Sedes específicas"};
            var categories=selectedText('input[name="asset_categories[]"]:checked');
            put("name",name||"Pendiente");put("dates",start&&end?start+" a "+end:"Pendiente");put("scope",scopeLabels[mode]||mode);put("categories",categories.length?categories.join(", "):"Pendiente");put("evidence",form.elements.requires_evidence&&form.elements.requires_evidence.checked?"Obligatoria":"Opcional");put("overlap",form.elements.allow_overlap&&form.elements.allow_overlap.checked?"Permitidos":"Bloqueados");
        }
        form.querySelectorAll("[data-campaign-next]").forEach(function(button){button.addEventListener("click",function(event){event.preventDefault();if(validate(current))setStep(current+1,true);});});
        form.querySelectorAll("[data-campaign-back]").forEach(function(button){button.addEventListener("click",function(){setStep(current-1,true);});});
        progress.forEach(function(button,i){button.addEventListener("click",function(){if(i<=maxReached)setStep(i,true);});});
        scopeInputs.forEach(function(input){input.addEventListener("change",updateScope);});
        form.addEventListener("input",updateSummary);form.addEventListener("change",updateSummary);
        form.addEventListener("submit",function(event){if(!validate(0)||!scopeValid()||!categoryValid()){event.preventDefault();setStep(!scopeValid()?1:(!categoryValid()?2:0),true);}});
        updateScope();setStep(0,false);
    });
    document.querySelectorAll("[data-reopening-selection]").forEach(function(select){
        select.addEventListener("change",function(){var parts=select.value.split("|");var form=select.form;if(!form)return;var campaign=form.elements.campaign_id,sede=form.elements.sede_id;if(campaign)campaign.value=parts[0]||"";if(sede)sede.value=parts[1]||"";});
    });
    document.querySelectorAll("[data-print-page]").forEach(function(button){button.addEventListener("click",function(){window.print();});});
    document.querySelectorAll("[data-history-back]").forEach(function(link){link.addEventListener("click",function(event){if(window.history.length>1){event.preventDefault();window.history.back();}});});
    (function(){
        var banner=document.createElement("div");
        banner.className="network-status-banner";
        banner.setAttribute("role","status");
        banner.setAttribute("aria-live","polite");
        banner.hidden=true;
        document.body.appendChild(banner);
        function updateNetwork(){var offline=!navigator.onLine;banner.hidden=!offline;banner.textContent=offline?"Sin conexión. Los borradores se conservarán en este dispositivo hasta recuperar internet.":"";document.body.classList.toggle("is-offline",offline);document.querySelectorAll("[data-network-indicator]").forEach(function(el){el.textContent=offline?"Sin conexión":"En línea";el.classList.toggle("is-offline",offline);});}
        window.addEventListener("online",updateNetwork);window.addEventListener("offline",updateNetwork);updateNetwork();
    })();
    document.querySelectorAll("[data-additional-equipment-form]").forEach(function(form){
        var serial=form.querySelector("[data-additional-serial]");
        var serialConfirmation=form.querySelector("[data-additional-serial-confirmation]");
        var plate=form.querySelector("[data-additional-plate]");
        var plateConfirmation=form.querySelector("[data-additional-plate-confirmation]");
        var category=form.querySelector("[data-additional-category]");
        var ownership=form.querySelector("[data-additional-ownership]");
        var equipmentState=form.querySelector("[data-additional-state]");
        var sedeInput=form.querySelector("[data-sede-hidden]");
        var manufacturer=form.querySelector("[data-additional-manufacturer]");
        var model=form.querySelector("[data-additional-model]");
        var brandOptions=form.querySelector("[data-additional-brand-options]");
        var modelOptions=form.querySelector("[data-additional-model-options]");
        var guide=form.querySelector("[data-additional-category-guide]");
        var summary=form.querySelector("[data-additional-form-summary]");
        var technicalChoice=form.querySelector("[data-additional-technical-choice]");
        var technicalPanel=form.querySelector("[data-additional-technical-panel]");
        var status=form.querySelector("[data-additional-identity-status]");
        var conflictModalElement=document.querySelector("[data-additional-conflict-modal]");
        var conflictModalBody=conflictModalElement?conflictModalElement.querySelector("[data-additional-conflict-modal-body]"):null;
        var conflictModal=conflictModalElement&&window.bootstrap
            ?bootstrap.Modal.getOrCreateInstance(conflictModalElement,{backdrop:"static",keyboard:true})
            :null;
        var submit=form.querySelector("[data-additional-submit]");
        if(!serial||!plate||!category||!ownership||!status||!submit||!technicalChoice){return;}
        var rules={};try{rules=JSON.parse(form.dataset.categoryRules||"{}");}catch(e){rules={};}
        var technicalCatalogs={};try{technicalCatalogs=JSON.parse(form.dataset.technicalCatalogs||"{}");}catch(e){technicalCatalogs={};}
        var timer=null,requestController=null,identityRequestSequence=0,identityTimeoutMs=3000,identityCacheTtlMs=60000,identityCacheMaxEntries=100,identityResultCache=new Map(),catalogCacheTtlMs=300000,catalogCacheMaxEntries=100,catalogResultCache=new Map(),blocked=!!status.querySelector(".additional-conflict-card"),identityCheckUnavailable=false;
        var previousCategory=category.value||"";
        function esc(value){return String(value==null?"":value).replace(/[&<>"']/g,function(ch){return {"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#039;"}[ch];});}
        function requiredControlsReady(){var controls=Array.from(form.querySelectorAll("input,select,textarea"));return controls.every(function(control){if(control.disabled||!control.required){return true;}if(control===plate){return plateEntryComplete(control.value);}if(control.type==="checkbox"){return control.checked;}return control.validity?control.validity.valid:!!String(control.value||"").trim();});}function updateSubmitState(){var sedeReady=!!sedeInput&&!!String(sedeInput.value||"").trim();var disabled=blocked||!sedeReady||!requiredControlsReady();submit.disabled=disabled;submit.setAttribute("aria-disabled",disabled?"true":"false");submit.dataset.identityCheckUnavailable=identityCheckUnavailable?"1":"0";}
        function setBlocked(value){blocked=!!value;form.dataset.identityConflict=blocked?"1":"0";updateSubmitState();}
        function showConflictModal(html){
            if(conflictModalBody&&typeof html==="string"&&html.trim()!==""){
                conflictModalBody.innerHTML=html;
                conflictModalBody.querySelectorAll("[data-additional-conflict-open]").forEach(function(button){button.remove();});
            }
            if(conflictModal){conflictModal.show();}
        }
        function hideConflictModal(){if(conflictModal){conflictModal.hide();}}
        function clearStatus(){identityCheckUnavailable=false;status.hidden=true;status.innerHTML="";hideConflictModal();if(conflictModalBody){conflictModalBody.innerHTML="";}setBlocked(false);}
        function renderChecking(){identityCheckUnavailable=false;status.hidden=false;status.innerHTML='<div class="additional-identity-checking" role="status"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span>Comprobando serial y placa en el inventario…</span></div>';setBlocked(false);}
        function renderError(){var fallbackMessage="No fue posible comprobar el serial y la placa en este momento. El servidor volverá a validarlos al registrar.";identityCheckUnavailable=true;status.hidden=false;status.innerHTML='<div class="alert alert-warning mb-0" role="alert"><strong>'+esc(fallbackMessage)+'</strong><div class="small mt-1">Puede continuar cuando los campos obligatorios estén completos.</div></div>';setBlocked(false);}
        function renderAvailable(){identityCheckUnavailable=false;status.hidden=false;status.innerHTML='<div class="additional-identity-available" role="status"><span aria-hidden="true">✓</span><div><strong>Equipo no registrado previamente</strong><small>El serial y la placa están disponibles. Puede registrar el equipo adicional.</small></div></div>';hideConflictModal();if(conflictModalBody){conflictModalBody.innerHTML="";}setBlocked(false);}
        function renderConflicts(data){
            identityCheckUnavailable=false;
            var html='<div class="additional-conflict-card" role="alert"><div class="additional-conflict-heading"><span aria-hidden="true">⚠</span><div><strong>Este elemento ya está registrado</strong><small>No puede crearse como equipo adicional hasta resolver la coincidencia.</small></div></div>';
            if(data.identity_split){html+='<div class="additional-conflict-critical"><strong>Atención:</strong> el serial y la placa apuntan a registros diferentes. Verifique físicamente ambas etiquetas.</div>';}
            html+='<div class="additional-conflict-list">';
            (data.conflicts||[]).forEach(function(item){
                var location=[item.sede_identificador,item.sede_nombre].filter(Boolean).join(" · ")||"Pendiente de asociación";
                var territory=[item.municipio,item.departamento].filter(Boolean).join(" / ");
                html+='<div class="additional-conflict-item"><div><span class="badge text-bg-danger">Coincidencia por '+esc(item.matched_by||"identificador")+'</span> <span class="badge text-bg-secondary">'+esc(item.source_label||"Registro existente")+'</span></div>';
                html+='<strong>'+esc(item.name||"Elemento registrado")+'</strong>';
                html+='<span>'+esc(item.category_label||"Otra categoría")+(item.equipment_type?' · '+esc(item.equipment_type):'')+'</span>';
                html+='<span><b>Sede:</b> '+esc(location)+(territory?' · '+esc(territory):'')+'</span>';
                html+='<span><b>Registro:</b> #'+esc(item.id||"")+' · <b>Placa:</b> '+esc(item.placa_rnec||"Sin placa")+' · <b>Serial:</b> '+esc(item.serial_number||"Sin serial")+'</span>';
                if(item.category_mismatch){html+='<div class="additional-category-mismatch"><strong>Categoría diferente:</strong> seleccionó '+esc(data.selected_category_label||"")+', pero el elemento está registrado como '+esc(item.category_label||"")+'.</div>';}
                if(item.campaign_name){html+='<span><b>Campaña:</b> '+esc(item.campaign_name)+' · Estado '+esc(item.status||"")+'</span>';}
                if(item.view_url){html+='<a class="btn btn-sm btn-outline-primary" href="'+esc(item.view_url)+'">Ver equipo registrado</a>';}
                html+='</div>';
            });
            html+='<div class="additional-conflict-actions"><button class="btn btn-sm btn-primary" type="button" data-additional-conflict-open>Ver ubicación del equipo</button></div>';
            var conflictHtml=html+'</div></div>';
            status.hidden=false;
            status.innerHTML=conflictHtml;
            setBlocked(true);
            showConflictModal(conflictHtml);
        }
        function normalizeSerial(value){return String(value||"").toUpperCase().replace(/[^A-Z0-9]/g,"");}
        function updateConfirmation(){
            if(serialConfirmation){
                if(serialConfirmation.value&&normalizeSerial(serial.value)!==normalizeSerial(serialConfirmation.value)){serialConfirmation.setCustomValidity("La confirmación no coincide con el serial.");}
                else{serialConfirmation.setCustomValidity("");}
            }
            if(plateConfirmation){
                var plateValue=normalizePlate(plate.value),confirmValue=normalizePlate(plateConfirmation.value);
                if(confirmValue&&plateValue!==confirmValue){plateConfirmation.setCustomValidity("La confirmación no coincide con la Placa RNEC.");}
                else{plateConfirmation.setCustomValidity("");}
            }
        }
        function setSelectOptions(select,choices,currentValue){
            if(!select){return;}
            var selected=String(currentValue==null?select.value:currentValue);
            select.innerHTML="";
            Object.keys(choices||{}).forEach(function(value){
                var option=document.createElement("option");option.value=value;option.textContent=choices[value];if(value===selected){option.selected=true;}select.appendChild(option);
            });
            if(selected&&!Array.from(select.options).some(function(option){return option.value===selected;})){select.value="";}
        }
        function populateTechnicalOptions(rule){
            var catalog=technicalCatalogs[category.value]||{};
            (rule&&Array.isArray(rule.technical)?rule.technical:[]).forEach(function(key){
                var wrapper=form.querySelector('[data-additional-field="'+key+'"]');
                var select=wrapper?wrapper.querySelector("select"):null;
                if(select){
                    // Conserva la opción enviada cuando el servidor devuelve el formulario con errores.
                    var initial=select.dataset.initialValue||select.value;
                    setSelectOptions(select,catalog[key]||{"":"Seleccione una opción"},initial);
                    delete select.dataset.initialValue;
                }
            });
        }
        function applyCategory(){
            var rule=rules[category.value]||null;
            var visible=rule&&Array.isArray(rule.visible)?rule.visible:[];
            var required=rule&&Array.isArray(rule.required)?rule.required:[];
            var technical=rule&&Array.isArray(rule.technical)?rule.technical:[];
            var technicalRequired=rule&&Array.isArray(rule.technical_required)?rule.technical_required:[];
            var technicalEnabled=!!technicalChoice.checked;
            populateTechnicalOptions(rule);
            form.querySelectorAll("[data-additional-field]").forEach(function(wrapper){
                var key=wrapper.dataset.additionalField,isTechnical=technical.indexOf(key)>=0;
                var show=key==="asset_category"||!!rule&&visible.indexOf(key)>=0&&(!isTechnical||technicalEnabled);
                wrapper.hidden=!show;
                wrapper.querySelectorAll("input,select,textarea").forEach(function(control){
                    if(!show){
                        control.required=false;control.disabled=true;control.setCustomValidity("");
                        if((previousCategory&&previousCategory!==category.value)||(isTechnical&&!technicalEnabled)){control.value="";}
                    }else{
                        control.disabled=false;
                        control.required=key==="asset_category"||required.indexOf(key)>=0||(technicalEnabled&&technicalRequired.indexOf(key)>=0);
                    }
                });
            });
            plate.required=!!rule;if(plateConfirmation){plateConfirmation.required=false;}if(technicalPanel){technicalPanel.hidden=!rule||!technicalEnabled;}
            if(guide){
                if(rule){var message=technicalEnabled?"Complete las opciones técnicas que correspondan.":"Puede continuar con la información básica; las características técnicas permanecen ocultas.";guide.innerHTML='<div><strong>'+esc(rule.label)+'</strong><span>'+esc(rule.description||"")+'</span></div><small>'+esc(message)+'</small>';guide.hidden=false;}
                else{guide.innerHTML='<div><strong>Seleccione una categoría</strong><span>SIVI mostrará únicamente la información necesaria para ese tipo de elemento.</span></div>';guide.hidden=false;}
            }
            if(summary){summary.textContent=rule?(technicalEnabled?"Información básica y técnica para "+rule.label+".":"Información básica para "+rule.label+"."):"Seleccione la categoría para mostrar sus opciones.";}
            form.dataset.categoryReady="1";previousCategory=category.value||"";loadBrands();updateConfirmation();updateSubmitState();
        }
        function fillOptions(list,values){if(!list)return;list.innerHTML="";(values||[]).forEach(function(value){var option=document.createElement("option");option.value=value;list.appendChild(option);});}
        function boundedCacheSet(cache,key,value,maxEntries){
            cache.set(key,{storedAt:Date.now(),value:value});
            while(cache.size>maxEntries){
                cache.delete(cache.keys().next().value);
            }
        }
        function catalogCacheKey(params){
            return Object.keys(params).sort().map(function(key){
                return key+"="+String(params[key]||"").trim().toUpperCase();
            }).join("&");
        }
        function fetchCatalog(params){
            if(!form.dataset.catalogUrl||!category.value){
                return Promise.resolve({ok:true,manufacturers:[],models:[]});
            }
            var key=catalogCacheKey(params);
            var cached=catalogResultCache.get(key);
            if(cached&&Date.now()-cached.storedAt<catalogCacheTtlMs){
                return Promise.resolve(cached.value);
            }
            return fetch(
                form.dataset.catalogUrl+"&"+new URLSearchParams(params).toString(),
                {
                    credentials:"same-origin",
                    cache:"no-store",
                    headers:{"X-Requested-With":"XMLHttpRequest"}
                }
            )
                .then(function(response){
                    if(!response.ok)throw new Error("HTTP "+response.status);
                    return response.json();
                })
                .then(function(data){
                    if(data&&data.ok){
                        boundedCacheSet(
                            catalogResultCache,
                            key,
                            data,
                            catalogCacheMaxEntries
                        );
                    }
                    return data;
                });
        }
        function loadBrands(){
            if(!category.value){fillOptions(brandOptions,[]);fillOptions(modelOptions,[]);return;}
            fetchCatalog({asset_category:category.value}).then(function(data){if(data.ok)fillOptions(brandOptions,data.manufacturers||[]);}).catch(function(error){if(error.name!=="AbortError")fillOptions(brandOptions,[]);});
            if(manufacturer&&manufacturer.value.trim())loadModels();else fillOptions(modelOptions,[]);
        }
        function loadModels(){
            if(!category.value||!manufacturer||!manufacturer.value.trim()){fillOptions(modelOptions,[]);return;}
            fetchCatalog({asset_category:category.value,manufacturer:manufacturer.value.trim()}).then(function(data){if(data.ok)fillOptions(modelOptions,data.models||[]);}).catch(function(error){if(error.name!=="AbortError")fillOptions(modelOptions,[]);});
        }
        function normalizeIdentityValue(value){
            return String(value||"").trim().toUpperCase().replace(/[^A-Z0-9]/g,"");
        }
        function identityCacheKey(serialValue,plateValue){
            return normalizeIdentityValue(serialValue)
                +"|"+normalizeIdentityValue(plateValue)
                +"|"+String(category.value||"otro");
        }
        function renderIdentityResult(data){
            if(!data||!data.ok){renderError();return;}
            if(data.has_conflicts){renderConflicts(data);}
            else{renderAvailable();}
        }
        function checkIdentity(){
            var serialValue=serial.value.trim(),plateValue=plate.value.trim();
            updateConfirmation();

            if(!serialValue&&!plateValue){
                identityRequestSequence++;
                if(requestController){requestController.abort();}
                clearStatus();
                return;
            }

            if(plateValue&&!plateEntryComplete(plateValue)){
                identityRequestSequence++;
                if(requestController){requestController.abort();}
                status.hidden=false;
                status.innerHTML='<div class="alert alert-warning mb-0">La Placa RNEC debe iniciar con 000. Escriba los números de forma continua; SIVI agregará el guion automáticamente.</div>';
                setBlocked(false);
                return;
            }

            if(!plateValue&&normalizeIdentityValue(serialValue).length<4){
                identityRequestSequence++;
                if(requestController){requestController.abort();}
                status.hidden=false;
                status.innerHTML='<div class="alert alert-info mb-0">Digite al menos 4 caracteres del serial para iniciar la comprobación.</div>';
                setBlocked(false);
                return;
            }

            var cacheKey=identityCacheKey(serialValue,plateValue);
            var cached=identityResultCache.get(cacheKey);
            if(cached&&Date.now()-cached.storedAt<identityCacheTtlMs){
                identityRequestSequence++;
                if(requestController){requestController.abort();}
                renderIdentityResult(cached.value);
                return;
            }

            identityRequestSequence++;
            var requestSequence=identityRequestSequence;

            if(requestController){requestController.abort();}
            var controller=new AbortController();
            requestController=controller;
            renderChecking();

            var timeoutId=setTimeout(function(){
                if(requestSequence!==identityRequestSequence){return;}
                controller.abort();
                requestController=null;
                renderError();
            },identityTimeoutMs);

            var params=new URLSearchParams({
                serial_number:serialValue,
                placa_rnec:plateValue,
                asset_category:category.value||"otro"
            });

            fetch(
                form.dataset.identityCheckUrl+"&"+params.toString(),
                {
                    credentials:"same-origin",
                    cache:"no-store",
                    headers:{"X-Requested-With":"XMLHttpRequest"},
                    signal:controller.signal
                }
            )
                .then(function(response){
                    if(!response.ok){throw new Error("HTTP "+response.status);}
                    return response.json();
                })
                .then(function(data){
                    if(requestSequence!==identityRequestSequence){return;}
                    if(data&&data.ok){
                        boundedCacheSet(
                            identityResultCache,
                            cacheKey,
                            data,
                            identityCacheMaxEntries
                        );
                    }
                    renderIdentityResult(data);
                })
                .catch(function(error){
                    if(requestSequence!==identityRequestSequence){return;}
                    if(error.name!=="AbortError"){renderError();}
                })
                .finally(function(){
                    clearTimeout(timeoutId);
                    if(requestController===controller){requestController=null;}
                });
        }
        function schedule(){if(timer){clearTimeout(timer);}timer=setTimeout(checkIdentity,800);}
        serial.addEventListener("input",function(){updateConfirmation();updateSubmitState();schedule();});serial.addEventListener("blur",checkIdentity);
        if(serialConfirmation){serialConfirmation.addEventListener("input",updateConfirmation);}
        plate.addEventListener("input",function(){
            updateConfirmation();
            // No se ejecuta validación remota mientras la placa está incompleta.
            // Esto evita cualquier actualización visual que pueda interferir con la escritura.
            updateSubmitState();
            if(plateEntryComplete(plate.value)){schedule();}
            else if(timer){clearTimeout(timer);}
        });
        plate.addEventListener("blur",function(){if(plate.value.trim())checkIdentity();});
        if(plateConfirmation){plateConfirmation.addEventListener("input",updateConfirmation);}
        category.addEventListener("change",function(){technicalChoice.checked=false;applyCategory();checkIdentity();});
        technicalChoice.addEventListener("change",applyCategory);
        ownership.addEventListener("change",function(){applyCategory();updateSubmitState();});
        if(equipmentState){equipmentState.addEventListener("change",updateSubmitState);}
        if(manufacturer){manufacturer.addEventListener("change",loadModels);manufacturer.addEventListener("blur",loadModels);}
        status.addEventListener("click",function(event){
            var trigger=event.target.closest("[data-additional-conflict-open]");
            if(!trigger){return;}
            event.preventDefault();
            showConflictModal(status.innerHTML);
        });
        form.addEventListener("input",updateSubmitState);
        form.addEventListener("change",updateSubmitState);
        form.addEventListener("sivi:sede-selected",updateSubmitState);
        form.addEventListener("submit",function(event){
            updateConfirmation();
            updateSubmitState();
            if(blocked){event.preventDefault();status.hidden=false;status.scrollIntoView({behavior:"smooth",block:"center"});return;}
            if(!form.checkValidity()){event.preventDefault();form.reportValidity();var invalid=form.querySelector(":invalid");if(invalid){invalid.focus();invalid.scrollIntoView({behavior:"smooth",block:"center"});}return;}
            submit.disabled=true;submit.textContent="Registrando…";
        });
        setBlocked(blocked);applyCategory();
        if(conflictModalElement&&conflictModalElement.dataset.autoShow==="1"&&conflictModalBody&&conflictModalBody.innerHTML.trim()!==""){
            setBlocked(true);
            showConflictModal(conflictModalBody.innerHTML);
        }else if((serial.value.trim()||plate.value.trim())&&!blocked){
            schedule();
        }
    });

    (function(){
        var search=document.getElementById("topbarGlobalSearch");
        document.addEventListener("keydown",function(event){
            if((event.ctrlKey||event.metaKey)&&String(event.key).toLowerCase()==="k"){
                if(search){event.preventDefault();search.focus();search.select();}
            }
        });
        document.querySelectorAll("[data-global-equipment-search]").forEach(function(form){
            form.addEventListener("submit",function(event){
                var input=form.querySelector("input[name=q]");
                if(!input||input.value.trim().length<2){event.preventDefault();input&&input.focus();input&&input.setCustomValidity("Escriba al menos dos caracteres para buscar.");input&&input.reportValidity();}
                else{input.setCustomValidity("");}
            });
        });
    })();
    (function(){
        var params=new URLSearchParams(window.location.search);
        if(params.get("focus")==="closure"){
            window.addEventListener("load",function(){var target=document.getElementById("siteClosure");if(target){target.scrollIntoView({behavior:"smooth",block:"start"});target.setAttribute("tabindex","-1");target.focus({preventScroll:true});}});
        }
    })();
    document.querySelectorAll("form[data-persist-filters]").forEach(function(form){
        var key="sivi.filters."+(form.dataset.persistFilters||document.body.dataset.page||"page");
        form.addEventListener("submit",function(){
            try{var values=new URLSearchParams(new FormData(form));localStorage.setItem(key,values.toString());}catch(e){}
        });
        var explicit=Array.from(new URLSearchParams(window.location.search).keys()).some(function(name){return !["page","campaign_id"].includes(name);});
        var saved="";try{saved=localStorage.getItem(key)||"";}catch(e){}
        if(!explicit&&saved){
            var restore=document.createElement("a");restore.className="btn btn-sm btn-outline-secondary restore-filter-button";restore.href=window.location.pathname+"?"+saved;restore.textContent="Restaurar últimos filtros";restore.title="Recupera los filtros usados en su última consulta";
            var actions=form.closest(".toolbar")||form.parentElement;actions&&actions.appendChild(restore);
        }
    });
    (function(){
        var page=document.body.dataset.page||"";
        if(!["equipos","novedades","correcciones","notificaciones","adicionales"].includes(page))return;
        var key="sivi.scroll."+page+window.location.search;
        window.addEventListener("beforeunload",function(){try{sessionStorage.setItem(key,String(window.scrollY));}catch(e){}});
        window.addEventListener("pageshow",function(event){if(!event.persisted)return;try{var value=Number(sessionStorage.getItem(key)||0);if(value>0)window.scrollTo(0,value);}catch(e){}});
    })();
    document.querySelectorAll("form").forEach(function(form){
        var summary=null;
        function ensureSummary(){
            if(summary)return summary;
            summary=document.createElement("div");summary.className="form-error-summary";summary.setAttribute("role","alert");summary.setAttribute("tabindex","-1");summary.hidden=true;
            form.prepend(summary);return summary;
        }
        form.addEventListener("invalid",function(event){
            event.preventDefault();var box=ensureSummary();var field=event.target;var label=field.labels&&field.labels.length?field.labels[0].textContent.trim():"Campo pendiente";box.innerHTML="<strong>Revise la información antes de continuar.</strong><span>"+label+": "+(field.validationMessage||"complete este campo")+"</span>";box.hidden=false;field.classList.add("is-invalid");field.scrollIntoView({behavior:"smooth",block:"center"});
        },true);
        // La propiedad validity.valid no dispara el evento invalid ni mueve el foco mientras el usuario escribe.
        form.addEventListener("input",function(event){if(event.target&&event.target.validity&&event.target.validity.valid)event.target.classList.remove("is-invalid");});
    });
    (function(){
        document.querySelectorAll("[data-mobile-scan-bridge]").forEach(function(bridge){
            var form=bridge.closest("form[data-mobile-scan-form]")||bridge.closest("form");
            if(!form)return;
            var startBtn=bridge.querySelector("[data-mobile-scan-start]");
            var sessionBox=bridge.querySelector("[data-mobile-scan-session]");
            var qr=bridge.querySelector("[data-mobile-scan-qr]");
            var code=bridge.querySelector("[data-mobile-scan-code]");
            var link=bridge.querySelector("[data-mobile-scan-link]");
            var copyBtn=bridge.querySelector("[data-mobile-scan-copy]");
            var shareBtn=bridge.querySelector("[data-mobile-scan-share]");
            var whatsapp=bridge.querySelector("[data-mobile-scan-whatsapp]");
            var renewBtn=bridge.querySelector("[data-mobile-scan-renew]");
            var stopBtn=bridge.querySelector("[data-mobile-scan-stop]");
            var status=bridge.querySelector("[data-mobile-scan-status]");
            var countdown=bridge.querySelector("[data-mobile-scan-countdown]");
            var csrf=form.querySelector('input[name="csrf"]');
            if(!startBtn||!sessionBox||!csrf)return;
            var token="",scannerUrl="",sequence=0,pollTimer=0,countdownTimer=0,expiresAt=0,stopping=false,pendingAck=0,ackTimer=0;
            function setStatus(message,tone){if(!status)return;status.textContent=message;status.dataset.tone=tone||"";}
            function notify(message){
                var toast=document.createElement("div");toast.className="mobile-scan-toast";toast.setAttribute("role","status");toast.textContent=message;document.body.appendChild(toast);
                requestAnimationFrame(function(){toast.classList.add("is-visible");});setTimeout(function(){toast.classList.remove("is-visible");setTimeout(function(){toast.remove();},250);},3200);
            }
            function formatCountdown(){
                if(!countdown||!expiresAt)return;var remaining=Math.max(0,Math.ceil((expiresAt-Date.now())/1000));var minutes=Math.floor(remaining/60),seconds=remaining%60;
                countdown.textContent=String(minutes).padStart(2,"0")+":"+String(seconds).padStart(2,"0");
                if(remaining<=0){setStatus("La conexión venció. Use Renovar o genere una nueva.","danger");clearTimeout(pollTimer);}
            }
            function targetInput(target){return form.querySelector('[data-mobile-scan-target="'+target+'"]');}
            function acknowledge(seq){
                if(!token||!seq)return;pendingAck=seq;clearTimeout(ackTimer);
                var body=new FormData();body.append("csrf",csrf.value);body.append("token",token);body.append("sequence",String(seq));
                fetch(bridge.dataset.ackUrl,{method:"POST",body:body,credentials:"same-origin",headers:{"X-Requested-With":"XMLHttpRequest"}})
                    .then(function(response){return response.json().then(function(data){return {response:response,data:data};});})
                    .then(function(result){if(!result.response.ok||!result.data.ok)throw new Error(result.data.message||"No fue posible confirmar la lectura.");pendingAck=0;setStatus("✓ Lectura aplicada y confirmada al celular.","success");})
                    .catch(function(){if(token&&pendingAck){setStatus("Dato aplicado. Reintentando confirmación al celular…","warning");ackTimer=setTimeout(function(){acknowledge(pendingAck);},1800);}});
            }
            function applyScan(data){
                var input=targetInput(data.target);if(!input){setStatus("El formulario no tiene disponible el campo recibido.","warning");return;}
                input.disabled=false;input.value=data.value||"";
                ["input","change","blur"].forEach(function(name){input.dispatchEvent(new Event(name,{bubbles:true}));});
                var label=data.target==="placa_rnec"?"Placa RNEC":"serial";
                setStatus("✓ "+label+" recibido desde el celular"+(data.format?" · "+data.format:""),"success");
                notify((label==="serial"?"Serial":"Placa RNEC")+" recibido: "+(data.value||""));
                input.classList.add("mobile-scan-received");setTimeout(function(){input.classList.remove("mobile-scan-received");},1800);
                acknowledge(Number(data.sequence||0));
            }
            function schedulePoll(delay){clearTimeout(pollTimer);if(!token)return;pollTimer=setTimeout(poll,delay||1200);}
            function poll(){
                if(!token)return;var url=bridge.dataset.pollUrl+"&"+new URLSearchParams({token:token,after:String(sequence)}).toString();
                fetch(url,{credentials:"same-origin",cache:"no-store",headers:{"X-Requested-With":"XMLHttpRequest"}})
                    .then(function(response){return response.json().then(function(data){return {response:response,data:data};});})
                    .then(function(result){
                        var data=result.data;if(!result.response.ok||!data.ok)throw new Error(data.message||"No fue posible consultar la conexión.");
                        if(data.expired||data.status!=="active"){setStatus("La conexión móvil finalizó. Puede renovarla o generar una nueva.","warning");return;}
                        if(data.expires_at){var parsed=Date.parse(String(data.expires_at).replace(" ","T")+"-05:00");if(!isNaN(parsed))expiresAt=parsed;}
                        if(data.has_scan&&Number(data.sequence)>sequence){sequence=Number(data.sequence);applyScan(data);}
                        else if(data.mobile_connected){setStatus("Celular conectado. Esperando lectura…","active");}
                        schedulePoll(1000);
                    })
                    .catch(function(){if(token){setStatus("Reconectando con el celular…","warning");schedulePoll(2500);}});
            }
            function renderSession(data){
                token=data.token||"";scannerUrl=data.scanner_url||"";sequence=0;pendingAck=0;expiresAt=Date.now()+Number(data.expires_in||600)*1000;
                sessionBox.hidden=false;startBtn.hidden=true;code.textContent=data.pairing_code||"------";link.href=scannerUrl;link.textContent=scannerUrl;
                if(qr){qr.hidden=false;qr.src=(data.qr_url||"")+(String(data.qr_url||"").includes("?")?"&":"?")+"_="+Date.now();qr.onerror=function(){qr.hidden=true;};}
                var shareText="Abra este enlace temporal de SIVI para escanear el serial o la Placa RNEC: "+scannerUrl+" Código de conexión: "+(data.pairing_code||"");
                whatsapp.href="https://wa.me/?text="+encodeURIComponent(shareText);
                if(navigator.share){shareBtn.hidden=false;shareBtn.onclick=function(){navigator.share({title:"Lector móvil SIVI",text:"Conexión temporal para capturar QR, barras o texto de etiquetas.",url:scannerUrl}).catch(function(){});};}
                setStatus("Esperando que el celular abra el enlace…","active");formatCountdown();clearInterval(countdownTimer);countdownTimer=setInterval(formatCountdown,1000);schedulePoll(700);
            }
            function start(){
                startBtn.disabled=true;startBtn.textContent="Generando conexión…";setStatus("Creando enlace seguro…","active");
                var body=new FormData();body.append("csrf",csrf.value);body.append("campaign_id",bridge.dataset.campaignId||"0");body.append("sede_id",bridge.dataset.sedeId||"0");
                fetch(bridge.dataset.startUrl,{method:"POST",body:body,credentials:"same-origin",headers:{"X-Requested-With":"XMLHttpRequest"}})
                    .then(function(response){return response.json().then(function(data){return {response:response,data:data};});})
                    .then(function(result){if(!result.response.ok||!result.data.ok)throw new Error(result.data.message||"No fue posible crear la conexión.");renderSession(result.data);})
                    .catch(function(error){setStatus(error.message||"No fue posible crear la conexión con el celular.","danger");})
                    .finally(function(){startBtn.disabled=false;startBtn.textContent="Conectar celular";});
            }
            function renew(){
                if(!token)return;renewBtn.disabled=true;renewBtn.textContent="Renovando…";
                var body=new FormData();body.append("csrf",csrf.value);body.append("token",token);
                fetch(bridge.dataset.renewUrl,{method:"POST",body:body,credentials:"same-origin",headers:{"X-Requested-With":"XMLHttpRequest"}})
                    .then(function(response){return response.json().then(function(data){return {response:response,data:data};});})
                    .then(function(result){if(!result.response.ok||!result.data.ok)throw new Error(result.data.message||"No fue posible renovar.");expiresAt=Date.now()+Number(result.data.expires_in||600)*1000;var minutes=Math.max(1,Math.round(Number(result.data.expires_in||600)/60));setStatus("Conexión renovada por "+minutes+" minutos.","success");renewBtn.textContent="Renovar "+minutes+" minutos";schedulePoll(200);})
                    .catch(function(error){setStatus(error.message||"No fue posible renovar la conexión.","danger");})
                    .finally(function(){renewBtn.disabled=false;renewBtn.textContent="Renovar "+String(bridge.dataset.sessionMinutes||10)+" minutos";});
            }
            function disconnect(notifyServer){
                clearTimeout(pollTimer);clearTimeout(ackTimer);clearInterval(countdownTimer);var current=token;token="";sequence=0;pendingAck=0;expiresAt=0;sessionBox.hidden=true;startBtn.hidden=false;
                if(notifyServer!==false&&current&&!stopping){stopping=true;var body=new FormData();body.append("csrf",csrf.value);body.append("token",current);fetch(bridge.dataset.stopUrl,{method:"POST",body:body,credentials:"same-origin",keepalive:true}).catch(function(){}).finally(function(){stopping=false;});}
            }
            startBtn.addEventListener("click",start);
            renewBtn&&renewBtn.addEventListener("click",renew);
            stopBtn&&stopBtn.addEventListener("click",function(){disconnect(true);setStatus("Celular desconectado.","");});
            copyBtn&&copyBtn.addEventListener("click",function(){
                if(!scannerUrl)return;var done=function(){copyBtn.textContent="Enlace copiado";setTimeout(function(){copyBtn.textContent="Copiar enlace";},1800);};
                if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(scannerUrl).then(done).catch(function(){});}else{var area=document.createElement("textarea");area.value=scannerUrl;area.style.position="fixed";area.style.opacity="0";document.body.appendChild(area);area.select();try{document.execCommand("copy");done();}catch(e){}area.remove();}
            });
            window.addEventListener("pagehide",function(){if(token){var body=new FormData();body.append("csrf",csrf.value);body.append("token",token);navigator.sendBeacon&&navigator.sendBeacon(bridge.dataset.stopUrl,body);}});
        });
    })();

    /**
     * NÚMERO DE CONTACTO
     *
     * Mantiene una digitación continua, elimina caracteres que no sean números
     * y limita el valor a 10 posiciones. La validación final exige que el número
     * comience por 60 para fijo o por 3 para celular.
     */
    (function(){
        var selector="input[data-contact-phone]";
        var message="Digite 10 números. Fijo: inicia por 60. Celular: inicia por 3.";

        function sanitize(input){
            var digits=String(input.value||"").replace(/[^0-9]/g,"").slice(0,10);
            if(input.value!==digits){input.value=digits;}
            return digits;
        }

        function validate(input,showState){
            var value=String(input.value||"");
            var valid=value===""||/^(?:60[0-9]{8}|3[0-9]{9})$/.test(value);
            input.setCustomValidity(valid?"":message);
            if(showState){
                input.classList.toggle("is-invalid",!valid);
                input.classList.toggle("is-valid",valid&&value!=="");
            }
            return valid;
        }

        document.querySelectorAll(selector).forEach(function(input){
            sanitize(input);
            validate(input,false);
        });

        document.addEventListener("input",function(event){
            var input=event.target;
            if(!(input instanceof HTMLInputElement)||!input.matches(selector)){return;}
            sanitize(input);
            validate(input,false);
            input.classList.remove("is-invalid","is-valid");
        });

        document.addEventListener("blur",function(event){
            var input=event.target;
            if(!(input instanceof HTMLInputElement)||!input.matches(selector)){return;}
            validate(input,true);
        },true);

        document.addEventListener("invalid",function(event){
            var input=event.target;
            if(!(input instanceof HTMLInputElement)||!input.matches(selector)){return;}
            validate(input,true);
        },true);
    })();

    (function(){
        if(document.body.dataset.pwaInstallEnabled!=="1")return;
        var buttons=Array.from(document.querySelectorAll("[data-pwa-install]"));
        var modalElement=document.getElementById("pwaInstallModal");
        if(!buttons.length||!modalElement)return;
        var modal=window.bootstrap?bootstrap.Modal.getOrCreateInstance(modalElement):null;
        var androidInstructions=modalElement.querySelector("[data-pwa-android-instructions]");
        var iosInstructions=modalElement.querySelector("[data-pwa-ios-instructions]");
        var genericInstructions=modalElement.querySelector("[data-pwa-generic-instructions]");
        var confirmButton=modalElement.querySelector("[data-pwa-confirm-install]");
        var status=modalElement.querySelector("[data-pwa-status]");
        var deferredPrompt=null;
        var ua=navigator.userAgent||"";
        var isIOS=/iPad|iPhone|iPod/.test(ua)||(navigator.platform==="MacIntel"&&navigator.maxTouchPoints>1);
        var isStandalone=window.matchMedia("(display-mode: standalone)").matches||window.navigator.standalone===true;
        function showButtons(){buttons.forEach(function(button){button.hidden=isStandalone;});}
        function resetInstructions(){[androidInstructions,iosInstructions,genericInstructions].forEach(function(el){if(el)el.hidden=true;});if(status)status.textContent="";}
        function openInstall(){
            resetInstructions();
            if(deferredPrompt&&androidInstructions){androidInstructions.hidden=false;}
            else if(isIOS&&iosInstructions){iosInstructions.hidden=false;}
            else if(genericInstructions){genericInstructions.hidden=false;}
            if(modal)modal.show();
        }
        buttons.forEach(function(button){button.addEventListener("click",openInstall);});
        window.addEventListener("beforeinstallprompt",function(event){event.preventDefault();deferredPrompt=event;showButtons();});
        if(confirmButton){confirmButton.addEventListener("click",function(){
            if(!deferredPrompt){if(status)status.textContent="Use el menú del navegador para instalar SIVI.";return;}
            confirmButton.disabled=true;
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then(function(choice){
                if(status)status.textContent=choice.outcome==="accepted"?"Instalación aceptada.":"Instalación cancelada.";
                deferredPrompt=null;confirmButton.disabled=false;
            }).catch(function(){confirmButton.disabled=false;});
        });}
        window.addEventListener("appinstalled",function(){isStandalone=true;buttons.forEach(function(button){button.hidden=true;});if(modal)modal.hide();});
        showButtons();
    })();
    if("serviceWorker" in navigator){window.addEventListener("load",function(){navigator.serviceWorker.register("sw.js?v=1.0.0.0",{updateViaCache:"none"}).then(function(registration){registration.update().catch(function(){});}).catch(function(){});});}
