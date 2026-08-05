/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: public/assets/sivi-onboarding.js
 * Propósito: Implementa el recorrido guiado para orientar al usuario durante sus primeras actividades.
 * Los bloques críticos se comentan para facilitar soporte y mantenimiento.
 */
(() => {
    'use strict';

    const currentScript = document.currentScript;
    const scriptUrl = new URL(currentScript?.src || 'assets/sivi-onboarding.js', window.location.href);
    const apiUrl = new URL('../onboarding-status.php', scriptUrl).toString();
    const fallbackKey = 'sivi:first-login-tour:v1';
    const state = { step: 0, csrf: '', role: '', authenticated: false, target: null };

    const steps = [
        {
            kicker: 'Bienvenido a SIVI',
            title: 'Conozca el propósito del aplicativo',
            text: 'SIVI permite confirmar la información de la sede, validar físicamente los equipos, registrar novedades y mantener actualizado el inventario de la RNEC.',
            bullets: ['La información se diligencia por etapas.', 'Los campos obligatorios faltantes se marcan claramente.', 'Los cambios quedan asociados al usuario y a la sede.'],
            flow: ['1. Confirmar sede', '2. Validar equipos', '3. Registrar novedades', '4. Finalizar']
        },
        {
            kicker: 'Paso 1',
            title: 'Confirme primero la información de la sede',
            text: 'Revise la sede antes de iniciar la validación de equipos. Los datos del Registrador o Responsable no se prediligencian: deben ser confirmados y escritos por el usuario.',
            bullets: ['Complete únicamente información verificada.', 'Los campos pendientes aparecerán con borde rojo.', 'La aplicación lo llevará al primer campo obligatorio faltante.'],
            selectors: ['[href*="sede"]', '[data-tour="site"]', '[href*="confirmar"]']
        },
        {
            kicker: 'Paso 2',
            title: 'Valide cada equipo',
            text: 'Compare la información registrada con el equipo físico y confirme serial, Placa RNEC, estado y ubicación.',
            bullets: ['La placa se escribe o pega completa en un solo campo.', 'Puede ingresarla con guion, por ejemplo 000-12345, o escribir todos los números continuos.', 'Use “Usar esta placa” cuando la sugerencia corresponda al equipo revisado.'],
            selectors: ['[href*="validar"]', '[href*="inventario"]', '[data-tour="inventory"]']
        },
        {
            kicker: 'Paso 3',
            title: 'Registre equipos adicionales y novedades',
            text: 'Cuando encuentre un equipo no relacionado, regístrelo como adicional. Cuando exista una inconsistencia, utilice el flujo de novedades correspondiente.',
            bullets: ['No duplique seriales ni placas.', 'Seleccione la categoría y el estado correctos.', 'Adjunte evidencia cuando el flujo lo solicite.'],
            selectors: ['[href*="adicional"]', '[href*="novedad"]', '[data-tour="additional-equipment"]']
        },
        {
            kicker: 'Paso 4',
            title: 'Revise pendientes antes de finalizar',
            text: 'Antes de cerrar la validación, verifique que no existan equipos o campos pendientes. La finalización confirma que la información fue revisada.',
            bullets: ['Revise el resumen de avance.', 'Corrija los campos señalados.', 'Finalice solo cuando la sede esté completamente validada.'],
            selectors: ['[href*="seguimiento"]', '[href*="finalizar"]', '[data-tour="progress"]']
        },
        {
            kicker: 'Ayuda disponible',
            title: 'Puede volver a consultar esta guía',
            text: 'Después de completar el recorrido encontrará el botón “Guía de SIVI” en la parte inferior de la pantalla para verlo nuevamente.',
            bullets: ['La guía se muestra automáticamente una sola vez por usuario.', 'Cada perfil verá únicamente las opciones que tenga autorizadas.', 'Los datos guardados no se modifican por realizar el recorrido.']
        }
    ];

    function localCompleted() {
        try { return localStorage.getItem(fallbackKey) === 'done'; } catch (_) { return false; }
    }
    function setLocalCompleted() {
        try { localStorage.setItem(fallbackKey, 'done'); } catch (_) { /* sin almacenamiento local */ }
    }

    async function requestStatus() {
        try {
            const response = await fetch(apiUrl, { credentials: 'same-origin', cache: 'no-store' });
            if (response.status === 401) return null;
            if (!response.ok) throw new Error('status');
            return await response.json();
        } catch (_) {
            return { ok: true, enabled: true, authenticated: true, should_show: !localCompleted(), csrf: '', role: '' };
        }
    }

    async function save(action, step = state.step) {
        if (!state.authenticated) return;
        if (!state.csrf) {
            if (action === 'complete' || action === 'skip') setLocalCompleted();
            return;
        }
        try {
            await fetch(apiUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': state.csrf },
                body: JSON.stringify({ action, step })
            });
        } catch (_) {
            if (action === 'complete' || action === 'skip') setLocalCompleted();
        }
    }

    function removeTarget() {
        state.target?.classList.remove('sivi-tour-target');
        state.target = null;
    }

    function highlight(step) {
        removeTarget();
        if (!Array.isArray(step.selectors)) return;
        for (const selector of step.selectors) {
            const candidate = document.querySelector(selector);
            if (candidate && candidate.offsetParent !== null) {
                state.target = candidate;
                candidate.classList.add('sivi-tour-target');
                candidate.scrollIntoView({ behavior: 'smooth', block: 'center' });
                break;
            }
        }
    }

    function closeTour() {
        removeTarget();
        document.querySelector('.sivi-tour-overlay')?.remove();
        document.body.classList.remove('sivi-tour-lock');
        document.querySelector('.sivi-tour-launcher')?.focus({ preventScroll: true });
    }

    function render() {
        const step = steps[state.step];
        const overlay = document.querySelector('.sivi-tour-overlay');
        if (!overlay) return;

        overlay.querySelector('.sivi-tour-kicker').textContent = step.kicker;
        overlay.querySelector('.sivi-tour-title').textContent = step.title;
        overlay.querySelector('.sivi-tour-text').textContent = step.text;

        const list = overlay.querySelector('.sivi-tour-list');
        list.replaceChildren(...(step.bullets || []).map(item => {
            const li = document.createElement('li');
            li.textContent = item;
            return li;
        }));
        list.hidden = !step.bullets?.length;

        const flow = overlay.querySelector('.sivi-tour-flow');
        flow.replaceChildren(...(step.flow || []).map(item => {
            const span = document.createElement('span');
            span.textContent = item;
            return span;
        }));
        flow.hidden = !step.flow?.length;

        overlay.querySelector('.sivi-tour-counter').textContent = `${state.step + 1} de ${steps.length}`;
        overlay.querySelector('.sivi-tour-progress > span').style.width = `${((state.step + 1) / steps.length) * 100}%`;
        overlay.querySelector('[data-tour-prev]').hidden = state.step === 0;
        const next = overlay.querySelector('[data-tour-next]');
        next.textContent = state.step === steps.length - 1 ? 'Finalizar recorrido' : 'Siguiente';
        highlight(step);
        next.focus({ preventScroll: true });
        void save('progress', state.step);
    }

    function startTour(manual = false) {
        if (document.querySelector('.sivi-tour-overlay')) return;
        state.step = 0;
        const overlay = document.createElement('div');
        overlay.className = 'sivi-tour-overlay';
        overlay.setAttribute('role', 'presentation');
        overlay.innerHTML = `
            <section class="sivi-tour-dialog" role="dialog" aria-modal="true" aria-labelledby="sivi-tour-title">
                <header class="sivi-tour-header">
                    <p class="sivi-tour-kicker"></p>
                    <h2 class="sivi-tour-title" id="sivi-tour-title"></h2>
                </header>
                <div class="sivi-tour-body">
                    <p class="sivi-tour-text"></p>
                    <ul class="sivi-tour-list"></ul>
                    <div class="sivi-tour-flow"></div>
                </div>
                <footer class="sivi-tour-footer">
                    <div class="sivi-tour-progress" aria-hidden="true"><span></span></div>
                    <span class="sivi-tour-counter"></span>
                    <div class="sivi-tour-actions">
                        <button type="button" class="sivi-tour-button sivi-tour-button-link" data-tour-skip>Omitir recorrido</button>
                        <button type="button" class="sivi-tour-button sivi-tour-button-secondary" data-tour-prev>Anterior</button>
                        <button type="button" class="sivi-tour-button sivi-tour-button-primary" data-tour-next>Siguiente</button>
                    </div>
                </footer>
            </section>`;
        document.body.appendChild(overlay);
        document.body.classList.add('sivi-tour-lock');

        overlay.querySelector('[data-tour-prev]').addEventListener('click', () => {
            state.step = Math.max(0, state.step - 1);
            render();
        });
        overlay.querySelector('[data-tour-next]').addEventListener('click', async () => {
            if (state.step < steps.length - 1) {
                state.step += 1;
                render();
                return;
            }
            await save('complete', state.step);
            setLocalCompleted();
            closeTour();
        });
        overlay.querySelector('[data-tour-skip]').addEventListener('click', async () => {
            await save('skip', state.step);
            setLocalCompleted();
            closeTour();
        });
        overlay.addEventListener('keydown', event => {
            if (event.key === 'ArrowRight') overlay.querySelector('[data-tour-next]')?.click();
            if (event.key === 'ArrowLeft' && state.step > 0) overlay.querySelector('[data-tour-prev]')?.click();
        });
        void save('start', 0);
        render();
    }

    function addLauncher() {
        if (document.querySelector('.sivi-tour-launcher')) return;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'sivi-tour-launcher';
        button.textContent = '❔ Guía de SIVI';
        button.setAttribute('aria-label', 'Abrir nuevamente el recorrido guiado de SIVI');
        button.addEventListener('click', () => startTour(true));
        document.body.appendChild(button);
    }

    document.addEventListener('DOMContentLoaded', async () => {
        const status = await requestStatus();
        if (!status || status.enabled === false || status.authenticated === false) return;
        state.authenticated = true;
        state.csrf = typeof status.csrf === 'string' ? status.csrf : '';
        state.role = typeof status.role === 'string' ? status.role : '';
        addLauncher();
        if (status.should_show === true) {
            window.setTimeout(() => startTour(false), 500);
        }
    });
})();
