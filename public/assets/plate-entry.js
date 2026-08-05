/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: public/assets/plate-entry.js
 * Propósito: Controla la escritura continua de la Placa RNEC, agrega el guion y valida el prefijo obligatorio 000 sin perder el foco.
 * Los bloques críticos se comentan para facilitar soporte y mantenimiento.
 */
(() => {
    'use strict';

    const REQUIRED_PREFIX = '000';
    const selectors = [
        'input[name="placa_reported"]',
        'input[name="placa_rnec"]',
        'input[name="placa_confirmation"]',
        'input[data-placa-rnec]',
        'input[data-sivi-plate-input]'
    ].join(',');

    // Obtiene la cantidad total de caracteres configurada por el administrador, incluido el guion.
    function expectedTotalCharacters(input) {
        const configured = Number.parseInt(input.dataset.plateTotalCharacters || '9', 10);
        return Number.isFinite(configured) && configured >= 5 ? configured : 9;
    }

    // Calcula cuántos números debe contener la placa descontando el guion.
    function expectedDigitCount(input) {
        return expectedTotalCharacters(input) - 1;
    }

    // Elimina espacios, guiones y cualquier carácter que no sea numérico.
    function digitsOnly(value) {
        return String(value || '').replace(/\D+/g, '');
    }

    // Permite la captura parcial, pero exige que los tres primeros números formen el prefijo 000.
    function hasValidPrefix(digits) {
        return digits.length < REQUIRED_PREFIX.length || digits.startsWith(REQUIRED_PREFIX);
    }

    // Inserta el guion después de 000 únicamente cuando el prefijo es válido.
    function formatDigits(digits, input) {
        const limited = digits.slice(0, expectedDigitCount(input));
        if (limited.length < REQUIRED_PREFIX.length) return limited;
        if (!limited.startsWith(REQUIRED_PREFIX)) return limited;
        return REQUIRED_PREFIX + '-' + limited.slice(REQUIRED_PREFIX.length);
    }

    // Reubica el cursor considerando el guion agregado automáticamente para evitar pérdida de foco.
    function caretFromDigitPosition(digitPosition, formattedValue) {
        if (formattedValue.startsWith(REQUIRED_PREFIX + '-') && digitPosition >= REQUIRED_PREFIX.length) {
            return Math.min(formattedValue.length, digitPosition + 1);
        }
        return Math.min(formattedValue.length, digitPosition);
    }

    // Crea o reutiliza el mensaje accesible que explica un prefijo inválido.
    function messageElement(input) {
        const id = input.id ? input.id + '-prefix-message' : '';
        if (id) {
            const existing = document.getElementById(id);
            if (existing) return existing;
        }

        const message = document.createElement('small');
        if (id) message.id = id;
        message.className = 'sivi-plate-prefix-message';
        message.setAttribute('role', 'alert');
        message.hidden = true;
        input.insertAdjacentElement('afterend', message);
        return message;
    }

    // Actualiza el estado visual y la validación del navegador sin reemplazar el campo.
    function updatePrefixValidation(input, digits) {
        const invalid = digits.length >= REQUIRED_PREFIX.length && !digits.startsWith(REQUIRED_PREFIX);
        const message = messageElement(input);
        if (invalid) {
            const text = 'La Placa RNEC debe iniciar con 000 antes del guion.';
            input.setCustomValidity(text);
            input.classList.add('sivi-field-invalid');
            input.setAttribute('aria-invalid', 'true');
            message.textContent = text;
            message.hidden = false;
            return false;
        }

        input.setCustomValidity('');
        input.classList.remove('sivi-field-invalid');
        input.removeAttribute('aria-invalid');
        message.textContent = '';
        message.hidden = true;
        return true;
    }

    // Formatea el valor durante la escritura y conserva la posición lógica del cursor.
    function applyFormat(input) {
        const currentValue = input.value;
        const currentCaret = input.selectionStart == null ? currentValue.length : input.selectionStart;
        const digitsBeforeCaret = digitsOnly(currentValue.slice(0, currentCaret)).length;
        const digits = digitsOnly(currentValue);
        const formatted = formatDigits(digits, input);

        if (formatted !== currentValue) {
            input.value = formatted;
            const nextCaret = caretFromDigitPosition(digitsBeforeCaret, formatted);
            try {
                input.setSelectionRange(nextCaret, nextCaret);
            } catch (_) {
                // Algunos navegadores no permiten cambiar la selección en estados especiales.
            }
        }

        updatePrefixValidation(input, digitsOnly(input.value));
    }

    // Configura una sola vez cada campo de Placa RNEC y registra sus eventos de escritura.
    function prepare(input) {
        if (!(input instanceof HTMLInputElement)) return;
        if (input.dataset.siviAutoPlateEntry === 'true') return;

        input.dataset.siviAutoPlateEntry = 'true';
        input.removeAttribute('data-sivi-plain-plate-entry');
        input.type = 'text';
        input.inputMode = 'numeric';
        input.autocomplete = 'off';
        input.removeAttribute('pattern');
        input.removeAttribute('maxlength');
        input.removeAttribute('minlength');
        input.setAttribute(
            'title',
            'Escriba la placa completa. Debe iniciar con 000 y SIVI agregará automáticamente el guion.'
        );

        input.addEventListener('compositionstart', () => {
            input.dataset.siviComposing = 'true';
        });
        input.addEventListener('compositionend', () => {
            delete input.dataset.siviComposing;
            applyFormat(input);
        });
        input.addEventListener('input', () => {
            if (input.dataset.siviComposing === 'true') return;
            applyFormat(input);
        });
        input.addEventListener('blur', () => {
            applyFormat(input);
        });
        input.addEventListener('keydown', event => {
            if (
                event.key === 'Backspace' &&
                input.selectionStart === input.selectionEnd &&
                input.selectionStart === 4 &&
                input.value.startsWith(REQUIRED_PREFIX + '-')
            ) {
                event.preventDefault();
                input.value = '00' + input.value.slice(4);
                try {
                    input.setSelectionRange(2, 2);
                } catch (_) {}
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });

        applyFormat(input);
    }

    // Aplica la configuración a campos existentes y a los agregados dinámicamente.
    function prepareAll(root = document) {
        if (root.matches?.(selectors)) prepare(root);
        root.querySelectorAll?.(selectors).forEach(prepare);
    }

    document.addEventListener('DOMContentLoaded', () => {
        prepareAll(document);

        const observer = new MutationObserver(records => {
            records.forEach(record => {
                record.addedNodes.forEach(node => {
                    if (node instanceof HTMLElement) prepareAll(node);
                });
            });
        });
        observer.observe(document.body, { childList: true, subtree: true });
    });
})();
